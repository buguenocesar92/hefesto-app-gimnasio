<?php

declare(strict_types=1);

namespace Tests\Feature\Membresias;

use App\Enums\EstadoMembresia;
use App\Enums\MotivoBloqueoIngreso;
use App\Enums\MotivoPeriodoSinCobro;
use App\Models\Cobro;
use App\Models\Membresia;
use App\Models\PeriodoSinCobro;
use App\Models\Plan;
use App\Models\Socio;
use App\Models\Tenant;
use App\Services\Membresias\CalculadoraEstadoMembresia;
use App\Services\Tenancy\ContextoGimnasio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EL MES GRATIS DE DICIEMBRE
 * -------------------------
 * Muchos contratos de gimnasio tienen un mes gratis. Esta prueba comprueba
 * que el sistema lo trate como lo que es: UN PERIODO SIN COBRO con fecha de
 * inicio y de término, al lado de los cobros y con las mismas reglas de
 * encaste.
 *
 * Lo que NO se comprueba, porque no existe y no debe existir: que haya una
 * columna `gratis` en la membresía. Si alguien la agrega, esta prueba sigue
 * pasando (el derecho se calcula igual), pero el dueño se queda sin poder
 * responder "¿hasta cuándo dura?".
 */
class MesGratisTest extends TestCase
{
    use RefreshDatabase;

    private ContextoGimnasio $contexto;

    private CalculadoraEstadoMembresia $calculadora;

    private Tenant $gimnasio;

    private Plan $plan;

    private Socio $socio;

