<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos de abandono.
 *
 * Es la salida que este producto existe para producir: socios con membresía
 * vigente que no vienen hace días. Ver docs/01, sección 2.
 *
 * Un aviso abierto por socio como máximo: el índice único es
 * `(socio_id) WHERE cerrado_en IS NULL`. Así no se apilan veinte avisos del
 * mismo socio que dejó de venir, que es lo que pasaría si el detector
 * corriera cada hora sin mirar lo que ya estaba avisado.
 *
 * Cuando el socio vuelve, el aviso se cierra con `cerrado_en` y queda la
 * pregunta abierta de si el aviso sirvió: eso se mide en el panel, no se
 * supone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avisos_abandono', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->foreignUuid('socio_id')->constrained('socios')->cascadeOnDelete();

            $tabla->integer('dias_sin_venir');
            $tabla->timestampTz('ultima_visita_en')->nullable();
            $tabla->date('ultimo_pago')->nullable();

            // El dueño necesita las dos señales juntas: el socio que dejó de
            // venir CON la membresía al día es el que hay que recuperar hoy;
            // el que además está atrasado es otro problema.
            $tabla->boolean('pago_al_dia')->default(true);

            $tabla->text('observacion')->nullable();
            $tabla->timestampTz('detectado_en');
            $tabla->timestampTz('cerrado_en')->nullable();
            $tabla->foreignUuid('cerrado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'cerrado_en']);
            $tabla->index(['tenant_id', 'dias_sin_venir']);

            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX avisos_abandono_uno_abierto_por_socio
                    ON public.avisos_abandono (socio_id)
                    WHERE cerrado_en IS NULL
            SQL);
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS public.avisos_abandono_uno_abierto_por_socio');
        Schema::dropIfExists('avisos_abandono');
    }
};