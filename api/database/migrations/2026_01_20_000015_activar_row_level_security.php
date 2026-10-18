<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AISLAMIENTO ENTRE GIMNASIOS: Row Level Security (RLS) de PostgreSQL.
 *
 * Esta es la MISMA migración, con el mismo mecanismo, que la del Portal de
 * Autoservicio. Ver apps/portal-autoservicio/docs/03-MULTITENANCY.md y
 * apps/portal-autoservicio/api/database/migrations/2026_01_05_000009_activar_row_level_security.php.
 *
 * No se inventó un cuarto enfoque para este producto: el problema es
 * exactamente el mismo (una base, varios clientes, datos que no se pueden
 * cruzar) y el patrón ya está documentado y probado.
 *
 * MECANISMO
 * ---------
 * 1. `public.app_tenant_id()` lee el valor de la variable de sesión
 *    `app.tenant_id`. Si no está definida, LANZA un error. No devuelve NULL ni
 *    cadena vacía: falla de forma ruidosa, que es lo que importa.
 * 2. Cada tabla de negocio tiene una política que exige
 *    `tenant_id = app_tenant_id()`, al leer (USING) y al escribir
 *    (WITH CHECK).
 * 3. `FORCE ROW LEVEL SECURITY` hace que la política aplique también al
 *    dueño de la tabla. Sin FORCE, el dueño la saltaría, y en desarrollo el
 *    rol de la aplicación suele ser el mismo que creó las tablas.
 * 4. La variable se fija con `set_config('app.tenant_id', ..., true)`, con
 *    alcance de TRANSACCIÓN. Al confirmar o revertir, PostgreSQL la descarta
 *    sola, y el worker de colas no puede arrastrar el gimnasio de otro
 *    trabajo.
 *
 * REQUISITO DE DESPLIEGUE: el rol que usa la aplicación NO puede ser
 * superusuario ni tener BYPASSRLS, porque con cualquiera de las dos cosas
 * PostgreSQL ignora las políticas por completo. El comando
 * `php artisan gimnasio:verificar-aislamiento` lo comprueba.
 */
