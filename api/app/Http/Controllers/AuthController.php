<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Autenticación contra Authentik.
 *
 * ESTE CONTROLADOR NO ES EL QUE PROTEGE LOS DATOS
 * ------------------------------------------------
 * La protección real es la Row Level Security de PostgreSQL: si un endpoint
 * se agregara sin sesión y tocara una tabla protegida,
 * `public.app_tenant_id()` lanzaría excepción y la petición fallaría. Eso no
 * depende de que esta clase esté bien escrita.
 */
class AuthController extends Controller
{
    /**
     * Quién es la persona que está trabajando ahora.
     *
     * El frontend la usa para decidir qué menús mostrar, NO para decidir qué
     * se puede ver: la autorización real está en las Policies y en la RLS.
     */
    public function sesion(): JsonResponse
    {
        $usuario = auth()->user();

        return response()->json([
            'usuario' => [
                'id' => $usuario->id,
                'nombre' => trim(($usuario->nombre ?? '') . ' ' . ($usuario->apellido ?? '')),
                'correo' => $usuario->correo,
                'rol' => $usuario->rol->value,
                'gimnasio_id' => $usuario->tenant_id,
            ],
            'permisos' => [
                'registrar_acceso' => $usuario->puedeRegistrarAcceso(),
                'gestionar_socios' => $usuario->puedeGestionarSocios(),
                'administrar_gimnasio' => $usuario->esAdministrador(),
                'ver_rutinas' => $usuario->puedeVerRutinas(),
            ],
        ]);
    }
}