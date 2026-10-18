<?php

declare(strict_types=1);

namespace Tests\Feature\Retencion;

use App\Enums\EstadoMembresia;
use App\Models\AvisoAbandono;
use App\Models\Cobro;
use App\Models\Membresia;
use App\Models\Plan;
use App\Models\Socio;
use App\Models\Tenant;
use App\Models\Visita;
use App\Services\Retencion\DetectorDeInactividad;
use App\Services\Tenancy\ContextoGimnasio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LA GENTE DEJA DE VENIR Y NADIE SE ENTERA HASTA QUE YA SE FUERON
 * -----------------------------------------------------------
 * Esta prueba comprueba lo que el producto existe para encontrar: el socio
 * con membresía vigente que lleva demasiado tiempo sin aparecer.
 *
 * Y comprueba algo que importa más todavía: que el aviso se cierre solo
 * cuando el socio vuelve. Un panel que sigue mostrando como riesgo de fuga
 * a alguien que entrena tres veces por semana deja de ser leído a los tres
 * días.
 */
class DetectorDeInactividadTest extends TestCase
{
    use RefreshDatabase;

    private ContextoGimnasio $contexto;

    private DetectorDeInactividad $detector;

    private Tenant $gimnasio;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->sePuedeProbarConEstaBase()) {
            $this->markTestSkipped('Requiere PostgreSQL: el detector consulta la tabla de visitas.');
        }

        $this->contexto = new ContextoGimnasio();
        $this->detector = new DetectorDeInactividad();

        $this->gimnasio = Tenant::create([
            'slug' => 'gimnasio-retention',
            'nombre' => 'Gimnasio de retención',
            'zona_horaria' => Tenant::ZONA_HORARIA_POR_DEFECTO,
            'dias_aviso_inactividad' => 21,
            'activo' => true,
        ]);

        $this->plan = $this->contexto->ejecutarAislado(
            $this->gimnasio->id,
            fn (): Plan => Plan::create([
                'nombre' => 'Mensual',
                'precio_clp' => 29900,
                'dias_periodo' => 30,
                'activo' => true,
            ])
        );
    }

    #[Test]
    public function detecta_al_socio_que_no_viene_hace_mas_de_veintiun_dias(): void
    {
        $avisos = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): array {
            $socio = $this->crearSocioConMembresiaAlDia();

            // Última visita hace 26 días.
            $entrada = now()->subDays(26)->setTime(18, 30);
            Visita::factory()->create([
                'tenant_id' => $this->gimnasio->id,
                'socio_id' => $socio->id,
                'entrada_en' => $entrada,
                'salida_en' => $entrada->copy()->addHour(),
            ]);

            return $this->detector->registrar()->all();
        });

        $this->assertCount(1, $avisos);
        $this->assertSame(26, $avisos[0]->dias_sin_venir);
    }

    #[Test]
    public function no_avisa_al_socio_que_viene_semanalmente(): void
    {
        $avisos = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): array {
            $socio = $this->crearSocioConMembresiaAlDia();

            for ($semana = 1; $semana <= 3; $semana++) {
                $entrada = now()->subWeeks($semana)->setTime(19, 0);
                Visita::factory()->create([
                    'tenant_id' => $this->gimnasio->id,
                    'socio_id' => $socio->id,
                    'entrada_en' => $entrada,
                    'salida_en' => $entrada->copy()->addHour(),
                ]);
            }

            return $this->detector->registrar()->all();
        });

        $this->assertCount(0, $avisos, 'Un socio que viene no es un riesgo de fuga.');
    }

    #[Test]
    public function el_aviso_muestra_las_dos_senales_juntas(): void
    {
        $avisos = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): array {
            $socio = $this->crearSocioConMembresiaAlDia();

            $entrada = now()->subDays(30)->setTime(18, 30);
            Visita::factory()->create([
                'tenant_id' => $this->gimnasio->id,
                'socio_id' => $socio->id,
                'entrada_en' => $entrada,
                'salida_en' => $entrada->copy()->addHour(),
            ]);

            return $this->detector->registrar()->all();
        });

        $this->assertCount(1, $avisos);

        // La señal clave: dejó de venir, pero SIGUE PAGANDO. Es el caso
        // recuperable, y el que el dueño tiene que llamar hoy.
        $this->assertTrue(
            $avisos[0]->pago_al_dia,
            'El socio dejó de venir pero su membresía está al día: ese es el aviso que sí sirve.'
        );
        $this->assertNotNull($avisos[0]->ultimo_pago);
        $this->assertNotNull($avisos[0]->ultima_visita_en);
    }

    #[Test]
    public function no_avisa_a_un_socio_suspendido(): void
    {
        $avisos = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): array {
            $socio = $this->crearSocioConMembresiaAlDia();

            $entrada = now()->subDays(40)->setTime(18, 30);
            Visita::factory()->create([
                'tenant_id' => $this->gimnasio->id,
                'socio_id' => $socio->id,
                'entrada_en' => $entrada,
                'salida_en' => $entrada->copy()->addHour(),
            ]);

            // Ya avisó que no viene, y tiene fecha de vuelta.
            $socio->forceFill([
                'suspendida_desde' => now()->subDays(10),
                'suspension_hasta' => now()->addDays(20),
            ])->save();

            return $this->detector->registrar()->all();
        });

        $this->assertCount(
            0,
            $avisos,
            'Un socio suspendido ya avisó que no viene: contarlo sería ruido en el panel.'
        );
    }

    #[Test]
    public function el_aviso_se_cierra_cuando_el_socio_vuelve(): void
    {
        $this->contexto->ejecutarAislado($this->gimnasio->id, function (): void {
            $socio = $this->crearSocioConMembresiaAlDia();

            $entrada = now()->subDays(26)->setTime(18, 30);
            Visita::factory()->create([
                'tenant_id' => $this->gimnasio->id,
                'socio_id' => $socio->id,
                'entrada_en' => $entrada,
                'salida_en' => $entrada->copy()->addHour(),
            ]);

            $this->detector->registrar();

            $this->assertSame(1, AvisoAbandono::query()->whereNull('cerrado_en')->count());

            // El socio vuelve. Al registrar la visita, el aviso se cierra.
            $this->detector->cerrarAvisosDe($socio);

            $this->assertSame(0, AvisoAbandono::query()->whereNull('cerrado_en')->count());
            $this->assertSame(
                1,
                AvisoAbandono::query()->count(),
                'El aviso queda en la historia, no se borra: sirve para medir si sirvió.'
            );
        });
    }

    #[Test]
    public function no_se_apilan_avisos_del_mismo_socio(): void
    {
        $this->contexto->ejecutarAislado($this->gimnasio->id, function (): void {
            $socio = $this->crearSocioConMembresiaAlDia();

            $entrada = now()->subDays(26)->setTime(18, 30);
            Visita::factory()->create([
                'tenant_id' => $this->gimnasio->id,
                'socio_id' => $socio->id,
                'entrada_en' => $entrada,
                'salida_en' => $entrada->copy()->addHour(),
            ]);

            // El detector corre varias veces, como cuando se programa.
            $this->detector->registrar();
            $this->detector->registrar();
            $this->detector->registrar();

            $this->assertSame(
                1,
                AvisoAbandono::query()->where('socio_id', $socio->id)->count(),
                'Un socio con un aviso abierto no genera veinte avisos.'
            );
        });
    }

    #[Test]
    public function un_socio_que_nunca_vino_no_entra_en_la_lista_de_abandono(): void
    {
        $resultado = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): array {
            // El socio existe y nunca vino.

            $this->crearSocioConMembresiaAlDia();

            return [
                'avisos' => $this->detector->registrar()->count(),
                'nunca_vinieron' => $this->detector->sociosQueNuncaVinieron(),
            ];
        });

        $this->assertSame(0, $resultado['avisos']);
        $this->assertCount(
            1,
            $resultado['nunca_vinieron'],
            'Nunca haber venido es otro problema y aparece aparte en el panel.'
        );
    }

    // --- Utilidades ---------------------------------------------------------

    private function crearSocioConMembresiaAlDia(): Socio
    {
        $socio = Socio::factory()->create([
            'tenant_id' => $this->gimnasio->id,
            'documento' => null,
        ]);

        $membresia = Membresia::create([
            'socio_id' => $socio->id,
            'plan_id' => $this->plan->id,
            'iniciada_en' => now()->subMonths(3)->toDateString(),
            'estado' => EstadoMembresia::Activa->value,
        ]);

        // Un cobro que cubre de hoy en adelante: el socio está al día.
        Cobro::create([
            'membresia_id' => $membresia->id,
            'periodo_desde' => now()->startOfMonth()->toDateString(),
            'periodo_hasta' => now()->startOfMonth()->copy()->addMonth()->toDateString(),
            'monto_clp' => 29900,
            'medio_pago' => 'tarjeta',
            'pagado_en' => now()->startOfMonth(),
            'anulado' => false,
        ]);

        return $socio;
    }
}
