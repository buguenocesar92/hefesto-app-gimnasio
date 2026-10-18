<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista pública de gimnasios.
 *
 * `tenants` no lleva RLS porque se consulta en el inicio de sesión, cuando
 * todavía no hay contexto de gimnasio. Esa excepción necesita una
 * compensación, y la compensación es esta vista: ANTES del login la
 * aplicación lee acá, no la tabla cruda.
 *
 * Expone TRES columnas: `slug`, `nombre` y `activo`. No expone razón social,
 * RUT, ni correos ni teléfonos. Con eso alcanza para armar el selector de
 * gimnasio en la pantalla de entrada, y no alcanza para sacar información
 * que sirva para algo.
 *
 * Riesgo residual, dicho con todas las letras: un `slug` se puede enumerar
 * sin autenticarse, porque aparece en la URL de entrada y en los enlaces que
 * el gimnasio reparte. Un atacante puede descubrir que el Gimnasio Fuerza
 * usa la plataforma. No puede ver más que el nombre. Queda anotado como
 * decisión pendiente del cliente en docs/03.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW public.vw_gimnasios_publicos AS
            SELECT
                t.slug,
                t.nombre,
                t.activo
            FROM public.tenants t
            WHERE t.activo = true
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON VIEW public.vw_gimnasios_publicos IS
                'Gimnasios activos para la pantalla de entrada. Expone solo slug, nombre y activo: no datos de contacto.';
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS public.vw_gimnasios_publicos');
    }
};