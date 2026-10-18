<?php

declare(strict_types=1);

namespace App\Http\Resources;

/**
 * Ficha del socio, como sale a la interfaz.
 *
 * QUÉ NO VIAJA
 * -----------
 * - El documento no sale por defecto. Aparece solo si quien consulta tiene
 *   permiso de recepción (`incluir_documento`), porque el número de documento
 *   es un dato personal que no toda persona del gimnasio necesita.
 * - No hay NADA de salud. No existe ese dato en la base, así que tampoco
 *   puede filtrarse desde acá. Ver docs/07.
 *
 * El permiso se decide con `$this->request->user()` dentro del recurso,
 * para que forgetting de agregarlo en un endpoint nuevo no expona el dato.
 */
class SocioResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($peticion): array
    {
        $usuario = $peticion->user();
        $puedeVerDocumento = $usuario !== null && $usuario->puedeGestionarSocios();

        return [
            'id' => $this->id,
            'nombre' => trim($this->nombre . ' ' . ($this->apellido ?? '')),
            'nombre_solo' => $this->nombre,
            'apellido' => $this->apellido,
            'documento' => $puedeVerDocumento ? $this->documento : null,
            'nacimiento' => $this->nacimiento?->toDateString(),
            'correo' => $this->correo,
            'telefono' => $this->telefono,
            'activo' => $this->activo,
            'suspendida_desde' => $this->suspendida_desde?->toIso8601String(),
            'suspension_hasta' => $this->suspension_hasta?->toIso8601String(),
            'membresia' => $this->whenLoaded('membresias', function (): mixed {
                $membresia = $this->membresias->first();

                return $membresia === null ? null : [
                    'id' => $membresia->id,
                    'plan' => $membresia->plan?->nombre,
                    'estado' => $membresia->estado->value,
                    'iniciada_en' => $membresia->iniciada_en?->toDateString(),
                ];
            }),
            'ultima_visita' => $this->whenLoaded('visitas', function (): mixed {
                $visita = $this->visitas->first();

                return $visita === null ? null : [
                    'entrada_en' => $visita->entrada_en?->toIso8601String(),
                    'salida_en' => $visita->salida_en?->toIso8601String(),
                    'abierta' => $visita->estaAbierta(),
                ];
            }),
        ];
    }
}