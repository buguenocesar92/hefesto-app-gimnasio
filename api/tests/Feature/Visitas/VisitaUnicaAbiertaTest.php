<?php

declare(strict_types=1);

namespace Tests\Feature\Visitas;

use App\Enums\CanalAcceso;
use App\Enums\TipoEventoAcceso;
use App\Models\Socio;
use App\Models\Tenant;
use App\Models\Visita;
use App\Services\Asistencia\RegistroVisita;
use App\Services\Tenancy\ContextoGimnasio;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PRUEBA CRÍTICA: un socio no puede tener dos visitas simultáneas abiertas.
 *
 * La regla está en la base, en un índice único parcial:
 *
 *     UNIQUE (socio_id) WHERE salida_en IS NULL
 *
 * Estas pruebas comprueban las TRES capas en que puede romperse:
 *
 *  1. El servicio (`RegistroVisita`) rechaza con un mensaje legible.
 *  2. La base lo impone, aunque se la salte la comprobación del servicio.
 *  3. Cerrar la visita libera la entrada: un socio puede volver a entrar.
 *
 * El punto 2 es el que importa: dos personas picando entrada el mismo
 * instante leen "no hay visita abierta" las dos, y las dos escriben. Solo el
 * índice puede impedir eso.
 */
class VisitaUnicaAbiertaTest extends TestCase
{
    use RefreshDatabase;

    private ContextoGimnasio $contexto;

    private RegistroVisita $registro;

    private Tenant $gimnasio;

