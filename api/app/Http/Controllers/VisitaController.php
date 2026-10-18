<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CanalAcceso;
use App\Http\Requests\Visitas\CerrarVisitaRequest;
use App\Http\Requests\Visitas\RegistrarInvitadoRequest;
use App\Http\Requests\Visitas\RegistrarEntradaRequest;
use App\Http\Resources\VisitaResource;
use App\Models\EventoAcceso;
use App\Models\IngresoInvitado;
use App\Models\Socio;
use App\Models\Visita;
use App\Services\Asistencia\ContadoresAsistencia;
use App\Services\Asistencia\RegistroVisita;
use App\Services\Retencion\DetectorDeInactividad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Acceso y asistencia.
 *
 * LA ASISTENCIA ES UNA SERIE DE EVENTOS
 * -----------------------------------
 * Un socio puede venir tres veces en un día, y cada vez es una visita con su
 * hora de entrada y su hora de salida. Acá no hay un endpoint que diga
 * "el socio está adentro": hay entradas, hay salidas y hay una bitácora de
 * lo que pasó en cada una.
 *
 * UN INVITADO NO ES UN SOCIO
 * --------------------------
 * `registrarInvitado()` escribe en `ingresos_invitados`, NO en `visitas`.
 * No hay forma de que un invitado aparezca en un conteo de socios, porque la
 * tabla no participa de esos conteos. No es una condición que este código
 * respete: es que los datos no están en el mismo lado.
 */
class VisitaController extends Controller
{
    public function __construct(
        private readonly RegistroVisita $registro,
        private readonly ContadoresAsistencia $contadores,
        private readonly DetectorDeInactividad $detector
    ) {}

    public function indice(Request $peticion): AnonymousResourceCollection
    {
        $this->authorize('verIndice', Visita::class);

        $visitas = Visita::query()
            ->with('socio:id,nombre,apellido,documento,tenant_id')
            ->when($peticion->filled('socio_id'), function ($consulta) use ($peticion): void {
                $consulta->where('socio_id', $peticion->string('socio_id')->toString());
            })
            ->when($peticion->boolean('abiertas'), function ($consulta): void {
                $consulta->whereNull('salida_en')->where('anulada', false);
            })
            ->when($peticion->filled('desde'), function ($consulta) use ($peticion): void {
                $consulta->where('entrada_en', '>=', Carbon::parse((string) $peticion->input('desde'))->startOfDay());
            })
            ->orderByDesc('entrada_en')
            ->paginate(min((int) $peticion->input('por_pagina', 25), 100));

        return VisitaResource::collection($visitas);
    }

    public function entrar(RegistrarEntradaRequest $peticion): JsonResponse
    {
        $socio = Socio::findOrFail($peticion->string('socio_id')->toString());

        $this->authorize('entrar', $socio);

        try {
            $visita = $this->registro->entrar(
                $socio,
                CanalAcceso::from($peticion->input('canal', CanalAcceso::Dispositivo->value)),
                $peticion->user()->id,
                $peticion->fecha('momento'),
                $peticion->input('dispositivo'),
                $peticion->input('motivo_manual'),
            );
        } catch (RuntimeException $error) {
            // Un socio con visita abierta o sin motivo de acceso manual no es
            // un error del servidor: es una respuesta que la persona de
            // recepción tiene que poder leer y actuar. 422, no 500.
            throw new UnprocessableEntityHttpException($error->getMessage(), $error);
        }

        // El panel no puede seguir mostrando el número de ayer.
        $this->contadores->invalidar();

        return response()->json([
            'visita' => new VisitaResource($visita->load('socio')),
        ], 201);
    }

    public function mostrar(Visita $visita): JsonResponse
    {
        $this->authorize('ver', $visita);

        $visita->load(['socio', 'eventos.registradoPor']);

        return response()->json([
            'visita' => new VisitaResource($visita),
        ]);
    }

    public function salir(CerrarVisitaRequest $peticion, Visita $visita): JsonResponse
    {
        $this->authorize('salir', $visita);

        try {
            $this->registro->salir(
                $visita,
                CanalAcceso::from($peticion->input('canal', CanalAcceso::Dispositivo->value)),
                $peticion->user()->id,
                $peticion->fecha('momento'),
                $peticion->input('motivo'),
            );
        } catch (RuntimeException $error) {
            throw new UnprocessableEntityHttpException($error->getMessage(), $error);
        }

        $this->contadores->invalidar();

        // El socio volvió: si tenía un aviso de abandono abierto, este es el
        // momento exacto en que se cierra.
        $this->detector->cerrarAvisosDe($visita->socio);

        return response()->json([
            'visita' => new VisitaResource($visita->fresh()->load('socio')),
        ]);
    }

    /**
     * La bitácora de una visita.
     *
     * Es lo que contesta "¿por qué este socio tiene una visita de 14 horas?".
     */
    public function eventos(Visita $visita): JsonResponse
    {
        $this->authorize('ver', $visita);

        $eventos = $visita->eventos()
            ->with('registradoPor:id,nombre,apellido,tenant_id')
            ->get()
            ->map(fn (EventoAcceso $evento): array => [
                'id' => $evento->id,
                'tipo' => $evento->tipo->value,
                'canal' => $evento->canal->value,
                'ocurrido_en' => $evento->ocurrido_en->toIso8601String(),
                'registrado_por' => $evento->registradoPor?->nombre,
                'motivo' => $evento->motivo,
            ]);

        return response()->json(['eventos' => $eventos]);
    }

    /**
     * Ingreso de invitado.
     *
     * Va a su propia tabla. Un invitado no es un socio y no entra en ningún
     * conteo de asistencia de socios.
     */
    public function registrarInvitado(RegistrarInvitadoRequest $peticion): JsonResponse
    {
        $this->authorize('registrarInvitado', IngresoInvitado::class);

        $invitado = IngresoInvitado::create($peticion->validated());

        return response()->json([
            'invitado' => [
                'id' => $invitado->id,
                'nombre' => $invitado->nombre,
                'entrada_en' => $invitado->entrada_en->toIso8601String(),
            ],
        ], 201);
    }
}