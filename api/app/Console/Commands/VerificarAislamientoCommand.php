<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tenancy\ContextoGimnasio;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Comprueba que el aislamiento entre gimnasios esté realmente puesto.
 *
 * POR QUÉ ESTE COMANDO EXISTE
 * ---------------------------
 * El aislamiento no es solo el código que escribimos: es el ESTADO en que
 * quedó la base. Un despliegue bien hecho pero mal configurado (un rol con
 * BYPASSRLS, una política borrada a mano, una tabla nueva sin proteger)
 * deja la aplicación funcionando y, al mismo tiempo, completamente abierta
 * abierta entre dos gimnasios distintos. Nada en la interfaz lo delata.
 *
 * Por eso se corre en el despliegue y antes de dar por buena una migración.
 * Devuelve código de salida 1 si algo falla.
 *
 *   php artisan gimnasio:verificar-aislamiento
 */
class VerificarAislamientoCommand extends Command
{
    /** @var list<string> */
    private array $tablasProtegidas = [
        'usuarios',
        'planes',
        'socios',
        'membresias',
        'cobros',
        'periodos_sin_cobro',
        'visitas',
        'eventos_acceso',
        'ingresos_invitados',
        'rutinas',
        'registros_rutina',
        'avisos_abandono',
    ];

    protected $signature = 'gimnasio:verificar-aislamiento';

    protected $description = 'Comprueba que la RLS, el rol de base de datos y el contexto de gimnasio protejan los datos de cada gimnasio.';

    public function handle(): int
    {
        $fallos = [];

        $fallos = array_merge($fallos, $this->revisarRol());
        $fallos = array_merge($fallos, $this->revisarTablas());
        $fallos = array_merge($fallos, $this->revisarIndiceDeVisitaAbierta());
        $fallos = array_merge($fallos, $this->revisarVistaDeVisitas());
        $fallos = array_merge($fallos, $this->revisarFuncionDeContexto());
        $fallos = array_merge($fallos, $this->revisarLecturaAislada());
        $fallos = array_merge($fallos, $this->revisarVistaPublica());

        if ($fallos !== []) {
            $this->error('El aislamiento entre gimnasios NO está bien puesto:');

            foreach ($fallos as $fallo) {
                $this->line('  - ' . $fallo);
            }

            $this->line('');
            $this->line('No sigas con el despliegue. Revisa la migración');
            $this->line('database/migrations/2026_01_20_000015_activar_row_level_security.php');

            return self::FAILURE;
        }

        $this->info('Aislamiento entre gimnasios verificado correctamente.');

        return self::SUCCESS;
    }

    /**
     * El rol de la aplicación no puede ser superusuario ni tener BYPASSRLS.
     *
     * @return list<string>
     */
    private function revisarRol(): array
    {
        $fila = DB::selectOne(
            'SELECT r.rolname AS nombre, r.rolsuper AS superusuario, r.rolbypassrls AS salta_rls
             FROM pg_roles r
             WHERE r.rolname = current_user'
        );

        if ($fila === null) {
            return ['No se pudo identificar el rol de la aplicación en PostgreSQL.'];
        }

        $fallos = [];

        if ((bool) $fila->superusuario) {
            $fallos[] = sprintf('El rol "%s" es SUPERUSUARIO: ignora todas las políticas RLS.', $fila->nombre);
        }

        if ((bool) $fila->salta_rls) {
            $fallos[] = sprintf('El rol "%s" tiene BYPASSRLS: ignora todas las políticas RLS.', $fila->nombre);
        }

        if ($fallos === []) {
            $this->line('  [ok] Rol "' . $fila->nombre . '": sin superusuario y sin BYPASSRLS.');
        }

        return $fallos;
    }

    /**
     * @return list<string>
     */
    private function revisarTablas(): array
    {
        $fallos = [];

        foreach ($this->tablasProtegidas as $tabla) {
            $estado = DB::selectOne(
                'SELECT c.relrowsecurity AS rls, c.relforcerowsecurity AS forzada
                   FROM pg_class c
                  WHERE c.oid = to_regclass(?)',
                ['public.' . $tabla]
            );

            if ($estado === null) {
                $fallos[] = 'La tabla public.' . $tabla . ' no existe. Faltó correr las migraciones.';

                continue;
            }

            if (! $estado->rls) {
                $fallos[] = 'La tabla public.' . $tabla . ' NO tiene Row Level Security activada.';

                continue;
            }

            if (! $estado->forzada) {
                $fallos[] = 'La tabla public.' . $tabla . ' tiene RLS pero NO está forzada '
                    . '(FORCE ROW LEVEL SECURITY): el dueño de la tabla saltaría la política.';

                continue;
            }

            $politicas = DB::selectOne(
                'SELECT count(*) AS total FROM pg_policies WHERE schemaname = ? AND tablename = ?',
                ['public', $tabla]
            );

            if ((int) ($politicas->total ?? 0) === 0) {
                $fallos[] = 'La tabla public.' . $tabla . ' tiene RLS activada pero no tiene ninguna política.';
            }
        }

        if ($fallos === []) {
            $this->line('  [ok] ' . count($this->tablasProtegidas) . ' tablas con RLS activada, forzada y con política.');
        }

        return $fallos;
    }

