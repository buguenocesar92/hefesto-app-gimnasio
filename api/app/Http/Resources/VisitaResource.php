<?php

declare(strict_types=1);

namespace App\Http\Resources;

/**
 * Una visita, como sale a la interfaz.
 *
 * `dia_local` viene de la vista `vw_visitas`, que ya aplicó la regla del día
 * de la ENTRADA en la zona horaria del gimnasio. No se recalcula acá: si se
 * recalculara en PHP con la zona del servidor, un socio que entra a las 23:50
 * aparecería en el día equivocado, que es el error clásico de estos sistemas.
 */
class VisitaResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($peticion): array
    {
        return [
            'id' => $this->id,
            'socio' => $this->whenLoaded('socio', fn (): array => [
                'id' => $this->socio->id,
                'nombre' => trim($this->socio->nombre . ' ' . ($this->socio->apellido ?? '')),
            ]),
            'entrada_en' => $this->entrada_en?->toIso8601String(),
            'salida_en' => $this->salida_en?->toIso8601String(),
            'abierta' => $this->salida_en === null,
            'duracion_minutos' => $this->duracionMinutos(),
            'canal' => $this->canal->value,
            'dispositivo' => $this->dispositivo,
            'motivo_manual' => $this->motivo_manual,
            'cerrada_por_sistema' => $this->cerrada_por_sistema,
            'anulada' => $this->anulada,
            'cruza_medianoche' => $this->cruzaMedianoche(),
        ];
    }
}