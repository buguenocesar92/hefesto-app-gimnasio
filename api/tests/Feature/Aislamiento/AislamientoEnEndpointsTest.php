<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Enums\RolUsuario;
use App\Models\Socio;
use App\Models\Tenant;
use App\Models\Usuario;
use App\Models\Visita;
use App\Services\Tenancy\ContextoGimnasio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * EL AISLAMIENTO POR HTTP, NO SOLO EN LA BASE
 * ------------------------------------------
 * `AislamientoDeDatosTest` comprueba la Row Level Security. Esta comprueba
 * que la aplicación tampoco abra una puerta por encima.
 *
 * Lo que se verifica acá:
 *
 *  1. El middleware envuelve la petición en una transacción con el
 *     gimnasio, y sin sesión no hay contexto.
 *  2. Un recurso de otro gimnasio responde 404, no 403. Un 403 confirmaría
 *     que el recurso existe, que es filtrar información.
 *  3. Un socio con cuenta no puede registrar su propia entrada: el acceso lo
 *     controla quien está en la puerta, no quien llega.
 *  4. El documento de un socio no sale para quien no tiene permiso.
 */
class AislamientoEnEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private ContextoGimnasio $contexto;

    private Tenant $primero;

    private Tenant $segundo;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->sePuedeProbarConEstaBase()) {
            $this->markTestSkipped('Requiere PostgreSQL: el aislamiento se implementa con RLS.');
        }

        $this->contexto = new ContextoGimnasio();

        $this->primero = $this->crearGimnasio('primero.cl', 'Gimnasio Primero');
        $this->segundo = $this->crearGimnasio('segundo.cl', 'Gimnasio Segundo');
    }

    #[Test]
    public function sin_sesion_los_endpoints_de_negocio_responden_401(): void
    {
        $this->getJson('/api/v1/socios')->assertUnauthorized();
        $this->getJson('/api/v1/panel')->assertUnauthorized();
        $this->getJson('/api/v1/visitas')->assertUnauthorized();
    }

    #[Test]
    public function la_salud_responde_sin_sesion(): void
    {
        // La salud es pública a propósito: el orquestador de contenedores la
        // consulta para saber si el servicio está vivo, y no toca datos de
        // socios. Y no tiene contexto de gimnasio, así que si alguna vez
        // tocara una tabla protegida, `app_tenant_id()` lanzaría excepción y
        // la petición fallaría. El aislamiento falla cerrado.
        $this->getJson('/api/v1/salud')
            ->assertOk()
            ->assertJsonPath('estado', 'ok');
    }

    #[Test]
    public function un_socio_de_otro_gimnasio_responde_404_y_no_403(): void
    {
        $socioAjeno = $this->contexto->ejecutarAislado(
            $this->segundo->id,
            fn (): Socio => Socio::factory()->create([
                'tenant_id' => $this->segundo->id,
                'documento' => '22.111.333-4',
            ])
        );

        $usuario = $this->contexto->ejecutarAislado(
            $this->primero->id,
            fn (): Usuario => $this->crearUsuario($this->primero, RolUsuario::Encargado)
        );

        $respuesta = $this->actingAs($usuario)->getJson('/api/v1/socios/' . $socioAjeno->id);

        $respuesta->assertNotFound();
    }

    #[Test]
    public function una_visita_de_otro_gimnasio_responde_404(): void
    {
        $visitaAjena = $this->contexto->ejecutarAislado($this->segundo->id, function (): Visita {
            $socio = Socio::factory()->create([
                'tenant_id' => $this->segundo->id,
                'documento' => '33.222.444-5',
            ]);

            return Visita::factory()->create([
                'tenant_id' => $this->segundo->id,
                'socio_id' => $socio->id,
            ]);
        });

        $usuario = $this->contexto->ejecutarAislado(
            $this->primero->id,
            fn (): Usuario => $this->crearUsuario($this->primero, RolUsuario::Encargado)
        );

        $this->actingAs($usuario)->getJson('/api/v1/visitas/' . $visitaAjena->id)
            ->assertNotFound();
    }

    #[Test]
    public function la_lista_de_socios_no_trae_socios_de_otro_gimnasio(): void
    {
        $this->contexto->ejecutarAislado($this->primero->id, function (): void {
            Socio::factory()->count(2)->create([
                'tenant_id' => $this->primero->id,
                'documento' => null,
            ]);
        });

        $this->contexto->ejecutarAislado($this->segundo->id, function (): void {
            Socio::factory()->count(5)->create([
                'tenant_id' => $this->segundo->id,
                'documento' => null,
            ]);
        });

        $usuario = $this->contexto->ejecutarAislado(
            $this->primero->id,
            fn (): Usuario => $this->crearUsuario($this->primero, RolUsuario::Encargado)
        );

        $respuesta = $this->actingAs($usuario)->getJson('/api/v1/socios');

        $respuesta->assertOk();
        $respuesta->assertJsonCount(2, 'data');
    }

    #[Test]
    public function el_documento_del_socio_no_sale_a_un_coach(): void
    {
        // El coach necesita ver la ficha para trabajar con el socio. El
        // número de documento es un dato personal que no necesita, y por eso
        // el recurso lo oculta según quién pregunta.
        $socio = $this->contexto->ejecutarAislado(
            $this->primero->id,
            fn (): Socio => Socio::factory()->create([
                'tenant_id' => $this->primero->id,
                'documento' => '11.222.333-4',
            ])
        );

        $coach = $this->contexto->ejecutarAislado(
            $this->primero->id,
            fn (): Usuario => $this->crearUsuario($this->primero, RolUsuario::Coach)
        );

        $this->actingAs($coach)->getJson('/api/v1/socios/' . $socio->id)
            ->assertOk()
            ->assertJsonPath('socio.documento', null);

        $encargado = $this->contexto->ejecutarAislado(
            $this->primero->id,
            fn (): Usuario => $this->crearUsuario($this->primero, RolUsuario::Encargado, 'encargado2')
        );

        $this->actingAs($encargado)->getJson('/api/v1/socios/' . $socio->id)
            ->assertOk()
            ->assertJsonPath('socio.documento', '11.222.333-4');
    }

    #[Test]
    public function el_coach_no_ve_el_panel_de_socios_en_riesgo(): void
    {
        $coach = $this->contexto->ejecutarAislado(
            $this->primero->id,
            fn (): Usuario => $this->crearUsuario($this->primero, RolUsuario::Coach)
        );

        // La lista de socios que están a punto de irse es información de
        // negocio; un coach no necesita verla completa.
        $this->actingAs($coach)->getJson('/api/v1/panel')->assertForbidden();
    }

    #[Test]
    public function un_socio_no_puede_registrar_su_propia_entrada(): void
    {
        $socio = $this->contexto->ejecutarAislado(
            $this->primero->id,
            fn (): Socio => Socio::factory()->create([
                'tenant_id' => $this->primero->id,
                'documento' => null,
            ])
        );

        $cuentaSocio = $this->contexto->ejecutarAislado(
            $this->primero->id,
            fn (): Usuario => $this->crearUsuario($this->primero, RolUsuario::Socio, 'socio-cuenta')
        );

        $socio->forceFill(['usuario_id' => $cuentaSocio->id])->save();

        $respuesta = $this->actingAs($cuentaSocio)->postJson('/api/v1/visitas/entrada', [
            'socio_id' => $socio->id,
            'canal' => 'aplicacion',
        ]);

        // El acceso lo registra quien controla la puerta. Un socio que puede
        // registrarse su propia entrada puede inventarse las visitas que
        // quiera, y con eso falsear el panel.
        $respuesta->assertForbidden();
    }

    // --- Utilidades ---------------------------------------------------------

    private function crearGimnasio(string $dominio, string $nombre): Tenant
    {
        return Tenant::create([
            'slug' => \Illuminate\Support\Str::slug($dominio),
            'nombre' => $nombre,
            'zona_horaria' => Tenant::ZONA_HORARIA_POR_DEFECTO,
            'activo' => true,
        ]);
    }

    private function crearUsuario(Tenant $gimnasio, RolUsuario $rol, ?string $prefijo = null): Usuario
    {
        return Usuario::create([
            'tenant_id' => $gimnasio->id,
            'authentik_sub' => (string) \Illuminate\Support\Str::uuid(),
            'correo' => ($prefijo ?? $rol->value) . '@' . $gimnasio->slug . '.cl',
            'nombre' => 'Persona',
            'apellido' => 'De Prueba',
            'rol' => $rol->value,
            'activo' => true,
            'ultimo_acceso' => now(),
        ]);
    }
}