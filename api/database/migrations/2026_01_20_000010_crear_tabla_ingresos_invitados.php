<?php

declare(strict_types=1);

use App\Enums\CanalAcceso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ingresos de invitados.
 *
 * UN INVITADO NO ES UN SOCIO, Y POR ESO NO ESTÁ EN ESTA TABLA.
 *
 * Si el invitado se guardara en `visitas` con `socio_id` nulo, cada consulta
 * de asistencia tendría que acordarse de excluirlo, y el día que una se
 * olvide, el panel muestra más visitas que las que hubo, sin ningún error
 * visible. Con una tabla aparte, los conteos de socios no pueden tocar a los
 * invitados ni por accidente.
 *
 * Solo se guarda el nombre. El gimnasio no necesita el documento ni el
 * teléfono de alguien que viene una vez, y pedirle esos datos sería tomar
 * datos personales sin motivo (Ley 19.628, ver docs/07).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingresos_invitados', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Socio que trajo al invitado, si se sabe. NO es una relación
            // obligatoria: a veces entra un invitado solo.
            $tabla->foreignUuid('acompana_a_socio_id')->nullable()->constrained('socios')->nullOnDelete();

            $tabla->string('nombre', 120);
            $tabla->timestampTz('entrada_en');
            $tabla->timestampTz('salida_en')->nullable();
            $tabla->string('canal', 20)->default(CanalAcceso::Manual->value);
            $tabla->foreignUuid('registrado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->string('motivo', 200)->nullable();
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'entrada_en']);
            $tabla->index(['tenant_id', 'acompana_a_socio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingresos_invitados');
    }
};