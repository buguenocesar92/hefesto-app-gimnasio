<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Socios\ActualizarSocioRequest;
use App\Http\Requests\Socios\StoreSocioRequest;
use App\Http\Resources\SocioResource;
use App\Models\Socio;
use App\Services\Membresias\CalculadoraEstadoMembresia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Socios: la ficha de la persona.
 */
class SocioController extends Controller
{
    public function __construct(
        private readonly CalculadoraEstadoMembresia $calculadora
    ) {}

    public function indice(Request $peticion): AnonymousResourceCollection
    {
        $this->authorize('verIndice', Socio::class);

        $socios = Socio::query()
            ->with(['membresias' => function ($consulta): void {
                $consulta->orderByDesc('iniciada_en')->limit(1);
            }])
            ->when($peticion->string('buscar')->isNotEmpty(), function ($consulta) use ($peticion): void {
                $buscar = '%' . trim((string) $peticion->input('buscar')) . '%';
                $consulta->where(function ($interna) use ($buscar): void {
                    $interna->where('nombre', 'ilike', $buscar)
                        ->orWhere('apellido', 'ilike', $buscar)
                        ->orWhere('documento', $buscar);
                });
            })
            ->when($peticion->boolean('solo_activos'), function ($consulta): void {
                $consulta->where('activo', true);
            })
            // El orden por defecto es por apellido, que es como se busca a
            // alguien en recepción: por nombre de pila nadie lo encuentra.
            ->orderBy('apellido')
            ->orderBy('nombre')
            ->paginate(min((int) $peticion->input('por_pagina', 25), 100));

        return SocioResource::collection($socios);
    }

    public function mostrar(Socio $socio): JsonResponse
    {
        $this->authorize('ver', $socio);

        $socio->load(['membresias.plan', 'visitas' => function ($consulta): void {
            $consulta->orderByDesc('entrada_en')->limit(20);
        }, 'rutinas']);

        return response()->json([
            'socio' => new SocioResource($socio),
            // Las dos señales juntas, siempre. Ver
            // CalculadoraEstadoMembresia: por qué se muestran las dos.
            'estado' => $this->calculadora->resumen($socio),
        ]);
    }

    public function guardar(StoreSocioRequest $peticion): JsonResponse
    {
        $socio = Socio::create($peticion->validated());

        return response()->json([
            'socio' => new SocioResource($socio),
        ], 201);
    }

    public function actualizar(ActualizarSocioRequest $peticion, Socio $socio): JsonResponse
    {
        $this->authorize('actualizar', $socio);

        $socio->update($peticion->validated());

        return response()->json([
            'socio' => new SocioResource($socio->fresh()),
        ]);
    }
}