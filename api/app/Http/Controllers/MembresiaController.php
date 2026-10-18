<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoMembresia;
use App\Http\Requests\Membresias\CrearMembresiaRequest;
use App\Http\Requests\Membresias\CrearPeriodoSinCobroRequest;
use App\Http\Requests\Membresias\RegistrarCobroRequest;
use App\Models\Cobro;
use App\Models\Membresia;
use App\Models\Socio;
use App\Services\Membresias\CalculadoraEstadoMembresia;
use Illuminate\Http\JsonResponse;

/**
 * Membresías, cobros y periodos sin cobro.
 *
 * LAS TRES COSAS RESPONDEN JUNTAS
 * ------------------------------
 * La fecha de pago importa más de lo que parece: el socio deja de asistir
 * antes de dejar de pagar, o al revés según el caso. Por eso ningún endpoint
 * de acá devuelve solo el estado del cobro: devuelve también el estado de
 * la asistencia, con `CalculadoraEstadoMembresia::resumen()`.
 */
class MembresiaController extends Controller
{
    public function __construct(
        private readonly CalculadoraEstadoMembresia $calculadora
    ) {}

    public function indice(Socio $socio): JsonResponse
    {
        $this->authorize('verMembresia', $socio);

        $membresias = $socio->membresias()
            ->with(['plan', 'cobros' => function ($consulta): void {
                $consulta->orderByDesc('periodo_hasta');
            }, 'periodosSinCobro' => function ($consulta): void {
                $consulta->orderByDesc('hasta');
            }])
            ->get()
            ->map(function (Membresia $membresia): array {
                return [
                    'id' => $membresia->id,
                    'plan' => $membresia->plan?->nombre,
                    'estado' => $membresia->estado->value,
                    'iniciada_en' => $membresia->iniciada_en?->toDateString(),
                    'motivo_bloqueo' => $this->calculadora->motivoDeBloqueo($membresia)->value,
                    'cubre_hoy' => $membresia->cubreHoy(),
                    'fin_de_cobertura' => $this->calculadora->finDeCobertura($membresia),
                    'ultimo_pago' => $membresia->fechaUltimoPago(),
                    'cobros' => $this->cobrosDeLaMembresia($membresia),
                    'periodos_sin_cobro' => $membresia->periodosSinCobro->map(fn ($periodo): array => [
                        'id' => $periodo->id,
                        'desde' => $periodo->desde->toDateString(),
                        'hasta' => $periodo->hasta->toDateString(),
                        'dias' => $periodo->dias(),
                        'motivo' => $periodo->motivo->value,
                        'descripcion' => $periodo->motivo->descripcion(),
                        'nota' => $periodo->nota,
                    ])->all(),
                ];
            })
            ->all();

        return response()->json(['membresias' => $membresias]);
    }

    public function mostrar(Membresia $membresia): JsonResponse
    {
        $this->authorize('ver', $membresia);

        $membresia->load(['plan', 'socio', 'cobros', 'periodosSinCobro']);

        return response()->json([
            'membresia' => [
                'id' => $membresia->id,
                'socio_id' => $membresia->socio_id,
                'plan' => $membresia->plan?->nombre,
                'estado' => $membresia->estado->value,
                'motivo_bloqueo' => $this->calculadora->motivoDeBloqueo($membresia)->value,
                'cubre_hoy' => $membresia->cubreHoy(),
                'fin_de_cobertura' => $this->calculadora->finDeCobertura($membresia),
            ],
            'cobros' => $this->cobrosDeLaMembresia($membresia),
            'periodos_sin_cobro' => $membresia->periodosSinCobro->map(fn ($periodo): array => [
                'desde' => $periodo->desde->toDateString(),
                'hasta' => $periodo->hasta->toDateString(),
                'motivo' => $periodo->motivo->value,
            ])->all(),
        ]);
    }

