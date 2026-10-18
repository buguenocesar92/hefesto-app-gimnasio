<?php

declare(strict_types=1);

use App\Enums\RolUsuario;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usuarios: personal del gimnasio y socios con cuenta propia.
 *
 * `authentik_sub` es el identificador del proveedor de identidad. La
 * autenticación NO es de este sistema: Authentik demuestra quién es la
 * persona, y esta tabla guarda a qué gimnasio pertenece y qué papel tiene.
 *
 * `socio_id` se agrega después, porque `socios` se crea en la migración 4 y
 * esta es la 2. Por eso se declara la clave foránea acá con una condición
 * explícita en vez de `constrained()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->string('authentik_sub', 128)->unique();
            $tabla->string('correo', 160);
            $tabla->string('nombre', 80)->nullable();
            $tabla->string('apellido', 80)->nullable();
            $tabla->string('telefono', 32)->nullable();
            $tabla->string('rol', 20)->default(RolUsuario::Encargado->value);
            $tabla->boolean('activo')->default(true);
            $tabla->timestampTz('ultimo_acceso')->nullable();
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'rol']);
            $tabla->index(['tenant_id', 'correo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};