    /**
     * El índice único parcial de visita abierta tiene que existir.
     *
     * Es la garantía de que un socio no puede tener dos visitas abiertas, y
     * vive en la base. Si alguien lo borra a mano, la aplicación seguiría
     * funcionando y empezaría a haber socios con dos entradas abiertas.
     *
     * @return list<string>
     */
    private function revisarIndiceDeVisitaAbierta(): array
    {
        $existe = DB::selectOne(
            'SELECT count(*) AS total
               FROM pg_indexes
              WHERE schemaname = ? AND indexname = ?',
            ['public', 'visitas_una_abierta_por_socio']
        );

        if ((int) ($existe->total ?? 0) === 0) {
            return [
                'No existe el índice public.visitas_una_abierta_por_socio. '
                . 'Sin él, un socio puede tener dos visitas abiertas a la vez.',
            ];
        }

        $this->line('  [ok] El índice de visita abierta por socio existe.');

        return [];
    }

    /**
     * La vista de visitas tiene que ejecutarse con los permisos de quien
     * consulta, no con los de su dueño.
     *
     * @return list<string>
     */
    private function revisarVistaDeVisitas(): array
    {
        $estado = DB::selectOne(
            'SELECT c.reloptions FROM pg_class c WHERE c.oid = to_regclass(?)',
            ['public.vw_visitas']
        );

        if ($estado === null || $estado->reloptions === null) {
            return ['No existe la vista public.vw_visitas.'];
        }

        $opciones = is_array($estado->reloptions) ? $estado->reloptions : [$estado->reloptions];
        $segura = false;

        foreach ($opciones as $opcion) {
            if (is_string($opcion) && str_contains($opcion, 'security_invoker=true')) {
                $segura = true;
            }
        }

        if (! $segura) {
            return [
                'La vista public.vw_visitas NO tiene security_invoker=true. Leería "visitas" '
                . 'con los permisos de su dueño y la RLS no se aplicaría.',
            ];
        }

        $this->line('  [ok] La vista de visitas se ejecuta con los permisos de quien consulta.');

        return [];
    }

    /**
     * Sin contexto, la función tiene que FALLAR. Falla cerrado.
     *
     * @return list<string>
     */
    private function revisarFuncionDeContexto(): array
    {
        try {
            DB::select('SELECT public.app_tenant_id()');
        } catch (QueryException) {
            $this->line('  [ok] public.app_tenant_id() falla sin contexto de gimnasio (aislamiento cerrado).');

            return [];
        }

        return [
            'public.app_tenant_id() devolvió un valor sin que haya contexto de gimnasio. '
            . 'Debería lanzar excepción para que el aislamiento falle cerrado.',
        ];
    }

    /**
     * @return list<string>
     */
    private function revisarLecturaAislada(): array
    {
        $gimnasioInexistente = (string) Str::uuid();
        $contexto = new ContextoGimnasio();

        try {
            $conteo = $contexto->ejecutarAislado(
                $gimnasioInexistente,
                fn (): int => DB::table('visitas')->count()
            );
        } catch (QueryException $excepcion) {
            return ['No se pudo leer "visitas" con un contexto de gimnasio: ' . $excepcion->getMessage()];
        }

        $fallos = [];

        if ($conteo !== 0) {
            $fallos[] = 'La tabla "visitas" devolvió ' . $conteo . ' filas para un gimnasio inexistente.';
        }

        $residuo = $contexto->gimnasioIdEnConexion();

        if ($residuo !== null) {
            $fallos[] = 'La variable app.tenant_id quedó fijada (' . $residuo . ') después de la transacción. '
                . 'Si queda colgando, el worker de colas podría leer datos de otro gimnasio.';
        }

        if ($fallos === []) {
            $this->line('  [ok] Con un gimnasio inexistente no se lee ninguna fila y el contexto no queda colgando.');
        }

        return $fallos;
    }

    /**
     * @return list<string>
     */
    private function revisarVistaPublica(): array
    {
        $columnas = DB::select(
            "SELECT column_name
               FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = 'vw_gimnasios_publicos'
              ORDER BY column_name"
        );

        if ($columnas === []) {
            return ['No existe la vista public.vw_gimnasios_publicos.'];
        }

        $nombres = array_map(static fn (object $fila): string => (string) $fila->column_name, $columnas);
        sort($nombres);

        $esperadas = ['activo', 'nombre', 'slug'];
        sort($esperadas);

        if ($nombres !== $esperadas) {
            return ['La vista public.vw_gimnasios_publicos expone columnas distintas de las tres esperadas ('
                . implode(', ', $nombres) . '). No debe exponer datos de contacto.'];
        }

        $this->line('  [ok] La vista pública de gimnasios expone solo slug, nombre y activo.');

        return [];
    }
}