<?php

use App\Http\Middleware\ResolverTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/**
 * Punto de arranque de la API del Gimnasio de Hefesto.
 *
 * Este archivo reemplaza al que genera `composer create-project`. El resto
 * del esqueleto (artisan, public/index.php, storage) lo genera Composer.
 * Ver docs/04-INSTALACION.md.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/api/v1/salud',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CSRF y sesion se aplican por el grupo `web` de Laravel. La interfaz
        // la sirve Nuxt (BFF) en el mismo origen, asi que la cookie de sesion
        // viaja sola y el token CSRF se reenvia en la cabecera X-XSRF-TOKEN.
        $middleware->web(append: [
            ResolverTenant::class,
        ]);

        // El grupo `api` de Laravel no lleva cookies ni CSRF: la API se
        // consume desde el servidor de Nuxt, que ya valido la sesion. El
        // aislamiento entre gimnasios NO depende de esta capa, lo pone la
        // Row Level Security (ver docs/03).
        $middleware->alias([
            'tenant.resuelto' => ResolverTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Un rechazo de autorizacion entre gimnasios no debe confirmar que el
        // recurso existe en el otro gimnasio: se responde 404, no 403.
        $exceptions->render(function (Illuminate\Auth\Access\AuthorizationException $e, $request) {
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'mensaje' => 'No encontrado.',
                ], 404);
            }

            return null;
        });
    })
    ->create();