<?php

declare(strict_types=1);

namespace App\Services\Asistencia;

use App\Enums\CanalAcceso;
use App\Enums\TipoEventoAcceso;
use App\Models\EventoAcceso;
use App\Models\Socio;
use App\Models\Visita;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Registro de entradas y salidas.
 *
 * TODA LA ASISTENCIA PASA POR ACÁ
 * -------------------------------
 * No hay un segundo camino para registrar una visita. Si el turno escribe
 * directo en la base y recepción usa este servicio, las dos cosas divergen en
 * el primer corte de energía.
 *
 * LA REGLA DE UNA VISITA ABIERTA
 * -----------------------------
 * Un socio no puede tener dos visitas simultáneas abiertas. El servicio la
 * comprueba Y la base la impone:
 *
 *  - la comprobación da un error claro ("este socio tiene una visita abierta
 *    desde las 19:12"), que es lo que la persona de recepción necesita leer;
 *  - el índice único parcial `visitas_una_abierta_por_socio` garantiza que
 *    dos peticiones simultáneas no puedan meter las dos entradas.
 *
 * La comprobación sola NO alcanzaría: dos personas picando entrada a la vez
 * leen "no hay visita abierta" en el mismo instante y las dos escriben. Para
 * ese caso está el índice. La experiencia sin el índice NO existiría.
 */
class RegistroVisita
{
    /**
     * Registra una entrada.
     *
     * @throws RuntimeException si el socio ya tiene una visita abierta.
     */
    public function entrar(
        Socio $socio,
        CanalAcceso $canal,
        ?string $registradoPor = null,
        ?Carbon $momento = null,
        ?string $dispositivo = null,
        ?string $motivoManual = null
    ): Visita {
        $momento = $momento ?? now();

        $this->exigirJustificacion($canal, $registradoPor, $motivoManual);

        $visitaAbierta = $socio->visitasAbiertas()->first();

        if ($visitaAbierta !== null) {
            throw new RuntimeException(sprintf(
                'El socio ya tiene una visita abierta desde las %s. Registre la salida antes de una nueva entrada.',
                $visitaAbierta->entrada_en?->format('H:i') ?? 'hora desconocida'
            ));
        }

        try {
            $visita = Visita::create([
                'socio_id' => $socio->id,
                'entrada_en' => $momento,
                'salida_en' => null,
                'canal' => $canal,
                'dispositivo' => $dispositivo,
                'registrado_por' => $registradoPor,
                'motivo_manual' => $motivoManual,
            ]);
        } catch (QueryException $error) {
            // El índice único parcial rechazó una entrada duplicada: dos
            // personas registrando a la vez. El mensaje es el mismo que el
            // de arriba a propósito, porque quien lo ve necesita saber qué
            // hacer, no qué excepción lanzó el gestor de base de datos.
            if ($this->esViolacionDeVisitaAbierta($error)) {
                throw new RuntimeException(
                    'El socio ya tiene una visita abierta. Registre la salida antes de una nueva entrada.',
                    previous: $error
                );
            }

            throw $error;
        }

        $this->agregarEvento($visita, TipoEventoAcceso::Entrada, $canal, $registradoPor, $motivoManual, $momento);

        return $visita;
    }

    /**
     * Registra la salida de una visita.
     *
     * @throws RuntimeException si la visita ya estaba cerrada o anulada.
     */
    public function salir(
        Visita $visita,
        CanalAcceso $canal = CanalAcceso::Dispositivo,
        ?string $registradoPor = null,
        ?Carbon $momento = null,
        ?string $motivo = null
    ): Visita {
        $momento = $momento ?? now();

        $this->exigirJustificacion($canal, $registradoPor, $motivo);

        if ($visita->anulada) {
            throw new RuntimeException('Esta visita está anulada: no se le puede registrar una salida.');
        }

        if ($visita->salida_en !== null) {
            throw new RuntimeException('Esta visita ya tiene salida registrada.');
        }

        if ($momento->lessThan($visita->entrada_en)) {
            throw new RuntimeException(
                'La salida no puede ser anterior a la entrada. Revise la hora del dispositivo.'
            );
        }

        $visita->forceFill([
            'salida_en' => $momento,
            'cerrada_por_sistema' => false,
        ])->save();

        $this->agregarEvento($visita, TipoEventoAcceso::Salida, $canal, $registradoPor, $motivo, $momento);

        return $visita;
    }

    /**
     * Cierra una visita que quedó abierta sin que nadie registrara la salida.
     *
     * Esto NO es un afterthought: los cortes de energía y los turnos que se
     * caen dejan entradas sin salida todos los meses. Se cierra con
     * `cerrada_por_sistema = true` y queda un evento que dice que la cerró el
     * sistema, para que recepción vea la diferencia entre "el socio salió a
     * las 21:30" y "el socio salió a las 21:30 según el sistema, porque nadie
     * estaba en la casilla cuando pasó".
     */
    public function cerrarPorSistema(Visita $visita, ?Carbon $momento = null, ?string $observacion = null): Visita
    {
        $momento = $momento ?? now();

        if ($visita->salida_en !== null) {
            return $visita;
        }

        $visita->forceFill([
            'salida_en' => $momento,
            'cerrada_por_sistema' => true,
        ])->save();

        $this->agregarEvento(
            $visita,
            TipoEventoAcceso::CierrePorSistema,
            CanalAcceso::Dispositivo,
            null,
            $observacion ?? 'Nadie registró la salida antes de que el sistema la cerrara.',
            $momento
        );

        return $visita;
    }

    /**
     * Anula una visita mal registrada.
     *
     * No borra nada: marca la fila, le pone la salida si no la tenía (para
     * liberar el índice de visita abierta) y deja el evento de anulación con
     * el motivo. La bitácora tiene que poder contar la historia completa.
     */
    public function anular(Visita $visita, string $motivo, ?string $registradoPor = null): Visita
    {
        $cambios = [
            'anulada' => true,
        ];

        if ($visita->salida_en === null) {
            // Se le pone salida para que el índice único parcial no la cuente
            // como abierta: una visita anulada no bloquea la entrada del
            // socio.
            $cambios['salida_en'] = $visita->entrada_en->copy()->addSecond();
        }

        $visita->forceFill($cambios)->save();

        $this->agregarEvento(
            $visita,
            TipoEventoAcceso::Anulacion,
            $visita->canal,
            $registradoPor,
            $motivo,
            now()
        );

        return $visita;
    }

    /**
     * Un acceso manual siempre tiene que quedar marcado como manual.
     *
     * Los cortes de energía y los fallos del torno son la razón de que este
     * canal exista. Si se pudiera registrar un acceso manual sin motivo, en
     * seis meses no habría forma de distinguir un corte de luz de un socio
     * que se coló, y el registro de asistencia valdría menos que el papel.
     */
    private function exigirJustificacion(
        CanalAcceso $canal,
        ?string $registradoPor,
        ?string $motivo
    ): void {
        if (! $canal->exigeResponsable()) {
            return;
        }

        if ($registradoPor === null || $registradoPor === '') {
            throw new RuntimeException(
                'Un acceso manual tiene que registrar quién lo anotó.'
            );
        }

        if ($motivo === null || trim($motivo) === '') {
            throw new RuntimeException(
                'Un acceso manual tiene que tener un motivo: por ejemplo, corte de energía o fallo del turno.'
            );
        }
    }

    private function agregarEvento(
        Visita $visita,
        TipoEventoAcceso $tipo,
        CanalAcceso $canal,
        ?string $registradoPor,
        ?string $motivo,
        Carbon $ocurridoEn
    ): EventoAcceso {
        return EventoAcceso::create([
            'visita_id' => $visita->id,
            'socio_id' => $visita->socio_id,
            'tipo' => $tipo,
            'canal' => $canal,
            'registrado_por' => $registradoPor,
            'motivo' => $motivo,
            'ocurrido_en' => $ocurridoEn,
        ]);
    }

    /**
     * ¿La excepción es del índice de visita abierta?
     *
     * PostgreSQL no entrega un código de error específico para índices únicos
     * parciales, así que se busca el nombre del índice en el mensaje. Es
     * una heurística y queda anotada como tal: la garantía real no depende
     * de ella, porque el índice rechaza la fila igual, lo que cambia es el
     * mensaje que ve la persona de recepción.
     */
    private function esViolacionDeVisitaAbierta(QueryException $error): bool
    {
        return str_contains($error->getMessage(), 'visitas_una_abierta_por_socio');
    }
}