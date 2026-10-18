<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Periodos sin cobro: tiempo con derecho a entrar durante el cual no se cobra.
 *
 * EL MES GRATIS DE DICIEMBRE
 * --------------------------
 * Muchos contratos de gimnasio tienen un mes gratis. Se modela acá como un
 * PERIODO con inicio y fin, NO como una bandera `gratis` en la membresía.
 *
 * Por qué la diferencia importa: con una bandera no se puede preguntar
 * "¿hasta cuándo dura?", ni "¿cuándo vuelve el cobro?", ni "¿este socio tuvo
 * mes gratis en diciembre o en julio?". Con esta tabla, esas tres preguntas
 * tienen respuesta, y además el derecho del socio queda cubierto de forma
 * continua porque el periodo sin cobro y el siguiente cobro se tocan.
 *
 * `desde` es INCLUSIVO y `hasta` es EXCLUSIVO, igual que en `cobros`. El mes
 * gratis de diciembre es 2026-12-01 a 2027-01-01: cubre el 1 de diciembre y
 * el 31, y el 1 de enero ya es del cobro siguiente.
 *
 * `motivo` distingue el mes gratis pactado de una cortesía o de un cierre
 * técnico. El sistema los trata igual para el derecho a entrar, pero el dueño
 * necesita distinguirlos cuando revisa la caja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodos_sin_cobro', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $tabla->foreignUuid('membresia_id')->constrained('membresias')->cascadeOnDelete();

            $tabla->date('desde');
            $tabla->date('hasta');
            $tabla->string('motivo', 24)->default('mes_gratis');
            $tabla->text('nota')->nullable();
            $tabla->foreignUuid('registrado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'membresia_id']);
            $tabla->index(['tenant_id', 'desde', 'hasta']);

            // Dos periodos sin cobro de la misma membresía no se pueden
            // solapar. Si el dueño intenta cargar dos meses gratis
            // encima, la base lo rechaza en vez de crear un periodo que
            // nadie va a saber interpretar después.
            $tabla->unique(['membresia_id', 'desde', 'hasta'], 'periodos_sin_cobro_sin_repetir');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodos_sin_cobro');
    }
};