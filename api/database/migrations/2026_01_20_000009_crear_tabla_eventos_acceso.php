<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos de acceso: la bitácora que solo crece.
 *
 * Esta tabla es la que convierte la asistencia en una SERIE DE EVENTOS. La
 * tabla `visitas` se actualiza (le pone la salida), esta no: acá solo se
 * agrega.
 *
 * ¿POR QUÉ NO SE CORRIGE EL PASADO?
 * Porque un gimnasio necesita poder responder "¿por qué este socio tiene una
 * visita de 14 horas?". Si la salida se corrige sobreescribiendo, la
 * pregunta no tiene respuesta. Si se agrega un evento de anulación, la
 * respuesta está escrita y además se ve quién lo hizo y a qué hora.
 *
 * No hay clave foránea con `ON DELETE CASCADE` a `visitas` desde acá: si se
 * borrara una visita, sus eventos quedarían sin dueño y la bitácora mentiría.
 * Las visitas no se borran, se anulan. Por eso la restricción es RESTRICT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_acceso', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->foreignUuid('visita_id')->constrained('visitas')->restrictOnDelete();
            $tabla->foreignUuid('socio_id')->constrained('socios')->cascadeOnDelete();

            // entrada, salida, cierre_por_sistema, anulacion.
            $tabla->string('tipo', 24);

            $tabla->string('canal', 20)->default('dispositivo');
            $tabla->foreignUuid('registrado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->string('motivo', 200)->nullable();

            // Momento en que PASÓ el hecho. Puede diferir del `creado_en`: si
            // el turno se cayó y se cargaron los accesos después, el hecho
            // ocurrió a las 19:12 aunque se escribió a las 22:00.
            $tabla->timestampTz('ocurrido_en');
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'ocurrido_en']);
            $tabla->index(['tenant_id', 'socio_id', 'ocurrido_en']);
            $tabla->index(['visita_id', 'ocurrido_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_acceso');
    }
};