    private Socio $socio;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->sePuedeProbarConEstaBase()) {
            $this->markTestSkipped(
                'Requiere PostgreSQL: el índice único parcial que impide dos visitas abiertas '
                . 'no existe en otro motor de base de datos.'
            );
        }

        $this->contexto = new ContextoGimnasio();
        $this->registro = new RegistroVisita();

        $this->gimnasio = Tenant::create([
            'slug' => 'gimnasio-prueba',
            'nombre' => 'Gimnasio de prueba',
            'zona_horaria' => Tenant::ZONA_HORARIA_POR_DEFECTO,
            'horas_visita_abierta_maxima' => 8,
            'activo' => true,
        ]);

        $this->socio = $this->contexto->ejecutarAislado(
            $this->gimnasio->id,
            fn (): Socio => Socio::factory()->create(['tenant_id' => $this->gimnasio->id])
        );
    }

    #[Test]
    public function el_socio_puede_tener_una_visita_abierta(): void
    {
        $visita = $this->contexto->ejecutarAislado(
            $this->gimnasio->id,
            fn (): Visita => $this->registro->entrar(
                $this->socio,
                CanalAcceso::Dispositivo,
                null,
                now()
            )
        );

        $this->assertNull($visita->salida_en);
        $this->assertTrue($visita->estaAbierta());
    }

    #[Test]
    public function el_servicio_rechaza_una_segunda_entrada_con_la_primera_abierta(): void
    {
        $this->contexto->ejecutarAislado($this->gimnasio->id, function (): void {
            $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, now());

            // Lo que se espera NO es una excepción cualquiera: es un mensaje
            // que la persona de recepción pueda leer y entender. Por eso el
            // servicio lanza RuntimeException con la hora de la visita
            // abierta, y no un error de base de datos.
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/ya tiene una visita abierta/');

            $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, now());
        });
    }

    #[Test]
    public function la_base_impide_dos_visitas_abiertas_aunque_el_servicio_no_mire(): void
    {
        // Esta es la comprobación central. Se escribe DIRECTO en la tabla,
        // saltándose el servicio a propósito, para demostrar que la garantía
        // no depende de que `RegistroVisita` esté bien escrito.
        //
        // Si esta prueba falla, la regla de negocio depende del código.
        $this->expectException(QueryException::class);

        $this->contexto->ejecutarAislado($this->gimnasio->id, function (): void {
            Visita::create([
                'tenant_id' => $this->gimnasio->id,
                'socio_id' => $this->socio->id,
                'entrada_en' => now()->subHour(),
                'salida_en' => null,
            ]);

            Visita::create([
                'tenant_id' => $this->gimnasio->id,
                'socio_id' => $this->socio->id,
                'entrada_en' => now(),
                'salida_en' => null,
            ]);
        });
    }

    #[Test]
    public function el_indice_solo_considera_las_visitas_abiertas(): void
    {
        // Un socio puede tener todas las visitas cerradas que quiera: el
        // índice es PARCIAL y solo mira las que tienen `salida_en IS NULL`.
        $this->contexto->ejecutarAislado($this->gimnasio->id, function (): void {
            for ($i = 0; $i < 5; $i++) {
                $entrada = now()->subDays($i + 1)->setTime(19, 0);

                Visita::create([
                    'tenant_id' => $this->gimnasio->id,
                    'socio_id' => $this->socio->id,
                    'entrada_en' => $entrada,
                    'salida_en' => $entrada->copy()->addHour(),
                ]);
            }

            // Y una sexta abierta: sigue habiendo una sola.
            Visita::create([
                'tenant_id' => $this->gimnasio->id,
                'socio_id' => $this->socio->id,
                'entrada_en' => now(),
                'salida_en' => null,
            ]);

            $this->assertSame(1, Visita::query()->whereNull('salida_en')->count());
            $this->assertSame(6, Visita::query()->count());
        });
    }

    #[Test]
    public function dos_socios_diferentes_pueden_estar_adentro_al_mismo_tiempo(): void
    {
        $otro = $this->contexto->ejecutarAislado(
            $this->gimnasio->id,
            fn (): Socio => Socio::factory()->create(['tenant_id' => $this->gimnasio->id])
        );

        $this->contexto->ejecutarAislado($this->gimnasio->id, function () use ($otro): void {
            $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, now());
            $this->registro->entrar($otro, CanalAcceso::Dispositivo, null, now());

            $this->assertSame(2, Visita::query()->whereNull('salida_en')->count());
        });
    }

    #[Test]
    public function al_registrar_la_salida_el_socio_puede_volver_a_entrar(): void
    {
        $this->contexto->ejecutarAislado($this->gimnasio->id, function (): void {
            $entrada = now()->setTime(19, 0);

            $primera = $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, $entrada);
            $this->registro->salir($primera, CanalAcceso::Dispositivo, null, $entrada->copy()->addHour());

            // Tres visitas más el mismo día: el socio que vino en la mañana,
            // en la tarde y en la noche.
            for ($i = 0; $i < 3; $i++) {
                $otraEntrada = $entrada->copy()->addHours(3 + ($i * 3));
                $visita = $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, $otraEntrada);
                $this->registro->salir($visita, CanalAcceso::Dispositivo, null, $otraEntrada->copy()->addHour());
            }

            $this->assertSame(4, Visita::query()->count(), 'Cuatro visitas en un mismo día.');
            $this->assertSame(0, Visita::query()->whereNull('salida_en')->count());
        });
    }

    #[Test]
    public function una_visita_anulada_libera_la_entrada(): void
    {
        // Si la entrada estaba mal leída, el socio tiene que poder volver a
        // entrar sin que una anulación mal hecha le bloquee la puerta.
        $this->contexto->ejecutarAislado($this->gimnasio->id, function (): void {
            $visita = $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, now());

            $this->registro->anular($visita, 'El turno leyó otra huella.', null);

            $nueva = $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, now());

            $this->assertTrue($nueva->estaAbierta());
            $this->assertSame(1, Visita::query()->whereNull('salida_en')->count());
        });
    }

    #[Test]
    public function el_sistema_puede_cerrar_una_visita_que_quedo_abierta(): void
    {
        $this->contexto->ejecutarAislado($this->gimnasio->id, function (): void {
            $entrada = now()->subHours(10);

            $visita = $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, $entrada);
            $this->registro->cerrarPorSistema($visita, $entrada->copy()->addHours(8));

            $visita->refresh();

            $this->assertNotNull($visita->salida_en);
            $this->assertTrue(
                $visita->cerrada_por_sistema,
                'Una visita cerrada sola tiene que quedar marcada como tal, para que recepción '
                . 'distinga "el socio salió a las 21:30" de "nadie estaba en la casilla".'
            );

            $evento = $visita->eventos()->where('tipo', TipoEventoAcceso::CierrePorSistema->value)->first();

            $this->assertNotNull($evento, 'El cierre por sistema queda escrito en la bitácora.');
        });
    }
}