return new class extends Migration
{
    /**
     * Tablas con datos de negocio, y por lo tanto con RLS.
     *
     * @var list<string>
     */
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

    public function up(): void
    {
        // 1. Funcion de contexto. SECURITY INVOKER (lo normal): no necesita
        //    privilegios propios.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid
            LANGUAGE plpgsql
            STABLE
            AS $$
            DECLARE
                valor text;
            BEGIN
                valor := current_setting('app.tenant_id', true);

                IF valor IS NULL OR valor = '' THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'insufficient_privilege',
                        MESSAGE = 'No hay contexto de gimnasio en la conexion (app.tenant_id no esta definida). Se aborta la operacion para no exponer datos de otro gimnasio.',
                        HINT = 'La peticion no paso por App\Http\Middleware\ResolverTenant, o la operacion se ejecuto fuera de una transaccion.';
                END IF;

                RETURN valor::uuid;
            END
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON FUNCTION public.app_tenant_id() IS
                'Devuelve el gimnasio de la peticion. Lanza excepcion si no hay contexto: el aislamiento falla cerrado.';
        SQL);

        // 2. Politicas y activacion por tabla.
        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne(
                'SELECT to_regclass(?) AS tabla',
                ['public.' . $tabla]
            );

            // to_regclass devuelve NULL si la tabla todavia no existe.
            if ($existe === null || $existe->tabla === null) {
                continue;
            }

            DB::unprepared('ALTER TABLE public.' . $tabla . ' ENABLE ROW LEVEL SECURITY');

            // FORCE hace que la politica aplique tambien al dueno.
            DB::unprepared('ALTER TABLE public.' . $tabla . ' FORCE ROW LEVEL SECURITY');

            $nombre = 'politica_aislamiento_' . $tabla;

            DB::unprepared(<<<SQL
                DROP POLICY IF EXISTS {$nombre} ON public.{$tabla};

                CREATE POLICY {$nombre} ON public.{$tabla}
                    FOR ALL
                    TO PUBLIC
                    USING (tenant_id = public.app_tenant_id())
                    WITH CHECK (tenant_id = public.app_tenant_id());
            SQL);
        }

        // 3. Las tablas de infraestructura de Laravel (jobs fallidos, cache,
        //    sesiones) no llevan tenant_id y por eso no se incluyen arriba.
        //    Si alguien las agrega a la lista sin columna tenant_id, la
        //    migracion falla de forma explicita en vez de dejar una tabla de
        //    datos de negocio sin proteccion.
        $this->verificarColumnaTenant();

        // 4. La vista de visitas DEBE seguir respetando la RLS. Una vista de
        //    PostgreSQL se ejecuta por defecto con los permisos de quien la
        //    definió, y entonces leería `visitas` como su dueño. Esta
        //    migración falla ruidosamente si a alguien se le olvidó el
        //    `security_invoker = true` al crearla, porque la vista quedaría
        //    abriendo una puerta por la tabla más sensible del sistema.
        $this->verificarSeguridadDeLaVista();
    }

    public function down(): void
    {
        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne('SELECT to_regclass(?) AS tabla', ['public.' . $tabla]);

            if ($existe === null || $existe->tabla === null) {
                continue;
            }

            DB::unprepared('DROP POLICY IF EXISTS politica_aislamiento_' . $tabla . ' ON public.' . $tabla);
            DB::unprepared('ALTER TABLE public.' . $tabla . ' NO FORCE ROW LEVEL SECURITY');
            DB::unprepared('ALTER TABLE public.' . $tabla . ' DISABLE ROW LEVEL SECURITY');
        }

        DB::unprepared('DROP FUNCTION IF EXISTS public.app_tenant_id()');
    }

    /**
     * Falla ruidosamente si alguna tabla de la lista no tiene `tenant_id`.
     *
     * @throws RuntimeException
     */
    private function verificarColumnaTenant(): void
    {
        foreach ($this->tablasProtegidas as $tabla) {
            $existe = DB::selectOne(
                'SELECT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = ''public'' AND table_name = ? AND column_name = ''tenant_id''
                 ) AS tiene_columna',
                [$tabla]
            );

            if ($existe === null || ! $existe->tiene_columna) {
                throw new RuntimeException(
                    'La tabla public.' . $tabla . ' no tiene columna tenant_id y no puede protegerse con RLS. '
                    . 'Revise la migracion antes de continuar.'
                );
            }
        }
    }

    /**
     * La vista de visitas tiene que ejecutarse con los permisos de quien
     * consulta, no con los de su dueño.
     *
     * @throws RuntimeException
     */
    private function verificarSeguridadDeLaVista(): void
    {
        $estado = DB::selectOne(
            "SELECT c.reloptions
             FROM pg_class c
             WHERE c.oid = to_regclass('public.vw_visitas')"
        );

        if ($estado === null || $estado->reloptions === null) {
            throw new RuntimeException(
                'No existe la vista public.vw_visitas. Sin ella, los dias locales y las duraciones '
                . 'se calcularian en cada consulta y cada una podria equivocarse.'
            );
        }

        $opciones = is_array($estado->reloptions)
            ? $estado->reloptions
            : [$estado->reloptions];

        $segura = false;

        foreach ($opciones as $opcion) {
            if (is_string($opcion) && str_contains($opcion, 'security_invoker=true')) {
                $segura = true;
            }
        }

        if (! $segura) {
            throw new RuntimeException(
                'La vista public.vw_visitas NO tiene security_invoker=true. Sin esa opcion lee '
                . 'visitas con los permisos de su dueno y la Row Level Security no se aplica: '
                . 'un gimnasio podria ver las visitas de otro.'
            );
        }
    }
};