<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|---------------------------------------------------------------------------
| Rutas web
|---------------------------------------------------------------------------
|
| La interfaz la sirve Nuxt con SSR. Laravel solo responde el login y el
| callback de Authentik, que terminan redirigiendo a la interfaz.
|
*/

Route::redirect('/', '/entrar')->name('raiz');

Route::get('/entrar', function () {
    return response()->json([
        'mensaje' => 'El inicio de sesion se hace contra Authentik.',
        'url' => route('auth.entrar'),
    ]);
})->name('entrar.pagina');

Route::get('/auth/entrar', function () {
    return redirect()->away(route('auth.entrar.authentik'));
})->name('auth.entrar');

Route::get('/auth/callback', function () {
    return response()->json([
        'mensaje' => 'Callback de Authentik. El intercambio de codigo lo hace la API.',
    ]);
})->name('auth.callback');