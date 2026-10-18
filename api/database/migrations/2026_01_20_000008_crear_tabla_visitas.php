<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Visitas: una fila por cada vez que una persona estuvo adentro.
 *
 * POR QUÉ NO HAY UNA COLUMNA "DENTRO/FUERA"
 * ------------------------------------------
 * Porque la asistencia no es un estado, es una serie. Un socio puede venir
 * tres veces en un día y el sistema tiene que poder contar las tres. Guardar
 * un estado obliga a decidir qué pasa con la segunda entrada del mismo día, y
 * cualquier respuesta que se elija pierde información.
 *
 * LA REGLA DE UNA VISITA ABIERTA A LA VEZ
 * ---------------------------------------
 * Un socio no puede tener dos visitas simultáneas abiertas. Esa regla está en
 * UN ÍNDICE ÚNICO PARCIAL, no en el código:
 *
 *     UNIQUE (socio_id) WHERE salida_en IS NULL
 *
 * El índice parcial de PostgreSQL solo considera las filas que cumplen el
 * `WHERE`, así que solo choca entre visitas abiertas. Consecuencia: el socio
 * puede tener todas las visitas cerradas que quiera, y el día que alguien
 * intente registrar una segunda entrada sin haber cerrado la anterior, la
 * base lo rechaza.
 *
 * ¿POR QUÉ EN LA BASE Y NO EN PHP?
 * Porque esta es la operación que se hace con dos personas picando entrada a
 * la vez, un viernes a las 19:00, cuando recepción está a full. Una regla que
 * depende de que el código esté bien escrito se cae justo en ese momento, y
 * además se cae en silencio: el turno queda con dos visitas abiertas y nadie
 * se entera hasta que el conteo del mes no cuadra.
 *
 * Un socio puede tener como máximo UNA fila con `salida_en IS NULL` y que no
 * esté anulada. El índice no distingue anuladas porque una visita anulada se
 * marca con `anulada = true` y se le pone igual la salida; así una anulación
 * libera el índice. Ver `RegistroVisita::anular()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->foreignUuid('socio_id')->constrained('socios')->cascadeOnDelete();

            $tabla->timestampTz('entrada_en');
            $tabla->timestampTz('salida_en')->nullable();

            // Por dónde entró. `manual` además exige `registrado_por` y
            // `motivo_manual`: los cortes de energía y los fallos del torno se
            // anotan a mano y tienen que quedar marcados como tales.
            $tabla->string('canal', 20)->default('dispositivo');
            $tabla->string('dispositivo', 80)->nullable();
            $tabla->foreignUuid('registrado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->string('motivo_manual', 200)->nullable();

            // Una visita abierta sin salida NO es un dato faltante: es un caso.
            $tabla->boolean('cerrada_por_sistema')->default(false);
            $tabla->boolean('anulada')->default(false);
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'entrada_en']);
            $tabla->index(['tenant_id', 'socio_id', 'entrada_en']);
            $tabla->index(['tenant_id', 'salida_en']);
        });

        // LA REGLA DE NEGOCIO, IMPUESTA POR EL ÁRBOL.
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX visitas_una_abierta_por_socio
                ON public.visitas (socio_id)
                WHERE salida_en IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP INDEX IF EXISTS public.visitas_una_abierta_por_socio');
        Schema::dropIfExists('visitas');
    }
};