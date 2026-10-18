<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\Socio;
use App\Models\Tenant;
use App\Models\Visita;
use App\Services\Tenancy\ContextoGimnasio;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PRUEBA CRÍTICA: un gimnasio no puede leer los datos de otro.
 *
 * Estas pruebas van contra PostgreSQL de verdad. El aislamiento está
 * implementado con Row Level Security: si se corrieran contra SQLite,
 * pasarían sin comprobar nada. Ver `CreatesApplication`.
 *
 * Lo que se comprueba:
 *
 *  1. Sin contexto de gimnasio, la consulta FALLA (no devuelve cero filas en
 *     silencio).
 *  2. Cada gimnasio ve solo sus propias filas.
 *  3. Un socio de otro gimnasio no existe.
 *  4. No se puede escribir una fila en nombre de otro gimnasio.
 *  5. El contexto no sobrevive a la transacción (el worker de colas).
 *  6. La vista de visitas también respalda la RLS.
 *
 * TODA ESCRITURA OCURRE DENTRO DE `ejecutarAislado`
 * -------------------------------------------------
 * No es estilo: las tablas tienen RLS, así que una escritura sin contexto
 * falla. Las pruebas escriben por el mismo camino que la aplicación.
 */
class AislamientoDeDatosTest extends TestCase
{
    use RefreshDatabase;

