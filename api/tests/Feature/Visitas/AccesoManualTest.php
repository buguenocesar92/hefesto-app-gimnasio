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
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CORTES DE ENERGÍA Y FALLOS DEL TURNO
 * -----------------------------------
 * El acceso manual existe porque hay cutouts. Cuando se corta la luz, o
 * cuando el turno deja de leer, alguien anota a mano quién entró.
 *
 * La regla: ese acceso QUEDA MARCADO COMO MANUAL, con quién lo anotó y por
 * qué. No es un detalle de forma. Si el acceso manual se mezclara con el del
 * dispositivo, en seis meses no habría forma de distinguir un corte de luz
 * de un socio que se coló, y el registro de asistencia valdría menos que el
 * papel.
 */
class AccesoManualTest extends TestCase
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
            $this->markTestSkipped('Requiere PostgreSQL: el caso se comprueba sobre la tabla de visitas.');
        }

        $this->contexto = new ContextoGimnasio();
        $this->registro = new RegistroVisita();

        $this->gimnasio = Tenant::create([
            'slug' => 'gimnasio-cortes',
            'nombre' => 'Gimnasio con cortes',
            'zona_horaria' => Tenant::ZONA_HORARIA_POR_DEFECTO,
            'activo' => true,
        ]);

        $this->socio = $this->contexto->ejecutarAislado(
            $this->gimnasio->id,
            fn (): Socio => Socio::factory()->create(['tenant_id' => $this->gimnasio->id])
        );
    }

    #[Test]
    public function el_acceso_manual_exige_quien_lo_anoto(): void
    {
        $this->contexto->ejecutarAislado($this->gimnasio->id, function (): void {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/quién lo anotó/');

            $this->registro->entrar(
                $this->socio,
                CanalAcceso::Manual,
                null,
                now(),
                null,
                'Corte de energía.'
            );
        });
    }

    #[Test]
    public function el_acceso_manual_exige_un_motivo(): void
    {
        $this->contexto->ejecutarAislado($this->gimnasio->id, function (): void {
            $usuario = $this->crearUsuario();

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/corte de energía o fallo del turno/');

            $this->registro->entrar($this->socio, CanalAcceso::Manual, $usuario->id, now(), null, null);
        });
    }

    #[Test]
    public function un_acceso_manual_completo_queda_marcado_como_manual(): void
    {
        $visita = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): Visita {
            $usuario = $this->crearUsuario();

            return $this->registro->entrar(
                $this->socio,
                CanalAcceso::Manual,
                $usuario->id,
                now(),
                null,
                'Corte de energía entre las 19:00 y las 20:30.'
            );
        });

        $this->assertSame(CanalAcceso::Manual, $visita->canal);
        $this->assertSame(
            'Corte de energía entre las 19:00 y las 20:30.',
            $visita->motivo_manual,
            'El motivo queda escrito en la visita, no solo en el log.'
        );
        $this->assertNull(
            $visita->dispositivo,
            'Un acceso manual no puede atribuirse a un dispositivo que no lo registró.'
        );

        // Y queda en la bitácora, que es lo que permite reconstruir la noche.
        $evento = $visita->eventos()->first();
        $this->assertSame(CanalAcceso::Manual, $evento->canal);
        $this->assertSame('Corte de energía entre las 19:00 y las 20:30.', $evento->motivo);
    }

    #[Test]
    public function el_acceso_por_dispositivo_no_exige_motivo_ni_responsable(): void
    {
        $visita = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): Visita {
            return $this->registro->entrar(
                $this->socio,
                CanalAcceso::Dispositivo,
                null,
                now(),
                'turno-principal'
            );
        });

        $this->assertSame(CanalAcceso::Dispositivo, $visita->canal);
        $this->assertSame('turno-principal', $visita->dispositivo);
        $this->assertNull($visita->motivo_manual);
    }

    #[Test]
    public function se_puede_distinguir_cuantos_ingresos_fueron_manuales(): void
    {
        // Es la pregunta que el dueño se hace después de un corte: "¿cuánta
        // gente entró mientras no había sistema?". Con el canal guardado, la
        // respuesta sale de una consulta.
        $conteo = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): array {
            $usuario = $this->crearUsuario();

            $porDispositivo = $this->registro->entrar(
                $this->socio,
                CanalAcceso::Dispositivo,
                null,
                now(),
                'turno-principal'
            );
            $this->registro->salir($porDispositivo, CanalAcceso::Dispositivo, null, now()->addHour());

            $manual = $this->registro->entrar(
                $this->socio,
                CanalAcceso::Manual,
                $usuario->id,
                now(),
                null,
                'El turno se cayó.'
            );
            $this->registro->salir($manual, CanalAcceso::Manual, $usuario->id, now()->addHour(), 'El turno se cayó.');

            return [
                'total' => Visita::query()->count(),
                'manuales' => Visita::query()->where('canal', CanalAcceso::Manual->value)->count(),
            ];
        });

        $this->assertSame(2, $conteo['total']);
        $this->assertSame(1, $conteo['manuales']);
    }

    #[Test]
    public function una_salida_no_puede_ser_anterior_a_la_entrada(): void
    {
        // Un turno con la hora corrida es un problema real, y aceptarlo
        // produciría visitas de duración negativa en todos los reportes.
        $this->contexto->ejecutarAislado($this->gimnasio->id, function (): void {
            $entrada = now();

            $visita = $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, $entrada);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/no puede ser anterior/');

            $this->registro->salir($visita, CanalAcceso::Dispositivo, null, $entrada->copy()->subHour());
        });
    }

    #[Test]
    public function una_visita_no_puede_cerrarse_dos_veces(): void
    {
        $this->contexto->ejecutarAislado($this->gimnasio->id, function (): void {
            $entrada = now();

            $visita = $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, $entrada);
            $this->registro->salir($visita, CanalAcceso::Dispositivo, null, $entrada->copy()->addHour());

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/ya tiene salida registrada/');

            $this->registro->salir($visita, CanalAcceso::Dispositivo, null, $entrada->copy()->addHours(2));
        });
    }

    #[Test]
    public function la_bitacora_registra_todo_lo_que_le_pasa_a_una_visita(): void
    {
        $tipos = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): array {
            $entrada = now();

            $visita = $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, $entrada);
            $this->registro->salir($visita, CanalAcceso::Dispositivo, null, $entrada->copy()->addHour());
            $this->registro->anular($visita, 'El turno leyó otra huella.', null);

            return $visita->eventos()->orderBy('ocurrido_en')->pluck('tipo')->all();
        });

        $this->assertSame([
            TipoEventoAcceso::Entrada->value,
            TipoEventoAcceso::Salida->value,
            TipoEventoAcceso::Anulacion->value,
        ], $tipos, 'La bitácora cuenta la historia completa de la visita.');
    }

    private function crearUsuario(): \App\Models\Usuario
    {
        return \App\Models\Usuario::create([
            'tenant_id' => $this->gimnasio->id,
            'authentik_sub' => (string) \Illuminate\Support\Str::uuid(),
            'correo' => 'recepcion@gimnasio-cortes.cl',
            'nombre' => 'Persona',
            'apellido' => 'De Recepción',
            'rol' => \App\Enums\RolUsuario::Encargado->value,
            'activo' => true,
        ]);
    }
}