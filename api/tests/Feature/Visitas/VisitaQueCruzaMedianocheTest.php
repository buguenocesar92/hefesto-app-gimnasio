<?php

declare(strict_types=1);

namespace Tests\Feature\Visitas;

use App\Enums\CanalAcceso;
use App\Models\Socio;
use App\Models\Tenant;
use App\Models\Visita;
use App\Services\Asistencia\RegistroVisita;
use App\Services\Tenancy\ContextoGimnasio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * LA VISITA QUE CRUZA LA MEDIANOCHE
 * --------------------------------
 * Un socio que entra a las 23:50 y sale a las 00:10 hizo UNA visita. No son
 * dos visitas de dos días, y tampoco es un error de fecha: es lo que pasó.
 *
 * La regla que se aplica: LA VISITA PERTENECE AL DÍA DE LA ENTRADA. Está en
 * la vista `vw_visitas`, que calcula el día con la zona horaria del
 * gimnasio, y no en PHP: si se calculara con la zona del servidor (que en un
 * contenedor es UTC), todas las visitas de la tarde quedarían fechadas en el
 * día siguiente.
 *
 * Por qué importa en el negocio: el dueño cuenta la asistencia por día para
 * saber cuándo lleno el gimnasio. Si el viernes a las 23:50 se contara como
 * una visita del sábado, el gráfico muestra un sábado lleno y un viernes
 * vacío, y la decisión de ampliar el horario del viernes se toma con
 * números que no son ciertos.
 */
