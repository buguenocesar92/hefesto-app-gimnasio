<?php

declare(strict_types=1);

namespace App\Services\Retencion;

use App\Models\AvisoAbandono;
use App\Models\Membresia;
use App\Models\Socio;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Detector de abandono: el producto en sí.
 *
 * EL PROBLEMA QUE RESUELVE
 * -----------------------
 * La gente deja de venir y nadie se entera hasta que ya se fue. Si alguien no
 * viene en tres semanas, es la señal más temprana que existe, y casi ningún
 * gimnasio la detecta a tiempo porque nadie tiene un registro consolidado de
 * visitas. Cada pregunta al respecto termina en "¿y la vez pasada vino?".
 *
 * Este servicio deja de contestar esa pregunta: mira la última visita de cada
 * socio con membresía vigente y avisa de los que llevan demasiado tiempo sin
 * aparecer.
 *
 * LAS DOS SEÑALES JUNTAS, POR QUÉ
 * ------------------------------
 * La asistencia y el pago se muestran juntas porque no dicen lo mismo, y el
 * orden en que se rompe importa:
 *
 *  - deja de venir y SIGUE pagando: se está aburriendo o se cambió de lugar.
 *    Es recuperable y es el mejor momento para llamarlo.
 *  - sigue	viniendo y DEJÓ de pagar: no se va a quejar, se va a ir.
 *  - deja de venir y dejó de pagar: ya se fue.
 *
 * Avisar solo de la segunda perdería la primera, que es justo la que se
 * puede recuperar.
 *
 * NO HAY DATOS DE SALUD EN ESTO
 * -----------------------------
 * Un socio puede dejar de venir por una lesión. El sistema no se entera, no
 * lo pregunta y no lo anota: ve que no vino, y nada más. Si el dueño quiere
 * llamar al socio, que lo llame. Ver docs/07.
 */
class DetectorDeInactividad
{
    /**
     * Genera los avisos que corresponde, sin guardar nada.
     *
     * Se separa de `guardar()` para poder probarlo sin escribir en la base.
     *
     * @return Collection<int, AvisoAbandono>
     */
    public function detectar(?int $diasMinimos = null): Collection
    {
        $umbral = $diasMinimos ?? $this->umbralDelGimnasio();
        $hoy = today();
        $avisos = new Collection();

        $socios = Socio::query()
            ->where('activo', true)
            ->where(function ($consulta): void {
                // Un socio suspendido no es un riesgo de fuga: ya avisó que
                // no viene. Contarlo sería ruido.
                $consulta->whereNull('suspendida_desde')
                    ->orWhere('suspension_hasta', '<', now());
            })
            ->with(['membresias' => function ($consulta): void {
                $consulta->whereIn('estado', ['activa', 'vencida', 'suspendida'])
                    ->orderByDesc('iniciada_en')
                    ->limit(1);
            }])
            ->get();

        foreach ($socios as $socio) {
            $membresia = $socio->membresias->first();

            if ($membresia === null) {
                continue;
            }

            $ultimaVisita = $socio->visitas()
                ->orderByDesc('entrada_en')
                ->first();

            if ($ultimaVisita === null) {
                // Nunca vino. La señal es distinta: no es que dejó de venir,
                // es que no arrancó. El dueño la ve aparte en el panel, y no
                // se mezcla con los que dejaron de venir.
                continue;
            }

            $dias = (int) $ultimaVisita->entrada_en->startOfDay()->diffInDays($hoy->startOfDay(), false);

            if ($dias < $umbral) {
                continue;
            }

            // Un aviso abierto por socio como máximo (índice parcial). Si ya
            // hay uno, se actualiza la cuenta en vez de apilar veinte avisos.
            $aviso = AvisoAbandono::query()
                ->where('socio_id', $socio->id)
                ->whereNull('cerrado_en')
                ->first();

            $aviso ??= new AvisoAbandono([
                'socio_id' => $socio->id,
                'detectado_en' => now(),
            ]);

            $aviso->fill([
                'dias_sin_venir' => $dias,
                'ultima_visita_en' => $ultimaVisita->entrada_en,
                'ultimo_pago' => $membresia->fechaUltimoPago(),
                'pago_al_dia' => $membresia->cubreHoy(),
                'observacion' => $this->descripcion($socio, $membresia, $dias),
            ]);

            $avisos->push($aviso);
        }

        return $avisos;
    }

    /**
     * Detecta y guarda.
     *
     * @return Collection<int, AvisoAbandono>
     */
    public function registrar(?int $diasMinimos = null): Collection
    {
        return $this->detectar($diasMinimos)->each(function (AvisoAbandono $aviso): void {
            $aviso->save();

            // El socio volvió: se cierra el aviso anterior. Si no se cierra,
            // el panel seguiría mostrando como riesgo de fuga a alguien que
            // está entrenando tres veces por semana.
            AvisoAbandono::query()
                ->where('socio_id', $aviso->socio_id)
                ->whereNull('cerrado_en')
                ->where('id', '!=', $aviso->id)
                ->update(['cerrado_en' => now()]);
        });
    }

    /**
     * Cierra los avisos de los socios que volvieron.
     *
     * Se llama al registrar una visita, que es el momento en que se sabe que
     * el socio está de vuelta.
     */
    public function cerrarAvisosDe(Socio $socio): void
    {
        AvisoAbandono::query()
            ->where('socio_id', $socio->id)
            ->whereNull('cerrado_en')
            ->update(['cerrado_en' => now()]);
    }

    private function umbralDelGimnasio(): int
    {
        $gimnasio = Tenant::find(app(\App\Services\Tenancy\ContextoGimnasio::class)->gimnasioIdEnConexion());

        return $gimnasio?->diasSinVengarComoRiesgo() ?? 21;
    }

    private function descripcion(Socio $socio, Membresia $membresia, int $dias): string
    {
        $pago = $membresia->cubreHoy()
            ? 'La membresía está al día.'
            : 'La membresía está vencida.';

        return sprintf(
            'Sin venir hace %d días. %s %s',
            $dias,
            $pago,
            $socio->diasSinVisitar() === null ? 'Nunca había venido.' : ''
        );
    }

    /**
     * Socios que todavía no han venido nunca.
     *
     * Va aparte del detector de abandono a propósito: son dos problemas
     * distintos con dos respuestas distintas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sociosQueNuncaVinieron(): array
    {
        return DB::select(
            'SELECT s.id, s.nombre, s.apellido, m.iniciada_en
               FROM public.socios s
               JOIN public.membresias m ON m.socio_id = s.id
              WHERE s.activo = true
                AND NOT EXISTS (
                    SELECT 1 FROM public.visitas v WHERE v.socio_id = s.id
                )
              ORDER BY m.iniciada_en DESC
              LIMIT 50'
        );
    }
}