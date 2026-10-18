<?php

declare(strict_types=1);

use App\Enums\EstadoMembresia;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membresías: el vínculo entre un socio y un plan.
 *
 * Aquí NO hay columna de fecha de pago. La fecha de pago vive en `cobros`,
 * porque un socio paga todos los meses y el dueño necesita la lista, no un
 * único campo que se sobreescribe. Ver docs/02, sección 6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membresias', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->foreignUuid('socio_id')->constrained('socios')->cascadeOnDelete();
            $tabla->foreignUuid('plan_id')->constrained('planes')->restrictOnDelete();

            $tabla->date('iniciada_en');
            $tabla->string('estado', 20)->default(EstadoMembresia::Activa->value);
            $tabla->date('terminada_en')->nullable();
            $tabla->text('nota')->nullable();
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'socio_id']);
            $tabla->index(['tenant_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membresias');
    }
};