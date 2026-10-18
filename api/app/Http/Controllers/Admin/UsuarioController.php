<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\RolUsuario;
use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Policies\AdministrativoPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Personal del gimnasio y cuentas de socios.
 *
 * DAR DE ALTA A ALGUIEN ES UNA DECISIÓN DEL DUEÑO
 * ----------------------------------------------
 * Una cuenta nueva en recepción significa que alguien va a poder registrar
 * entradas y ver fichas de socios. Es acceso a datos personales de terceros,
 * así que no lo puede hacer recepción: solo el dueño.
 *
 * El `authentik_sub` se genera vacío a propósito. La cuenta queda creada
 * pero no habilitada: entra la primera vez que esa persona se autentica en
 * Authentik con ese mismo `sub`. Si se inventara el `sub` acá, la persona
 * podría no poder entrar nunca.
 */
class UsuarioController extends Controller
{
    public function indice(Request $peticion): JsonResponse
    {
        $this->authorize('verUsuarios', AdministrativoPolicy::class);

        return response()->json([
            'usuarios' => Usuario::query()
                ->orderBy('nombre')
                ->get()
                ->map(fn (Usuario $usuario): array => [
                    'id' => $usuario->id,
                    'nombre' => trim(($usuario->nombre ?? '') . ' ' . ($usuario->apellido ?? '')),
                    'correo' => $usuario->correo,
                    'rol' => $usuario->rol->value,
                    'activo' => $usuario->activo,
                    'ultimo_acceso' => $usuario->ultimo_acceso?->toIso8601String(),
                ])->all(),
        ]);
    }

    public function guardar(Request $peticion): JsonResponse
    {
        $this->authorize('crearUsuarios', AdministrativoPolicy::class);

        $datos = $peticion->validate([
            'nombre' => ['required', 'string', 'max:80'],
            'apellido' => ['nullable', 'string', 'max:80'],
            'correo' => ['required', 'email', 'max:160'],
            'rol' => ['required', \Illuminate\Validation\Rule::enum(RolUsuario::class)],
            'telefono' => ['nullable', 'string', 'max:32'],
        ]);

        $usuario = Usuario::create($datos + [
            // Identificador provisional. La cuenta se habilita sola la primera
            // vez que esa persona entra por Authentik.
            'authentik_sub' => (string) Str::uuid(),
            'activo' => true,
        ]);

        return response()->json(['usuario_id' => $usuario->id], 201);
    }
}