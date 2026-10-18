<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gimnasios (tenants).
 *
 * Esta tabla NO lleva Row Level Security, a propósito. Se consulta durante el
 * inicio de sesión, cuando todavía no hay contexto de gimnasio que poner en
 * la conexión. Es la excepción consciente que ya está documentada en
 * apps/portal-autoservicio/docs/03-MULTITENANCY.md, sección 4, y se compensa
 * igual: la vista `vw_gimnasios_publicos` expone solo tres columnas, y el rol
 * de la aplicación no es superusuario ni tiene BYPASSRLS.
 *
 * `zona_horaria` se guarda como nombre IANA ("America/Santiago"), no como
 * desplazamiento en horas: Chile cambia de UTC-4 a UTC-3 una vez al año, y un
 * desplazamiento fijo dejaría todas las visitas de invierno con la hora
 * corrida.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->string('slug', 80)->unique();
            $tabla->string('nombre', 160);
            $tabla->string('razon_social', 160)->nullable();
            $tabla->string('rut', 20)->nullable();
            $tabla->string('zona_horaria', 64)->default('America/Santiago');
            $tabla->string('correo_contacto', 160)->nullable();
            $tabla->string('telefono_contacto', 32)->nullable();

            // Ajustes de negocio que el dueño cambia sin tocar código.
            $tabla->integer('dias_aviso_inactividad')->default(21);
            $tabla->integer('horas_visita_abierta_maxima')->default(8);

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};