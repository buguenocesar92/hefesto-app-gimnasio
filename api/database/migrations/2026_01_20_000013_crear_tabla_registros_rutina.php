<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registros de series: ejercicio, fecha, kilos y repeticiones.
 *
 * UNA FILA POR SERIE, NO UNA FILA POR DÍA
 * ----------------------------------------
 * Aplanado a propósito. La consulta que importa es "¿cuál fue mi mejor marca
 * en press banca?", y esa se contesta con un índice sobre
 * `(socio_id, ejercicio, peso_kg)`, no con un recorrido en memoria de un JSON
 * anidado.
 *
 * `peso_kg` acepta decimal porque acá los pesos sí son fraccionarios (60,5
 * kg), a diferencia de los montos de plata, que son enteros. Ver docs/02,
 * sección 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registros_rutina', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->foreignUuid('rutina_id')->constrained('rutinas')->cascadeOnDelete();
            $tabla->foreignUuid('socio_id')->constrained('socios')->cascadeOnDelete();

            $tabla->string('ejercicio', 120);
            $tabla->date('fecha');
            $tabla->unsignedSmallInteger('serie');
            $tabla->unsignedSmallInteger('repeticiones');
            $tabla->decimal('peso_kg', 6, 2)->nullable();
            $tabla->string('canal', 20)->default('aplicacion');
            $tabla->foreignUuid('registrado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'socio_id', 'ejercicio', 'fecha']);
            $tabla->index(['tenant_id', 'rutina_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registros_rutina');
    }
};