    public function crear(CrearMembresiaRequest $peticion, Socio $socio): JsonResponse
    {
        $this->authorize('crear', Socio::class);

        $membresia = Membresia::create([
            'socio_id' => $socio->id,
            'plan_id' => $peticion->string('plan_id')->toString(),
            'iniciada_en' => $peticion->date('iniciada_en'),
            'estado' => EstadoMembresia::Activa->value,
            'nota' => $peticion->input('nota'),
        ]);

        // El mes gratis no es una casilla de la membresía: es un periodo con
        // fecha de inicio y de término, guardado al lado de los cobros.
        $desde = $peticion->date('mes_gratis_desde');
        $hasta = $peticion->date('mes_gratis_hasta');

        if ($desde !== null && $hasta !== null) {
            $this->calculadora->crearPeriodoSinCobro(
                $membresia,
                $desde,
                $hasta,
                \App\Enums\MotivoPeriodoSinCobro::MesGratis,
                'Cargado al crear la membresía.'
            );
        }

        return response()->json(['membresia_id' => $membresia->id], 201);
    }

    public function cobrar(RegistrarCobroRequest $peticion, Membresia $membresia): JsonResponse
    {
        $this->authorize('cobrar', $membresia);

        $cobro = Cobro::create([
            'membresia_id' => $membresia->id,
            'periodo_desde' => $peticion->date('periodo_desde'),
            'periodo_hasta' => $peticion->date('periodo_hasta'),
            'monto_clp' => (int) $peticion->input('monto_clp'),
            'medio_pago' => $peticion->input('medio_pago'),
            'pagado_en' => $peticion->date('pagado_en') ?? now(),
            'comprobante' => $peticion->input('comprobante'),
            'anulado' => false,
            'registrado_por' => $peticion->user()->id,
        ]);

        return response()->json([
            'cobro_id' => $cobro->id,
            'cubre_hoy' => $membresia->fresh()->cubreHoy(),
        ], 201);
    }

    /**
     * Un periodo sin cobro: el mes gratis de diciembre.
     *
     * Es un periodo con inicio y fin, no una bandera. Ver
     * `docs/02-MODELO-DE-DATOS.md`, sección 7.
     */
    public function periodoSinCobro(
        CrearPeriodoSinCobroRequest $peticion,
        Membresia $membresia
    ): JsonResponse {
        $this->authorize('periodoSinCobro', $membresia);

        $periodo = $this->calculadora->crearPeriodoSinCobro(
            $membresia,
            $peticion->date('desde'),
            $peticion->date('hasta'),
            \App\Enums\MotivoPeriodoSinCobro::from((string) $peticion->input('motivo')),
            $peticion->input('nota'),
        );

        return response()->json([
            'periodo_id' => $periodo->id,
            'desde' => $periodo->desde->toDateString(),
            'hasta' => $periodo->hasta->toDateString(),
            'cubre_hoy' => $membresia->fresh()->cubreHoy(),
        ], 201);
    }

    public function suspender(\Illuminate\Http\Request $peticion, Membresia $membresia): JsonResponse
    {
        $this->authorize('suspender', $membresia);

        $datos = $peticion->validate([
            'motivo' => ['nullable', 'string', 'max:200'],
        ]);

        $membresia->forceFill([
            'estado' => EstadoMembresia::Suspendida->value,
            'suspendida_desde' => now(),
            'suspension_hasta' => $peticion->date('suspension_hasta'),
        ])->save();

        return response()->json([
            'membresia_id' => $membresia->id,
            'estado' => $membresia->estado->value,
            'motivo_bloqueo' => $this->calculadora->motivoDeBloqueo($membresia->fresh())->value,
        ]);
    }

    /**
     * Un socio con cuenta propia NO ve los montos que pagó.
     *
     * Ve que su membresía está al día y hasta cuándo, que es lo que necesita;
     * el precio es información del contrato con el gimnasio, no suya.
     *
     * @return list<array<string, mixed>>
     */
    private function cobrosDeLaMembresia(Membresia $membresia): array
    {
        $usuario = auth()->user();
        $veMontos = $usuario !== null && $usuario->rol->esPersonal();

        return $membresia->cobros->map(fn (Cobro $cobro): array => [
            'id' => $cobro->id,
            'periodo_desde' => $cobro->periodo_desde?->toDateString(),
            'periodo_hasta' => $cobro->periodo_hasta?->toDateString(),
            'monto_clp' => $veMontos ? $cobro->monto_clp : null,
            'pagado_en' => $cobro->pagado_en?->toIso8601String(),
            'medio_pago' => $veMontos ? $cobro->medio_pago : null,
            'comprobante' => $veMontos ? $cobro->comprobante : null,
            'anulado' => $cobro->anulado,
        ])->all();
    }
}