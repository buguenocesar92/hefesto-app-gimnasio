<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\UsuarioController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MembresiaController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\RutinaController;
use App\Http\Controllers\SocioController;
use App\Http\Controllers\VisitaController;
use Illuminate\Support\Facades\Route;

/*
|---------------------------------------------------------------------------
| Rutas de la API (prefijo /api)
|---------------------------------------------------------------------------
|
| Todo cuelga de /api/v1 y todo exige sesion, menos el login, el callback y
| la salud. No hay endpoints publicos de datos de negocio.
|
| El aislamiento entre gimnasios lo pone la Row Level Security de PostgreSQL
| a traves de App\Http\Middleware\ResolverTenant, que envuelve la peticion
| en una transaccion con el tenant fijado. Ver docs/03-MULTITENANCY.md.
|
*/

Route::get('/v1/salud', fn () => response()->json([
    'estado' => 'ok',
    'servicio' => 'api-gimnasio',
]))->name('salud');

Route::prefix('/v1')->group(function (): void {
    Route::get('/sesion', [AuthController::class, 'sesion'])->name('sesion');

    // Todo lo de abajo es del gimnasio autenticado.
    Route::middleware(['auth'])->group(function (): void {
        // --- Panel: el producto en si -----------------------------------
        Route::get('/panel', [PanelController::class, 'indice'])->name('panel');

        // --- Socios ------------------------------------------------------
        Route::get('/socios', [SocioController::class, 'indice'])->name('socios.indice');
        Route::post('/socios', [SocioController::class, 'guardar'])->name('socios.guardar');
        Route::get('/socios/{socio}', [SocioController::class, 'mostrar'])->name('socios.mostrar');
        Route::patch('/socios/{socio}', [SocioController::class, 'actualizar'])->name('socios.actualizar');

        // --- Asistencia: una serie de eventos, no un estado ----------------
        Route::get('/visitas', [VisitaController::class, 'indice'])->name('visitas.indice');
        Route::post('/visitas/entrada', [VisitaController::class, 'entrar'])->name('visitas.entrar');
        Route::get('/visitas/{visita}', [VisitaController::class, 'mostrar'])->name('visitas.mostrar');
        Route::post('/visitas/{visita}/salida', [VisitaController::class, 'salir'])->name('visitas.salir');
        Route::get('/visitas/{visita}/eventos', [VisitaController::class, 'eventos'])->name('visitas.eventos');

        // El invitado NO es un socio: va en su propia tabla y no entra en
        // ningun conteo de socios. Ver docs/02, seccion 8.
        Route::post('/visitas/invitados', [VisitaController::class, 'registrarInvitado'])
            ->name('visitas.invitados');

        // --- Membresias, cobros y periodos sin cobro ----------------------
        Route::get('/socios/{socio}/membresias', [MembresiaController::class, 'indice'])
            ->name('membresias.indice');
        Route::post('/socios/{socio}/membresias', [MembresiaController::class, 'crear'])
            ->name('membresias.crear');
        Route::get('/membresias/{membresia}', [MembresiaController::class, 'mostrar'])
            ->name('membresias.mostrar');
        Route::post('/membresias/{membresia}/cobros', [MembresiaController::class, 'cobrar'])
            ->name('membresias.cobrar');
        Route::post('/membresias/{membresia}/periodos-sin-cobro', [MembresiaController::class, 'periodoSinCobro'])
            ->name('membresias.periodo-sin-cobro');
        Route::post('/membresias/{membresia}/suspension', [MembresiaController::class, 'suspender'])
            ->name('membresias.suspender');

        // --- Rutinas y seguimiento ----------------------------------------
        Route::get('/socios/{socio}/rutinas', [RutinaController::class, 'indice'])
            ->name('rutinas.indice');
        Route::post('/socios/{socio}/rutinas', [RutinaController::class, 'crear'])
            ->name('rutinas.crear');
        Route::post('/rutinas/{rutina}/registros', [RutinaController::class, 'registrarSerie'])
            ->name('rutinas.registrar-serie');
        Route::get('/rutinas/{rutina}/progreso', [RutinaController::class, 'progreso'])
            ->name('rutinas.progreso');

        // --- Administracion ------------------------------------------------
        Route::get('/admin/planes', [PlanController::class, 'indice'])->name('admin.planes.indice');
        Route::post('/admin/planes', [PlanController::class, 'guardar'])->name('admin.planes.guardar');
        Route::get('/admin/usuarios', [UsuarioController::class, 'indice'])->name('admin.usuarios.indice');
        Route::post('/admin/usuarios', [UsuarioController::class, 'guardar'])->name('admin.usuarios.guardar');
    });
});