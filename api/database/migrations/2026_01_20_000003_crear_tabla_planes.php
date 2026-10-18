<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planes contratados.
 *
 * `precio_clp` es un entero en pesos chilenos. Los montos se guardan como
 * enteros a propósito: un decimal binario para dinero acumula error de
 * redondeo, y en un gimnasio chico la diferencia se nota al cerrar el mes.
 * Ver docs/02, sección 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planes', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->string('nombre', 120);
            $tabla->text('descripcion')->nullable();
            $tabla->integer('precio_clp');
            $tabla->integer('dias_periodo')->default(30);
            $tabla->integer('visitas_maximas_periodo')->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planes');
    }
};