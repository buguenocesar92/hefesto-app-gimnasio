<?php

declare(strict_types=1);

namespace App\Services\Membresias;

use App\Enums\EstadoMembresia;
use App\Enums\MotivoBloqueoIngreso;
use App\Enums\MotivoPeriodoSinCobro;
use App\Models\Membresia;
use App\Models\PeriodoSinCobro;
use App\Models\Socio;
use Illuminate\Support\Carbon;

/**
 * Estado de una membresía calculado a partir de los periodos que la cubren.
 *
 * LA FECHA DE PAGO IMPORTA MÁS DE LO QUE PARECE
 * --------------------------------------------
 * El socio deja de asistir antes de dejar de pagar, o al revés, según el
 * caso, y no hay forma de saber de antemano cuál de las dos señales aparece
 * primero. Lo que sí se puede hacer es mostrar las dos, siempre juntas:
 *
 *  - ¿cuándo fue la última visita? (asistencia)
 *  - ¿cuándo fue el último pago y hasta cuándo da derecho? (cobranza)
 *
 * Un sistema que muestra solo una de las dos miente por omisión: la que
 * falta es la que más cuesta recuperar.
 *
 * POR QUÉ ESTO ES UNA CLASE Y NO UN CAMPO
 * ---------------------------------------
 * El derecho del socio a hoy no es un dato: es el resultado de encadenar
 * cobros y periodos sin cobro. Con un campo `al_dia` guardado, el día que
 * el dueño carga el mes gratis de diciembre, alguien tiene que acordarse de
 * ponerlo en `true`, y ese alguien no existe.
 *
 * Con esta clase, cargar el mes gratis basta: el estado se recalcula solo.
 */
class CalculadoraEstadoMembresia
{
    /**
     * Motivo por el que el socio no puede entrar hoy.
     *
     * Devuelve `Ninguno` cuando puede. El orden importa: primero lo que el
     * dueño decidió (suspensión, terminada) y después lo que la caja dice
     * (período vencido), porque si está suspendido da lo mismo que no tenga
     * el mes pagado.
     */
    public function motivoDeBloqueo(Membresia $membresia, ?Carbon $fecha = null): MotivoBloqueoIngreso
    {
        $referencia = $fecha ?? Carbon::today();

        if ($membresia->estado === EstadoMembresia::Terminada) {
            return MotivoBloqueoIngreso::Terminada;
        }

        if ($membresia->estado === EstadoMembresia::Suspendida) {
            return $suspensionVigente($membresia, $referencia)
                ? MotivoBloqueoIngreso::Suspendida
                : MotivoBloqueoIngreso::Ninguno;
        }

        if ($membresia->estado === EstadoMembresia::Vencida) {
            return $membresia->cubreHoy($referencia->toDateString())
                ? MotivoBloqueoIngreso::Ninguno
                : MotivoBloqueoIngreso::PeriodoVencido;
        }

        return $membresia->cubreHoy($referencia->toDateString())
            ? MotivoBloqueoIngreso::Ninguno
            : MotivoBloqueoIngreso::PeriodoVencido;
    }

    /**
     * Fin del derecho vigente, o null si ya se venció.
     *
     * Si el socio tiene el mes gratis de diciembre (2026-12-01 a 2027-01-01)
     * y el cobro de enero empieza el 2027-01-01, el derecho sigue vigente de
     * forma continua: el mes gratis no "come" días ni se pierde el cobro.
     */
    public function finDeCobertura(Membresia $membresia, ?Carbon $fecha = null): ?string
    {
        return $membresia->finDeCoberturaVigente(
            ($fecha ?? Carbon::today())->toDateString()
        );
    }

    /**
     * Los dos hechos que el dueño necesita ver juntos, en una respuesta.
     *
     * @return array<string, mixed>
     */
    public function resumen(Socio $socio, ?Carbon $fecha = null): array
    {
        $membresia = $socio->membresias()->first();
        $referencia = $fecha ?? Carbon::today();

        return [
            'socio_id' => $socio->id,
            'ultima_visita_en' => $socio->ultimaVisita()?->entrada_en?->toIso8601String(),
            'dias_sin_venir' => $socio->diasSinVisitar($referencia->toDateString()),
            'visitas_totales' => $socio->visitas()->count(),
            'membresia_id' => $membresia?->id,
            'plan' => $membresia?->plan?->nombre,
            'estado_membresia' => $membresia?->estado?->value,
            'ultimo_pago' => $membresia?->fechaUltimoPago(),
            'cubre_hoy' => $membresia?->cubreHoy($referencia->toDateString()) ?? false,
            'motivo_bloqueo' => $membresia === null
                ? MotivoBloqueoIngreso::SinMembresia->value
                : $this->motivoDeBloqueo($membresia, $referencia)->value,
        ];
    }

    /**
     * Registra un periodo sin cobro.
     *
     * Valida dos cosas que la base no puede validar sola: que el periodo
     * tenga sentido (desde antes que hasta) y que no se solape con otro
     * periodo sin cobro de la misma membresía.
     */
    public function crearPeriodoSinCobro(
        Membresia $membresia,
        Carbon $desde,
        Carbon $hasta,
        MotivoPeriodoSinCobro $motivo,
        ?string $nota = null
    ): PeriodoSinCobro {
        if ($desde->greaterThanOrEqualTo($hasta)) {
            throw new \InvalidArgumentException(
                'El periodo sin cobro empieza el ' . $desde->toDateString()
                . ' y termina el ' . $hasta->toDateString()
                . '. Tiene que terminar después de empezar.'
            );
        }

        $seSolapa = PeriodoSinCobro::query()
            ->where('membresia_id', $membresia->id)
            ->where('desde', '<', $hasta->toDateString())
            ->where('hasta', '>', $desde->toDateString())
            ->exists();

        if ($seSolapa) {
            throw new \InvalidArgumentException(
                'Ese periodo se cruza con otro periodo sin cobro de la misma membresía.'
            );
        }

        return PeriodoSinCobro::create([
            'membresia_id' => $membresia->id,
            'desde' => $desde->toDateString(),
            'hasta' => $hasta->toDateString(),
            'motivo' => $motivo,
            'nota' => $nota,
        ]);
    }

    private function suspensionVigente(Membresia $membresia, Carbon $referencia): bool
    {
        if ($membresia->suspendida_desde === null) {
            return false;
        }

        return $membresia->suspension_hasta === null
            || $referencia->lessThan($membresia->suspension_hasta);
    }
}