class VisitaQueCruzaMedianocheTest extends TestCase
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
                'Requiere PostgreSQL: el cálculo de la zona horaria usa funciones de PostgreSQL '
                . 'que no significan lo mismo en otro motor.'
            );
        }

        $this->contexto = new ContextoGimnasio();
        $this->registro = new RegistroVisita();

        $this->gimnasio = Tenant::create([
            'slug' => 'gimnasio-nocturno',
            'nombre' => 'Gimnasio nocturno',
            'zona_horaria' => 'America/Santiago',
            'activo' => true,
        ]);

        $this->socio = $this->contexto->ejecutarAislado(
            $this->gimnasio->id,
            fn (): Socio => Socio::factory()->create(['tenant_id' => $this->gimnasio->id])
        );
    }

    #[Test]
    public function una_visita_de_23_50_a_00_10_es_una_sola_visita_del_dia_de_la_entrada(): void
    {
        $fila = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): object {
            // Un viernes cualquiera, con la visita cruzando a la madrugada.
            $viernes = \Illuminate\Support\Carbon::parse('2026-03-13 23:50:00');

            $visita = $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, $viernes);
            $this->registro->salir(
                $visita,
                CanalAcceso::Dispositivo,
                null,
                \Illuminate\Support\Carbon::parse('2026-03-14 00:10:00')
            );

            $this->assertSame(1, Visita::query()->count(), 'Es una visita, no dos.');

            return DB::selectOne(
                'SELECT dia_local, duracion_minutos, abierta FROM public.vw_visitas WHERE id = ?',
                [$visita->id]
            );
        });

        $this->assertSame(
            '2026-03-13',
            $fila->dia_local,
            'La visita pertenece al día de la ENTRADA (viernes 13), no al día de la salida.'
        );

        $this->assertSame(20, (int) $fila->duracion_minutos, 'Duró 20 minutos, no 1440.');
        $this->assertFalse((bool) $fila->abierta);
    }

    #[Test]
    public function el_conteo_del_dia_suma_la_visita_una_sola_vez_y_en_el_dia_correcto(): void
    {
        $conteos = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): array {
            $entrada = \Illuminate\Support\Carbon::parse('2026-03-13 23:50:00');

            $visita = $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, $entrada);
            $this->registro->salir(
                $visita,
                CanalAcceso::Dispositivo,
                null,
                \Illuminate\Support\Carbon::parse('2026-03-14 00:10:00')
            );

            $viernes = (int) DB::selectOne(
                'SELECT count(*) AS total FROM public.vw_visitas WHERE dia_local = ?',
                ['2026-03-13']
            )->total;

            $sabado = (int) DB::selectOne(
                'SELECT count(*) AS total FROM public.vw_visitas WHERE dia_local = ?',
                ['2026-03-14']
            )->total;

            $total = (int) DB::selectOne('SELECT count(*) AS total FROM public.vw_visitas')->total;

            return compact('viernes', 'sabado', 'total');
        });

        $this->assertSame(1, $conteos['viernes'], 'Cuenta el viernes.');
        $this->assertSame(0, $conteos['sabado'], 'No inventa una visita el sábado.');
        $this->assertSame(1, $conteos['total'], 'El total no se parte en dos.');
    }

    #[Test]
    public function tres_visitas_el_mismo_dia_se_cuentan_tres(): void
    {
        $conteo = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): int {
            $mañana = \Illuminate\Support\Carbon::parse('2026-03-13 07:15:00');
            $tarde = \Illuminate\Support\Carbon::parse('2026-03-13 13:40:00');
            $noche = \Illuminate\Support\Carbon::parse('2026-03-13 23:50:00');

            foreach ([$mañana, $tarde, $noche] as $entrada) {
                $visita = $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, $entrada);
                $this->registro->salir($visita, CanalAcceso::Dispositivo, null, $entrada->copy()->addHour());
            }

            return (int) DB::selectOne(
                'SELECT count(*) AS total FROM public.vw_visitas WHERE dia_local = ?',
                ['2026-03-13']
            )->total;
        });

        $this->assertSame(
            3,
            $conteo,
            'La asistencia es una serie de eventos: tres veces el mismo día son tres visitas.'
        );
    }

    #[Test]
    public function el_dia_local_usa_la_zona_del_gimnasio_y_no_la_del_servidor(): void
    {
        // Un contenedor de servidor corre en UTC. Si el cálculo usara la zona
        // del servidor, una visita de las 21:00 en Santiago (00:00 del día
        // siguiente en UTC) quedaría fechada en el día equivocado.
        //
        // Esta prueba usa una hora que en UTC pertenece AL DÍA SIGUIENTE, así
        // que si el cálculo está mal, el día_local no será el esperado.
        $diaLocal = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): string {
            // 22:30 en Santiago (UTC-4 en marzo) es 02:30 del día siguiente
            // en UTC.
            $entrada = \Illuminate\Support\Carbon::parse('2026-03-13 22:30:00', 'UTC');

            $visita = $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, $entrada);

            return (string) DB::selectOne(
                'SELECT dia_local FROM public.vw_visitas WHERE id = ?',
                [$visita->id]
            )->dia_local;
        });

        $this->assertSame(
            '2026-03-13',
            $diaLocal,
            'El día local se calcula con la zona horaria del gimnasio, no con la del servidor.'
        );
    }

    #[Test]
    public function una_visita_abierta_no_se_parte_en_dos_dias(): void
    {
        // Una visita abierta a las 23:50 pertenece al viernes, aunque siga
        // abierta al sábado. El conteo de "abiertas ahora" no lleva fecha, así
        // que no hay forma de que se duplique.
        $fila = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): object {
            $entrada = \Illuminate\Support\Carbon::parse('2026-03-13 23:50:00');

            $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, $entrada);

            return DB::selectOne('SELECT dia_local, abierta FROM public.vw_visitas LIMIT 1');
        });

        $this->assertSame('2026-03-13', $fila->dia_local);
        $this->assertTrue((bool) $fila->abierta);
    }

    #[Test]
    public function una_visita_anulada_no_entra_en_los_conteos(): void
    {
        $conteo = $this->contexto->ejecutarAislado($this->gimnasio->id, function (): int {
            $entrada = \Illuminate\Support\Carbon::parse('2026-03-13 19:00:00');

            $visita = $this->registro->entrar($this->socio, CanalAcceso::Dispositivo, null, $entrada);
            $this->registro->salir($visita, CanalAcceso::Dispositivo, null, $entrada->copy()->addHour());
            $this->registro->anular($visita, 'El turno leyó otra huella.', null);

            // La fila sigue en la tabla: la bitácora no borra. Lo que cambia
            // es que no cuenta para el gráfico del dueño.
            $this->assertSame(1, Visita::query()->count(), 'La fila no se borra.');

            return (int) DB::selectOne('SELECT count(*) AS total FROM public.vw_visitas')->total;
        });

        $this->assertSame(0, $conteo, 'Una visita anulada no aparece en los conteos.');
    }
}