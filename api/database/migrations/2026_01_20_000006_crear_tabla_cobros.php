<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobros de membresía.
 *
 * CONVENCIÓN DE PERIODOS
 * ----------------------
 * `periodo_desde` es INCLUSIVO y `periodo_hasta` es EXCLUSIVO. Un cobro del
 * 1 de noviembre al 1 de diciembre cubre el 1 de noviembre y el 30, y el 1
 * de diciembre ya es del periodo siguiente.
 *
 * Esta convención es la que hace que el mes gratis y los cobros se encajen
 * sin huecos ni solapamientos: el mes gratis de diciembre va de 2026-12-01
 * a 2027-01-01 (excluido), y el cobro de enero va de 2027-01-01 a
 * 2027-02-01. No hay día que quede sin dueño ni día cobrado dos veces.
 *
 * `anulado` existe porque un cobro se anula, no se borra: si alguien se
 * equivocó al cobrar, la fila sigue ahí con la marca y el arqueo de caja
 * sigue cuadrando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobros', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->foreignUuid('membresia_id')->constrained('membresias')->cascadeOnDelete();

            $tabla->date('periodo_desde');
            $tabla->date('periodo_hasta');
            $tabla->integer('monto_clp');
            $tabla->string('medio_pago', 40)->nullable();
            $tabla->timestampTz('pagado_en')->nullable();
            $tabla->string('comprobante', 80)->nullable();
            $tabla->boolean('anulado')->default(false);
            $tabla->foreignUuid('registrado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'membresia_id']);
            $tabla->index(['tenant_id', 'periodo_hasta']);
            $tabla->index(['tenant_id', 'pagado_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobros');
    }
};