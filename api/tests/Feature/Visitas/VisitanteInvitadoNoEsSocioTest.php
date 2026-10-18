<?php

declare(strict_types=1);

namespace Tests\Feature\Visitas;

use App\Enums\CanalAcceso;
use App\Models\IngresoInvitado;
use App\Models\Socio;
use App\Models\Tenant;
use App\Models\Visita;
use App\Services\Asistencia\ContadoresAsistencia;
use App\Services\Tenancy\ContextoGimnasio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * UN INVITADO NO ES UN SOCIO
 * --------------------------
 * La regla no es "no mezclar al invited en el conteo". La regla es que el
 * invitado NO ESTÁ en la tabla de visitas: vive en `ingresos_invitados`.
 *
 * Por eso esta prueba no necesita que alguien se acuerde de excluirlo: no
 * hay forma de que un conteo de socios lo encuentre, porque la consulta ni
 * siquiera mira esa tabla.
 */
class VisitanteInvitadoNoEsSocioTest extends TestCase
{
    use RefreshDatabase;

    private ContextoGimnasio $contexto;

    private Tenant $gimnasio;

    private Socio $socio;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->sePuedeProbarConEstaBase()) {
            $this->markTestSkipped('Requiere PostgreSQL: los conteos se calculan con la vista de visitas.');
        }

        $this->contexto = new ContextoGimnasio();

        $this->gimnasio = Tenant::create([
            'slug' => 'gimnasio-invitados',
            'nombre' => 'Gimnasio con invitados',
            'zona_horaria' => Tenant::ZONA_HORARIA_POR_DEFECTO,
            'activo' => true,
        ]);

        $this->socio = $this->contexto->ejecutarAislado(
            $this->gimnasio->id,
            fn (): Socio => Socio::factory()->create(['tenant_id' => $this->gimnasio->id])
        );
    }

    #[Test]
    public function el_invitado_no_aparece_en_la_tabla_de_visitas(): void
    {
        $this->contexto->ejecutarAislado($this->gimnasio->id, function (): void {
            $visitaDelSocio = Visita::factory()->create([
                'tenant_id' => $this->gimnasio->id,
                'socio_id' => $this->socio->id,
            ]);

            IngresoInvitado::create([
                'acompana_a_socio_id' => $this->socio->id,
                'nombre' => 'Amigo de Pedro',
                'entrada_en' => $visitaDelSocio->entrada_en,
                'salida_en' => $visitaDelSocio->entrada_en->copy()->addHour(),
                'canal' => CanalAcceso::Manual->value,
            ]);

            $this->assertSame(1, Visita::query()->count(), 'Solo la visita del socio.');
            $this->assertSame(1, IngresoInvitado::query()->count());
            $this->assertNull(
                IngresoInvitado::query()->value('acompana_a_socio_id') === null ? null : 'con_socio',
                'El invitado puede tener un socio que lo trae, pero ese vínculo no lo convierte '
                . 'en la visita de ese socio.'
            );
            $this->assertSame(
                $this->socio->id,
                IngresoInvitado::query()->value('acompana_a_socio_id'),
                'El vínculo es "quién lo trajo", no "cuenta como visita de".'
            );
        });
    }

    #[Test]
    public function los_conteos_de_visitas_no_incluyen_a_los_invitados(): void
    {
        $conteo = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): int {
            Visita::factory()->count(3)->create([
                'tenant_id' => $this->gimnasio->id,
                'socio_id' => $this->socio->id,
            ]);

            IngresoInvitado::create([
                'acompana_a_socio_id' => $this->socio->id,
                'nombre' => 'Invitado uno',
                'entrada_en' => now(),
                'canal' => CanalAcceso::Manual->value,
            ]);

            IngresoInvitado::create([
                'nombre' => 'Invitado sin socio que lo trae',
                'entrada_en' => now(),
                'canal' => CanalAcceso::Manual->value,
            ]);

            return (int) DB::select('SELECT count(*) AS total FROM public.vw_visitas')[0]->total;
        });

        $this->assertSame(
            3,
            $conteo,
            'Dos invitados el mismo día no suman visitas: no son socios.'
        );
    }

    #[Test]
    public function los_invitados_se_cuentan_aparte_y_con_su_propia_clave(): void
    {
        $datos = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): array {
            Visita::factory()->create([
                'tenant_id' => $this->gimnasio->id,
                'socio_id' => $this->socio->id,
            ]);

            IngresoInvitado::create([
                'acompana_a_socio_id' => $this->socio->id,
                'nombre' => 'Invitado de hoy',
                'entrada_en' => now(),
                'canal' => CanalAcceso::Manual->value,
            ]);

            return (new ContadoresAsistencia())->recalcular($this->gimnasio->id);
        });

        $this->assertSame(1, $datos['visitas_hoy'], 'La visita del socio.');
        $this->assertSame(1, $datos['invitados_hoy'], 'El invitado va en su propio número.');

        // "Cuántas personas entraron" es OTRA métrica, y se arma sumando las
        // dos. No es la misma cifra que las visitas de socios, y no debería
        // verse igual: el dueño necesita las dos, separadas.
        $this->assertSame(2, $datos['visitas_hoy'] + $datos['invitados_hoy']);
    }

    #[Test]
    public function un_invitado_no_genera_visita_abierta_que_bloquee_al_socio(): void
    {
        // El socio trae un invitado. El invitado entra y sale. El socio sigue
        // pudiendo registrar su propia salida, y no queda ninguna visita
        // abierta en su nombre que le impida volver a entrar.
        $this->contexto->ejecutarAislado($this->gimnasio->id, function (): void {
            $entrada = now()->setTime(19, 0);

            Visita::factory()->create([
                'tenant_id' => $this->gimnasio->id,
                'socio_id' => $this->socio->id,
                'entrada_en' => $entrada,
                'salida_en' => $entrada->copy()->addHour(),
            ]);

            IngresoInvitado::create([
                'acompana_a_socio_id' => $this->socio->id,
                'nombre' => 'Invitado',
                'entrada_en' => $entrada,
                // Sigue adentro: el invitado no tiene salida todavía.
                'salida_en' => null,
                'canal' => CanalAcceso::Manual->value,
            ]);

            $this->assertSame(0, Visita::query()->whereNull('salida_en')->count());
            $this->assertSame(1, IngresoInvitado::query()->whereNull('salida_en')->count());
        });
    }

    #[Test]
    public function del_invitado_no_se_guardan_mas_datos_que_el_nombre(): void
    {
        // Mínimo de datos a propósito: el gimnasio necesita saber quién entró,
        // no el documento ni el teléfono de alguien que viene una vez.
        // Pedirlos sería tomar datos personales sin motivo (Ley 19.628).
        $columnas = DB::select(
            "SELECT column_name
               FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = 'ingresos_invitados'"
        );

        $nombres = array_map(static fn (object $fila): string => (string) $fila->column_name, $columnas);

        foreach (['documento', 'rut', 'telefono', 'correo', 'nacimiento'] as $prohibida) {
            $this->assertNotContains(
                $prohibida,
                $nombres,
                'La tabla de invitados no debe guardar ' . $prohibida . ': no hay motivo para pedirlo.'
            );
        }

        $this->assertContains('nombre', $nombres);
    }
}