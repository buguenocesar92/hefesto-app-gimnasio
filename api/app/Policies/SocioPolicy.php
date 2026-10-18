<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RolUsuario;
use App\Models\Socio;
use App\Models\Usuario;

/**
 * Permisos sobre la ficha del socio.
 *
 * DOBLE FILTRO
 * ------------
 * La Row Level Security ya garantiza que nada sale del gimnasio. Esta clase
 * es el SEGUNDO filtro, dentro del gimnasio: no todos los que están adentro
 * pueden ver todo.
 *
 * Tabla de permisos:
 *
 * | Acción                     | Socio | Coach | Encargado | Administrador |
 * |---------------------------|-------|-------|-----------|---------------|
 * | Ver la lista de socios     | no    | sí    | sí        | sí            |
 * | Ver su propia ficha        | sí    | no    | no        | no            |
 * | Ver otra ficha             | no    | sí    | sí        | sí            |
 * | Crear y editar socios      | no    | no    | sí        | sí            |
 * | Suspender a un socio       | no    | no    | no        | sí            |
 * | Registrar entrada          | no    | no    | sí        | sí            |
 *
 * El coach ve fichas porque su trabajo es ver qué hace cada socio. NO ve
 * cobros ni puede cambiar el estado de una membresía: cobrar es de recepción.
 *
 * CUANDO UNA POLICY RECHAZA, LA RESPUESTA ES 404
 * ---------------------------------------------
 * Un 403 confirmaría que el recurso existe. Está configurado en
 * bootstrap/app.php.
 */
class SocioPolicy
{
    public function verIndice(Usuario $usuario): bool
    {
        return $usuario->rol->esPersonal();
    }

    public function ver(Usuario $usuario, Socio $socio): bool
    {
        if ($usuario->rol === RolUsuario::Socio) {
            // Un socio con cuenta propia solo ve SU ficha. Y para que no
            // pueda ver la de otro, la comparación es por `usuario_id` y no
            // por nombre.
            return $socio->usuario_id !== null && $socio->usuario_id === $usuario->id;
        }

        return $usuario->rol->esPersonal();
    }

    public function crear(Usuario $usuario): bool
    {
        return $usuario->puedeGestionarSocios();
    }

    public function actualizar(Usuario $usuario, Socio $socio): bool
    {
        return $usuario->puedeGestionarSocios();
    }

    /**
     * Suspender es una decisión del dueño, no de recepción.
     *
     * Recepción ve el socio suspendido y no puede cambiar por qué. El dueño
     * puede.
     */
    public function suspender(Usuario $usuario, Socio $socio): bool
    {
        return $usuario->rol === RolUsuario::Administrador;
    }

    public function verMembresia(Usuario $usuario, Socio $socio): bool
    {
        if ($usuario->rol === RolUsuario::Socio) {
            return $socio->usuario_id !== null && $socio->usuario_id === $usuario->id;
        }

        // El coach ve si el socio tiene derecho a entrar, porque lo necesita
        // para citarlo, pero no ve cuánto pagó.
        return $usuario->rol->esPersonal();
    }
}