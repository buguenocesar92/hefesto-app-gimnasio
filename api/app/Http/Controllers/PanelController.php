<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Asistencia\ContadoresAsistencia;
use App\Services\Retencion\DetectorDeInactividad;
use App\Models\AvisoAbandono;
use App\Policies\AdministrativoPolicy;
use Illuminate\Http\JsonResponse;

/**
 * El panel: la pantalla por la que el dueño entra todas las mañanas.
 *
 * ORDEN DE LA PANTALLA
 * -------------------
 * Arriba, los números de hoy. Abajo, la lista de socios que dejaron de
 * venir. Ese orden no es decorativo: la pregunta del dueño es "¿a quién
 * llamo hoy?", y la respuesta está en la segunda tabla, no en la primera.
 */
class PanelController extends Controller
{
    public function __construct(
        private readonly ContadoresAsistencia $contadores,
        private readonly DetectorDeInactividad $detector
    ) {}

    public function indice(): JsonResponse
    {
        // El panel no tiene un modelo propio del que deducir la Policy, así
        // que hay que nombrarla. `AdministrativoPolicy` es la que responde
        // si esta persona puede ver la lista de socios en riesgo.
        $this->authorize('verPanel', AdministrativoPolicy::class);

        $avisos = AvisoAbandono::query()
            ->whereNull('cerrado_en')
            ->with('socio:id,nombre,apellido,documento,tenant_id')
            ->orderByDesc('dias_sin_venir')
            ->limit(50)
            ->get();

        return response()->json([
            'hoy' => now()->toDateString(),
            'contadores' => $this->contadores->leer(),
            'serie_30_dias' => $this->contadores->serieDiaria((string) auth()->user()->tenant_id),
            'en_riesgo' => $avisos->map(fn (AvisoAbandono $aviso): array => [
                'aviso_id' => $aviso->id,
                'socio_id' => $aviso->socio_id,
                'nombre' => trim(($aviso->socio?->nombre ?? '') . ' ' . ($aviso->socio?->apellido ?? '')),
                'dias_sin_venir' => $aviso->dias_sin_venir,
                'ultima_visita_en' => $aviso->ultima_visita_en?->toIso8601String(),
                'ultimo_pago' => $aviso->ultimo_pago,
                'pago_al_dia' => $aviso->pago_al_dia,
                'observacion' => $aviso->observacion,
            ])->all(),
            'nunca_vinieron' => $this->detector->sociosQueNuncaVinieron(),
        ]);
    }
}