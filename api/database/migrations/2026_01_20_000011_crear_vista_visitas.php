<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista de visitas con el día local y la duración ya calculados.
 *
 * DOS REGLAS QUE VIVEN AQUÍ, NO EN CADA CONSULTA
 * ----------------------------------------------
 *
 * 1. LA VISITA PERTENECE AL DÍA DE LA ENTRADA.
 *    Un socio que entra a las 23:50 y sale a las 00:10 hizo UNA visita, la
 *    del día de la entrada. No se parte en dos, y no se cuenta como dos
 *    visitas. Aquí eso se resuelve una vez, con
 *    `(entrada_en AT TIME ZONE t.zona_horaria)::date`, y todas las consultas
 *    que arman un gráfico o un conteo diario usan la misma columna.
 *
 * 2. LA ZONA HORARIA ES DEL GIMNASIO, NO DEL SERVIDOR.
 *    `AT TIME ZONE` sobre una columna `timestamptz` devuelve un `timestamp`
 *    sin zona, ya convertido al huso indicado. Si se usara la zona del
 *    servidor (que en un contenedor es UTC), todas las visitas de la tarde
 *    quedarían fechadas en el día siguiente.
 *
 * POR QUÉ `security_invoker = true`
 * ---------------------------------
 * Una vista de PostgreSQL se ejecuta por defecto con los permisos de QUIÉN
 * LA DEFINIÓ, no con los de quien consulta. Si esta vista se creara sin más,
 * leería `visitas` como su dueño y la Row Level Security de la tabla NO se
 * aplicaría: el panel de un gimnasio podría devolver visitas de otro sin que
 * nada lo note.
 *
 * `security_invoker = true` hace que la vista se ejecute con los permisos de
 * el usuario que consulta, y entonces la RLS sí manda. Está disponible desde
 * PostgreSQL 15; el despliegue usa 18. Ver docs/03, sección 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE VIEW public.vw_visitas
            WITH (security_invoker = true)
            AS
            SELECT
                v.id,
                v.tenant_id,
                v.socio_id,
                v.entrada_en,
                v.salida_en,
                v.canal,
                v.cerrada_por_sistema,
                v.anulada,
                -- El día de la visita es el día de la ENTRADA, en la zona
                -- horaria del gimnasio. Ver cabecera del archivo.
                (v.entrada_en AT TIME ZONE t.zona_horaria)::date AS dia_local,
                t.zona_horaria,
                -- Minutos dentro. Una visita abierta cuenta hasta ahora.
                CASE
                    WHEN v.salida_en IS NULL
                        THEN EXTRACT(EPOCH FROM (now() - v.entrada_en)) / 60
                    ELSE EXTRACT(EPOCH FROM (v.salida_en - v.entrada_en)) / 60
                END::integer AS duracion_minutos,
                (v.salida_en IS NULL) AS abierta
            FROM public.visitas v
            JOIN public.tenants t ON t.id = v.tenant_id
            WHERE v.anulada = false
        SQL);

        DB::unprepared(<<<'SQL'
            COMMENT ON VIEW public.vw_visitas IS
                'Visitas sin anular con el dia local del gimnasio y la duracion calculada. security_invoker para que la RLS siga aplicando.'
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS public.vw_visitas');
    }
};