    private Membresia $membresia;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->sePuedeProbarConEstaBase()) {
            $this->markTestSkipped('Requiere PostgreSQL: las fechas se comparan en la base.');
        }

        $this->contexto = new ContextoGimnasio();
        $this->calculadora = new CalculadoraEstadoMembresia();

        $this->gimnasio = Tenant::create([
            'slug' => 'gimnasio-trimestral',
            'nombre' => 'Gimnasio trimestral',
            'zona_horaria' => Tenant::ZONA_HORARIA_POR_DEFECTO,
            'activo' => true,
        ]);

        $this->contexto->ejecutarAislado($this->gimnasio->id, function (): void {
            $this->plan = Plan::create([
                'nombre' => 'Trimestral con mes gratis',
                'precio_clp' => 74900,
                'dias_periodo' => 90,
                'activo' => true,
            ]);

            $this->socio = Socio::factory()->create(['tenant_id' => $this->gimnasio->id]);

            $this->membresia = Membresia::create([
                'socio_id' => $this->socio->id,
                'plan_id' => $this->plan->id,
                'iniciada_en' => '2026-11-01',
                'estado' => EstadoMembresia::Activa->value,
            ]);
        });
    }

    #[Test]
    public function un_cobro_mensual_cubre_su_periodo_y_no_el_siguiente(): void
    {
        $this->enContexto(function (): void {
            $this->cobrar('2026-11-01', '2026-12-01');

            $this->assertTrue($this->membresia->cubreHoy('2026-11-01'), 'El primer día está cubierto.');
            $this->assertTrue($this->membresia->cubreHoy('2026-11-30'), 'El último día del mes está cubierto.');
            $this->assertFalse(
                $this->membresia->cubreHoy('2026-12-01'),
                'El día del fin del periodo ya es del periodo siguiente.'
            );
        });
    }

    #[Test]
    public function el_mes_gratis_extiende_el_derecho_sin_cobrar_nada(): void
    {
        $this->enContexto(function (): void {
            $this->cobrar('2026-11-01', '2026-12-01');

            // Todavía no hay cobro para diciembre, pero sí el mes gratis.
            $this->agregarMesGratis('2026-12-01', '2027-01-01');

            $this->assertTrue($this->membresia->cubreHoy('2026-11-15'), 'November: pagado.');
            $this->assertTrue(
                $this->membresia->cubreHoy('2026-12-15'),
                'Diciembre: el mes gratis mantiene el derecho a entrar.'
            );
            $this->assertTrue($this->membresia->cubreHoy('2026-12-31'), 'El último día del mes gratis.');

            $this->assertSame(
                '2027-01-01',
                $this->membresia->finDeCoberturaVigente('2026-12-15'),
                'El derecho sigue hasta el día que empieza el cobro siguiente.'
            );

            $this->assertSame(
                0,
                Cobro::query()->count(),
                'El mes gratis NO genera un cobro de cero pesos. Es un periodo, no un cobro.'
            );
        });
    }

    #[Test]
    public function el_cobro_de_enero_empieza_el_dia_que_termina_el_mes_gratis(): void
    {
        $this->enContexto(function (): void {
            $this->cobrar('2026-11-01', '2026-12-01');
            $this->agregarMesGratis('2026-12-01', '2027-01-01');
            $this->cobrar('2027-01-01', '2027-03-01');

            // No hay hueco ni solapamiento entre el mes gratis y el cobro.
            $this->assertTrue($this->membresia->cubreHoy('2026-12-31'));
            $this->assertTrue($this->membresia->cubreHoy('2027-01-01'));
            $this->assertTrue($this->membresia->cubreHoy('2027-02-28'));

            // Y el fin de la cobertura se extiende hasta marzo.
            $this->assertSame('2027-03-01', $this->membresia->finDeCoberturaVigente('2026-12-15'));
        });
    }

    #[Test]
    public function sin_cobro_de_enero_el_derecho_termina_al_fin_del_mes_gratis(): void
    {
        $this->enContexto(function (): void {
            $this->cobrar('2026-11-01', '2026-12-01');
            $this->agregarMesGratis('2026-12-01', '2027-01-01');

            $this->assertFalse(
                $this->membresia->cubreHoy('2027-01-01'),
                'Terminó el mes gratis y no hay cobro de enero: el derecho se acaba.'
            );

            $this->assertSame(
                MotivoBloqueoIngreso::PeriodoVencido,
                $this->calculadora->motivoDeBloqueo($this->membresia, Carbon::parse('2027-01-01')),
            );
        });
    }

    #[Test]
    public function el_mes_gratis_es_un_periodo_con_fecha_de_termino_y_no_una_bandera(): void
    {
        $this->enContexto(function (): void {
            $this->cobrar('2026-11-01', '2026-12-01');
            $periodo = $this->agregarMesGratis('2026-12-01', '2027-01-01');

            // Lo que una bandera NO podría contestar, y este periodo sí:
            $this->assertSame('2026-12-01', $periodo->desde->toDateString());
            $this->assertSame('2027-01-01', $periodo->hasta->toDateString());
            $this->assertSame(31, $periodo->dias(), 'Diciembre tiene 31 días.');
            $this->assertSame(MotivoPeriodoSinCobro::MesGratis, $periodo->motivo);

            // Y el motivo distingue el mes gratis pactado de una cortesía o de
            // un cierre técnico.
            $this->assertNotSame(
                $periodo->motivo,
                $this->calculadora->crearPeriodoSinCobro(
                    $this->membresia,
                    Carbon::parse('2027-03-01'),
                    Carbon::parse('2027-03-05'),
                    MotivoPeriodoSinCobro::Cortesia
                )->motivo
            );
        });
    }

    #[Test]
    public function no_se_pueden_cargar_dos_periodos_sin_cobro_superpuestos(): void
    {
        $this->enContexto(function (): void {
            $this->cobrar('2026-11-01', '2026-12-01');
            $this->agregarMesGratis('2026-12-01', '2027-01-01');

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/se cruza con otro periodo/');

            // Un mes gratis encima de otro dejaría un periodo que nadie sabría
            // interpretar después.
            $this->calculadora->crearPeriodoSinCobro(
                $this->membresia,
                Carbon::parse('2026-12-15'),
                Carbon::parse('2027-01-15'),
                MotivoPeriodoSinCobro::MesGratis
            );
        });
    }

    #[Test]
    public function un_periodo_sin_cobro_no_puede_terminar_antes_de_empezar(): void
    {
        $this->enContexto(function (): void {
            $this->expectException(\InvalidArgumentException::class);

            $this->calculadora->crearPeriodoSinCobro(
                $this->membresia,
                Carbon::parse('2026-12-01'),
                Carbon::parse('2026-11-01'),
                MotivoPeriodoSinCobro::MesGratis
            );
        });
    }

    #[Test]
    public function el_mes_gratis_igual_al_derecho_y_el_cobro_al_monto(): void
    {
        // La razón de negocio del mes gratis: el socio mantiene el derecho a
        // entrar, pero no aparece plata cobrada. Son dos hechos distintos y
        // el dueño los necesita separados en el arqueo.
        $this->enContexto(function (): void {
            $this->cobrar('2026-11-01', '2026-12-01', 74900);
            $this->agregarMesGratis('2026-12-01', '2027-01-01');

            $diciembre = Cobro::query()
                ->where('periodo_desde', '<=', '2026-12-01')
                ->where('periodo_hasta', '>', '2026-12-01')
                ->first();

            $this->assertNull($diciembre, 'En diciembre no hay cobro.');

            $this->assertNotNull(
                PeriodoSinCobro::query()
                    ->where('desde', '<=', '2026-12-01')
                    ->where('hasta', '>', '2026-12-01')
                    ->first(),
                'Pero sí hay derecho: el periodo sin cubre diciembre.'
            );
        });
    }

    // --- Utilidades ---------------------------------------------------------

    private function enContexto(callable $operacion): void
    {
        $this->contexto->ejecutarAislado($this->gimnasio->id, function () use ($operacion): void {
            $operacion();
        });
    }

    private function cobrar(string $desde, string $hasta, int $monto = 29900): Cobro
    {
        return Cobro::create([
            'membresia_id' => $this->membresia->id,
            'periodo_desde' => $desde,
            'periodo_hasta' => $hasta,
            'monto_clp' => $monto,
            'medio_pago' => 'tarjeta',
            'pagado_en' => Carbon::parse($desde)->addDay(),
            'anulado' => false,
        ]);
    }

    private function agregarMesGratis(string $desde, string $hasta): PeriodoSinCobro
    {
        return $this->calculadora->crearPeriodoSinCobro(
            $this->membresia,
            Carbon::parse($desde),
            Carbon::parse($hasta),
            MotivoPeriodoSinCobro::MesGratis,
            'Mes gratis de diciembre pactado en el contrato.'
        );
    }
}