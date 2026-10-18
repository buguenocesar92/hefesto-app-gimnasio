<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Rutinas\RegistrarSerieRequest;
use App\Http\Requests\Rutinas\StoreRutinaRequest;
use App\Models\Rutina;
use App\Models\Socio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rutinas y seguimiento.
 *
 * NO ES UN GIMNASIO DE ALTO RENDIMIENTO, Y POR ESO NO SE COMPLICA
 * --------------------------------------------------------------
 * No hay planes de periodización, ni RPE, ni curvas de fuerza, ni
 * reservas de máquinas. El valor de este módulo es que el socio vea su
 * progreso: la misma rutina con más kilos el segundo mes, o las tres
 * visitas de esta semana comparadas con las dos de la anterior.
 *
 * Y no hay datos de salud. Un gimnasio no es una clínica. Ver docs/07.
 */
class RutinaController extends Controller
{
    public function indice(Socio $socio): JsonResponse
    {
        $this->authorize('verIndice', Rutina::class);

        $rutinas = $socio->rutinas()
            ->with('registros')
            ->get()
            ->map(fn (Rutina $rutina): array => [
                'id' => $rutina->id,
                'nombre' => $rutina->nombre,
                'vigente_desde' => $rutina->vigente_desde?->toDateString(),
                'vigente_hasta' => $rutina->vigente_hasta?->toDateString(),
                'activa' => $rutina->activa,
                'registros' => $rutina->registros->map(fn ($registro): array => [
                    'fecha' => $registro->fecha->toDateString(),
                    'ejercicio' => $registro->ejercicio,
                    'serie' => $registro->serie,
                    'repeticiones' => $registro->repeticiones,
                    'peso_kg' => (float) $registro->peso_kg,
                ])->all(),
            ]);

        return response()->json(['rutinas' => $rutinas]);
    }

    public function crear(StoreRutinaRequest $peticion, Socio $socio): JsonResponse
    {
        $this->authorize('crear', Rutina::class);

        $rutina = Rutina::create([
            'socio_id' => $socio->id,
            'coach_id' => $peticion->user()->rol->esPersonal() ? $peticion->user()->id : null,
            'nombre' => $peticion->input('nombre'),
            'descripcion' => $peticion->input('descripcion'),
            'vigente_desde' => $peticion->date('vigente_desde') ?? today()->toDateString(),
            'activa' => true,
        ]);

        return response()->json(['rutina_id' => $rutina->id], 201);
    }

    public function registrarSerie(RegistrarSerieRequest $peticion, Rutina $rutina): JsonResponse
    {
        $this->authorize('registrarSerie', $rutina);

        $registro = $rutina->registros()->create([
            'socio_id' => $rutina->socio_id,
            'ejercicio' => $peticion->input('ejercicio'),
            'fecha' => $peticion->date('fecha') ?? today()->toDateString(),
            'serie' => (int) $peticion->input('serie', 1),
            'repeticiones' => (int) $peticion->input('repeticiones'),
            'peso_kg' => $peticion->input('peso_kg'),
            'registrado_por' => $peticion->user()->id,
        ]);

        return response()->json([
            'registro_id' => $registro->id,
            'peso_kg' => (float) $registro->peso_kg,
        ], 201);
    }

    /**
     * El progreso por ejercicio: la mejor marca y la última marca.
     *
     * Es la razón de que este módulo exista. La respuesta se calcula en SQL
     * sobre el índice (tenant_id, socio_id, ejercicio, fecha), no trayendo
     * todos los registros a memoria.
     */
    public function progreso(Rutina $rutina): JsonResponse
    {
        $this->authorize('ver', $rutina);

        $filas = \Illuminate\Support\Facades\DB::select(
            'SELECT ejercicio,
                    max(peso_kg) AS mejor_marca,
                    (array_agg(peso_kg ORDER BY fecha DESC, creado_en DESC))[1] AS ultima_marca,
                    max(fecha) AS ultima_fecha,
                    count(*)::integer AS registros
               FROM public.registros_rutina
              WHERE rutina_id = ?
              GROUP BY ejercicio
              ORDER BY ejercicio',
            [$rutina->id]
        );

        return response()->json([
            'rutina_id' => $rutina->id,
            'progreso' => array_map(static fn (object $fila): array => [
                'ejercicio' => $fila->ejercicio,
                'mejor_marca_kg' => $fila->mejor_marca === null ? null : (float) $fila->mejor_marca,
                'ultima_marca_kg' => $fila->ultima_marca === null ? null : (float) $fila->ultima_marca,
                'ultima_fecha' => $fila->ultima_fecha,
                'registros' => (int) $fila->registros,
            ], $filas),
        ]);
    }
}