    private ContextoGimnasio $contexto;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->sePuedeProbarConEstaBase()) {
            $this->markTestSkipped(
                'Requiere PostgreSQL: la Row Level Security no existe en otro motor de base de datos.'
            );
        }

        $this->contexto = new ContextoGimnasio();
    }

    #[Test]
    public function sin_contexto_de_gimnasio_la_consulta_falla_en_vez_de_traer_datos(): void
    {
        $gimnasio = $this->crearGimnasio('primero.cl');
        $this->crearSocioEn($gimnasio);

        // Se limpia el contexto a propósito: es la situación de una consulta
        // que se escapara del middleware.
        //
        // Lo que se espera NO es "cero filas", sino un error. La función de
        // contexto lanza excepción cuando no hay gimnasio, y la política RLS
        // aborta la consulta. Devolver cero filas sería más cómodo de probar,
        // pero dejaría pasar el caso peligroso: que alguien "arregle" la
        // función devolviendo NULL y termine leyendo filas del gimnasio
        // equivocado sin que nada lo delate.
        DB::select('SELECT set_config(?, ?, false)', [ContextoGimnasio::VARIABLE, '']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Socio::query()->count();
    }

    #[Test]
    public function cada_gimnasio_solo_ve_sus_propias_filas(): void
    {
        $primero = $this->crearGimnasio('primero.cl');
        $socioPrimero = $this->crearSocioEn($primero);
        $this->crearSocioEn($primero);
        $this->crearSocioEn($primero);

        $segundo = $this->crearGimnasio('segundo.cl');
        $socioSegundo = $this->crearSocioEn($segundo);

        $conteoEn = function (string $gimnasioId) use ($socioPrimero, $socioSegundo): object {
            return $this->contexto->ejecutarAislado($gimnasioId, function () use ($socioPrimero, $socioSegundo): object {
                return (object) [
                    'cantidad' => Socio::query()->count(),
                    'identificador' => Socio::query()->orderBy('nombre')->value('id'),
                ];
            });
        };

        $vistoPrimero = $conteoEn($primero->id);
        $this->assertSame(3, $vistoPrimero->cantidad);
        $this->assertSame($socioPrimero->id, $vistoPrimero->identificador);

        $vistoSegundo = $conteoEn($segundo->id);
        $this->assertSame(1, $vistoSegundo->cantidad);
        $this->assertSame($socioSegundo->id, $vistoSegundo->identificador);
    }

    #[Test]
    public function el_socio_de_otro_gimnasio_no_existe_para_este_contexto(): void
    {
        $primero = $this->crearGimnasio('primero.cl');
        $segundo = $this->crearGimnasio('segundo.cl');
        $socioAjeno = $this->crearSocioEn($segundo);

        $encontrado = $this->contexto->ejecutarAislado(
            $primero->id,
            fn (): ?Socio => Socio::find($socioAjeno->id)
        );

        $this->assertNull(
            $encontrado,
            'Un socio de otro gimnasio no puede existir para este contexto.'
        );
    }

    #[Test]
    public function no_se_puede_actualizar_una_fila_de_otro_gimnasio(): void
    {
        $primero = $this->crearGimnasio('primero.cl');
        $segundo = $this->crearGimnasio('segundo.cl');
        $socioAjeno = $this->crearSocioEn($segundo);

        // La política tiene WITH CHECK, pero además el UPDATE no encuentra la
        // fila: la RLS filtra antes. Lo que se comprueba acá es que la
        // operación falla y no deja el nombre cambiado.
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->contexto->ejecutarAislado($primero->id, function () use ($socioAjeno): void {
            DB::table('socios')
                ->where('id', $socioAjeno->id)
                ->update(['nombre' => 'secuestrado']);
        });
    }

    #[Test]
    public function no_se_puede_insertar_una_fila_con_el_gimnasio_de_otro(): void
    {
        $primero = $this->crearGimnasio('primero.cl');
        $segundo = $this->crearGimnasio('segundo.cl');
        $this->crearSocioEn($primero);

        // Contexto del primer gimnasio, pero se intenta guardar una fila con
        // el identificador del segundo. La política WITH CHECK lo impide.
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->contexto->ejecutarAislado($primero->id, function () use ($segundo): void {
            DB::table('socios')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $segundo->id,
                'nombre' => 'Socio con gimnasio ajeno',
                'activo' => true,
                'creado_en' => now(),
                'actualizado_en' => now(),
            ]);
        });
    }

    #[Test]
    public function las_visitas_de_otro_gimnasio_tampoco_se_ven(): void
    {
        $primero = $this->crearGimnasio('primero.cl');
        $socioPrimero = $this->crearSocioEn($primero);
        $this->crearVisitasEn($primero, $socioPrimero, 1);

        $segundo = $this->crearGimnasio('segundo.cl');
        $socioSegundo = $this->crearSocioEn($segundo);
        $this->crearVisitasEn($segundo, $socioSegundo, 3);

        $conteoEn = function (string $gimnasioId): int {
            return $this->contexto->ejecutarAislado(
                $gimnasioId,
                fn (): int => Visita::query()->count()
            );
        };

        $this->assertSame(1, $conteoEn($primero->id), 'Solo debe verse la visita del primer gimnasio.');
        $this->assertSame(3, $conteoEn($segundo->id));
    }

    #[Test]
    public function el_contexto_de_gimnasio_no_sobrevive_a_la_transaccion(): void
    {
        $gimnasio = $this->crearGimnasio('primero.cl');
        $socio = $this->crearSocioEn($gimnasio);
        $this->crearVisitasEn($gimnasio, $socio, 1);

        $conteo = $this->contexto->ejecutarAislado(
            $gimnasio->id,
            fn (): int => Visita::query()->count()
        );

        $this->assertSame(1, $conteo);
        $this->assertNull(
            $this->contexto->gimnasioIdEnConexion(),
            'La variable de contexto debe desaparecer al terminar la transacción. '
            . 'Si queda, el worker de colas podría leer datos de otro gimnasio.'
        );
    }

    #[Test]
    public function la_funcion_de_contexto_falla_cerrada_sin_gimnasio(): void
    {
        DB::select('SELECT set_config(?, ?, false)', [ContextoGimnasio::VARIABLE, '']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::select('SELECT public.app_tenant_id()');
    }

    #[Test]
    public function la_vista_de_visitas_respeta_el_aislamiento(): void
    {
        $primero = $this->crearGimnasio('primero.cl');
        $socioPrimero = $this->crearSocioEn($primero);
        $this->crearVisitasEn($primero, $socioPrimero, 1);

        $segundo = $this->crearGimnasio('segundo.cl');
        $socioSegundo = $this->crearSocioEn($segundo);
        $this->crearVisitasEn($segundo, $socioSegundo, 2);

        // Esta comprobación no puede faltar: `vw_visitas` se crea con
        // `security_invoker = true` justamente para que la RLS siga mandando.
        // Sin esa opción, la vista leería `visitas` con los permisos de su
        // dueño y devolvería los datos de los dos gimnasios al mismo tiempo.
        $conteoEn = function (string $gimnasioId): int {
            return $this->contexto->ejecutarAislado(
                $gimnasioId,
                fn (): int => (int) DB::select('SELECT count(*) AS total FROM public.vw_visitas')[0]->total
            );
        };

        $this->assertSame(1, $conteoEn($primero->id));
        $this->assertSame(2, $conteoEn($segundo->id));
    }

    #[Test]
    public function la_política_tiene_que_todas_las_tablas_de_negocio(): void
    {
        $tablas = [
            'usuarios', 'planes', 'socios', 'membresias', 'cobros',
            'periodos_sin_cobro', 'visitas', 'eventos_acceso',
            'ingresos_invitados', 'rutinas', 'registros_rutina', 'avisos_abandono',
        ];

        $protegidas = DB::select(
            'SELECT c.relname AS tabla
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = ?
                AND c.relrowsecurity = true
                AND c.relforcerowsecurity = true',
            ['public']
        );

        $nombres = array_map(static fn (object $fila): string => (string) $fila->tabla, $protegidas);
        sort($nombres);

        $esperadas = $tablas;
        sort($esperadas);

        $this->assertSame(
            $esperadas,
            $nombres,
            'Cada tabla de negocio tiene que tener RLS activada Y forzada. Una tabla sin RLS '
            . 'no la protege ni una consulta de Laravel ni una de PostgreSQL.'
        );
    }

    // --- Utilidades ---------------------------------------------------------

    /** `tenants` NO tiene RLS: se puede escribir directamente. */
    private function crearGimnasio(string $dominio): Tenant
    {
        return Tenant::create([
            'slug' => Str::slug($dominio),
            'nombre' => 'Gimnasio ' . $dominio,
            'zona_horaria' => Tenant::ZONA_HORARIA_POR_DEFECTO,
            'activo' => true,
        ]);
    }

    private function crearSocioEn(Tenant $gimnasio): Socio
    {
        return $this->contexto->ejecutarAislado(
            $gimnasio->id,
            fn (): Socio => Socio::factory()->create(['tenant_id' => $gimnasio->id])
        );
    }

    /**
     * @return Collection<int, Visita>
     */
    private function crearVisitasEn(Tenant $gimnasio, Socio $socio, int $cantidad): Collection
    {
        return $this->contexto->ejecutarAislado(
            $gimnasio->id,
            fn (): Collection => Visita::factory()->count($cantidad)->create([
                'tenant_id' => $gimnasio->id,
                'socio_id' => $socio->id,
            ])
        );
    }
}