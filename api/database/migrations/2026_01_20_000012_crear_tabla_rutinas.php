<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rutinas: la lista de ejercicios de un socio.
 *
 * Sin datos de salud. Ni lesiones, ni diagnósticos, ni lo que el socio dijo
 * que le duele. Esto es la lista de lo que se hace en la sala de máquinas, no
 * la historia clínica de nadie. Un gimnasio no es una clínica. Ver docs/07.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rutinas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->foreignUuid('socio_id')->constrained('socios')->cascadeOnDelete();
            $tabla->foreignUuid('coach_id')->nullable()->constrained('usuarios')->nullOnDelete();

            $tabla->string('nombre', 120);
            $tabla->text('descripcion')->nullable();
            $tabla->date('vigente_desde');
            $tabla->date('vigente_hasta')->nullable();
            $tabla->boolean('activa')->default(true);
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'socio_id']);
            $tabla->index(['tenant_id', 'activa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rutinas');
    }
};