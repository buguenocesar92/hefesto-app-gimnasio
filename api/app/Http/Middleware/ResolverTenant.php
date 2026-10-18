<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\ContextoGimnasio;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija el gimnasio de la petición para que la RLS de PostgreSQL sepa de quién
 * es cada fila.
 *
 * Envuelve la petición autenticada en una transacción con el gimnasio ya
 * fijado, y abre y cierra la transacción por su cuenta. Por eso el
 * middleware va ANTES que `auth`: resuelve el usuario por su cuenta con el
 * guard `web`, que ya lee la sesión.
 *
 * Peticiones sin sesión (salud, login, callback) pasan sin transacción. Si
 * esas rutas tocaran una tabla protegida, `public.app_tenant_id()` lanzaría
 * excepción y la petición fallaría: el aislamiento falla cerrado.
 */
class ResolverTenant
{
    public function __construct(private readonly ContextoGimnasio $contexto) {}

    public function handle(Request $peticion, Closure $siguiente): Response
    {
        $usuario = auth()->guard('web')->user();

        if ($usuario === null) {
            return $siguiente($peticion);
        }

        if ($usuario->tenant_id === null) {
            // Una cuenta sin gimnasio no puede operar. Se corta acá, antes de
            // tocar la base de datos.
            abort(403, 'La cuenta no esta asociada a ningun gimnasio.');
        }

        return $this->contexto->ejecutarAislado(
            $usuario->tenant_id,
            static fn () => $siguiente($peticion)
        );
    }
}