<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Socios: la ficha de la persona.
 *
 * DATOS PERSONALES BAJO LA LEY 19.628
 * -------------------------------------
 * Se guardan los datos que el gimnasio necesita para cobrar y para dejar
 * entrar: nombre, documento, contacto, fecha de nacimiento (que usa la
 * tarifa estudiante). NO HAY columnas de salud.
 *
 * No hay `lesion`, ni `diagnostico`, ni `aptitud_medica`, ni `peso`, ni
 * `altura`. Un gimnasio no es una clínica: si un socio deja de asistir por
 * una lesión, eso se conversó en recepción y no queda escrito acá. La razón
 * no es solo ética: un dato de salud cambia la calidad del dato que sí se
 * puede entregar, porque obliga a permisos más estrictos para algo que el
 * sistema no usa. Ver docs/07, sección 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('socios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->foreignUuid('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();

            $tabla->string('nombre', 120);
            $tabla->string('apellido', 120)->nullable();
            $tabla->string('documento', 20)->nullable();
            $tabla->date('nacimiento')->nullable();
            $tabla->string('correo', 160)->nullable();
            $tabla->string('telefono', 32)->nullable();
            $tabla->string('telefono_emergencia', 32)->nullable();
            $tabla->string('contacto_emergencia', 120)->nullable();

            // Suspensión del dueño. Es un PERIODO, no una bandera: la
            // suspensión tiene fecha de término y el socio vuelve solo.
            $tabla->timestampTz('suspendida_desde')->nullable();
            $tabla->timestampTz('suspension_hasta')->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            // Un documento por gimnasio, no global: dos gimnasios
            // pueden tener socios con el mismo número de documento.
            $tabla->unique(['tenant_id', 'documento']);
            $tabla->index(['tenant_id', 'activo']);
            $tabla->index(['tenant_id', 'apellido', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('socios');
    }
};