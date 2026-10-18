<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Policies\AdministrativoPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Planes y precios.
 *
 * Solo el dueño crea y cambia planes. Recepción los lee para saber qué
 * membresía se está vendiendo, pero no cambia precios: un precio mal
 * cargado afecta el cobro de todo el mes siguiente.
 */
class PlanController extends Controller
{
    public function indice(Request $peticion): JsonResponse
    {
        $this->authorize('verPlanes', AdministrativoPolicy::class);

        return response()->json([
            'planes' => Plan::query()->orderBy('nombre')->get()->map(fn (Plan $plan): array => [
                'id' => $plan->id,
                'nombre' => $plan->nombre,
                'descripcion' => $plan->descripcion,
                'precio_clp' => $plan->precio_clp,
                'dias_periodo' => $plan->dias_periodo,
                'visitas_maximas_periodo' => $plan->visitas_maximas_periodo,
                'activo' => $plan->activo,
            ])->all(),
        ]);
    }

    public function guardar(Request $peticion): JsonResponse
    {
        $this->authorize('crearPlanes', AdministrativoPolicy::class);

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'precio_clp' => ['required', 'integer', 'min:0'],
            'dias_periodo' => ['nullable', 'integer', 'min:1', 'max:365'],
            'visitas_maximas_periodo' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $plan = Plan::create($datos);

        return response()->json(['plan_id' => $plan->id], 201);
    }
}