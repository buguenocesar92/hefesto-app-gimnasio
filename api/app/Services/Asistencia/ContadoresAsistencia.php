<?php

declare(strict_types=1);

namespace App\Services\Asistencia;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Conteos de asistencia, calculados en memoria.
 *
 * POR QUÉ EN REDIS Y NO EN LA BASE
 * -------------------------------
 * El panel se abre varias veces al día y cada apertura pinta un gráfico con
 * visitas por día, socios que vienen a diario y socios en riesgo de fuga. Con
 * los índices correctos, PostgreSQL contesta esas consultas en milisegundos:
 * el problema no es la base, es que se repeten las mismas preguntas con los
 * mismos datos decenas de veces por hora.
 *
 * Por eso los conteos viven en Redis con una clave POR GIMNASIO, y se
 * recalculan una vez al cierre del día y después de cada cambio relevante.
 *
 * LA CLAUDE LLEVA EL GIMNASIO DENTRO
 * ---------------------------------
 * Una clave de cache sin gimnasio adentro es una fuga esperando: si la clave
 * fuera `contadores:visitas:hoy`, dos gimnasios en la misma instalación
 * leerían el mismo número. Por eso `clave()` mete el identificador del
 * gimnasio en todas partes.
 *
 * CACHEAR NO ES VERDAD ABSOLUTA
 * -----------------------------
 * Después de registrar una entrada, el número del día cambia. Por eso
 * `invalidar()` existe y se llama en cada registro: el caché no muestra el
 * pasado, muestra lo que se sabe. Si mañana se necesita tiempo real al
 * segundo, la respuesta es una consulta directa, no un caché que miente.
 */
class ContadoresAsistencia
{
    public const MINUTOS_POR_DEFECTO = 10;

    public function clave(string $gimnasioId, string $sufijo): string
    {
        return "gimnasio:{$gimnasioId}:contadores:{$sufijo}";
    }

    /**
     * Recalcula y guarda todos los contadores del gimnasio.
     *
     * @return array<string, int>
     */
    public function recalcular(?string $gimnasioId = null, ?string $fecha = null): array
    {
        $gimnasioId ??= app(\App\Services\Tenancy\ContextoGimnasio::class)->gimnasioIdEnConexion();

        if ($gimnasioId === null) {
            // Sin contexto no se calcula nada: se devolvería el conteo de
            // todos los gimnasios, que es justamente lo que no hay que
            // mostrar. Falla cerrado.
            return [];
        }

        $fecha ??= today()->toDateString();
        $hoy = DB::selectOne(
            'SELECT dia_local, count(*)::integer AS total
               FROM public.vw_visitas
              WHERE dia_local = ?
              GROUP BY dia_local',
            [$fecha]
        );

        $mes = DB::selectOne(
            'SELECT count(*)::integer AS total
               FROM public.vw_visitas
              WHERE dia_local >= date_trunc(\'month\', ?::date)::date',
            [$fecha]
        );

        $ayer = now()->subDay()->toDateString();
        $visitasAyer = DB::selectOne(
            'SELECT count(*)::integer AS total
               FROM public.vw_visitas
              WHERE dia_local = ?',
            [$ayer]
        );

        $abiertas = DB::selectOne(
            'SELECT count(*)::integer AS total
               FROM public.vw_visitas
              WHERE abierta = true'
        );

        $sociosActivos = DB::selectOne(
            "SELECT count(*)::integer AS total
               FROM public.socios
              WHERE activo = true"
        );

        $invitados = DB::selectOne(
            'SELECT count(*)::integer AS total
               FROM public.ingresos_invitados
              WHERE entrada_en::date = ?::date',
            [$fecha]
        );

        $datos = [
            'visitas_hoy' => (int) ($hoy->total ?? 0),
            'visitas_mes' => (int) ($mes->total ?? 0),
            'visitas_ayer' => (int) ($visitasAyer->total ?? 0),
            'visitas_abiertas' => (int) ($abiertas->total ?? 0),
            'socios_activos' => (int) ($sociosActivos->total ?? 0),
            'invitados_hoy' => (int) ($invitados->total ?? 0),
        ];

        Cache::put($this->clave($gimnasioId, 'hoy'), $datos, now()->addHours(6));

        return $datos;
    }

    /**
     * Lee los contadores guardados, y los calcula si todavía no existen.
     *
     * @return array<string, int>
     */
    public function leer(?string $gimnasioId = null): array
    {
        $gimnasioId ??= app(\App\Services\Tenancy\ContextoGimnasio::class)->gimnasioIdEnConexion();

        if ($gimnasioId === null) {
            return [];
        }

        $guardados = Cache::get($this->clave($gimnasioId, 'hoy'));

        if (is_array($guardados)) {
            return $guardados;
        }

        return $this->recalcular($gimnasioId);
    }

    /**
     * Serie diaria de visitas para el gráfico.
     *
     * Esta NO se cachea: son unos 30 números y la consulta usa el índice de
     * la vista. Cachear cada gráfico con una clave distinta llenaría Redis de
     * cosas que se piden una vez.
     *
     * @return array<string, int>
     */
    public function serieDiaria(string $gimnasioId, int $dias = 30): array
    {
        $filas = DB::select(
            'SELECT dia_local, count(*)::integer AS total
               FROM public.vw_visitas
              WHERE dia_local >= (?::date - (?::integer - 1))
              GROUP BY dia_local
              ORDER BY dia_local',
            [today()->toDateString(), $dias]
        );

        $serie = [];

        foreach ($filas as $fila) {
            $serie[(string) $fila->dia_local] = (int) $fila->total;
        }

        // Se rellenan los días sin visitas con cero. Un gráfico con huecos se
        // lee como si faltaran datos, cuando en realidad la respuesta es
        // "ese día no vino nadie".
        for ($i = $dias - 1; $i >= 0; $i--) {
            $dia = today()->subDays($i)->toDateString();
            $serie[$dia] ??= 0;
        }

        ksort($serie);

        return $serie;
    }

    /**
     * Se llama después de cada entrada, salida o anulación.
     *
     * Es lo que impide que el panel muestre el número de ayer con una
     * entrada más que ya se registró.
     */
    public function invalidar(?string $gimnasioId = null): void
    {
        $gimnasioId ??= app(\App\Services\Tenancy\ContextoGimnasio::class)->gimnasioIdEnConexion();

        if ($gimnasioId === null) {
            return;
        }

        Cache::forget($this->clave($gimnasioId, 'hoy'));
    }
}