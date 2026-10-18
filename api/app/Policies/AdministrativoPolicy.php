<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RolUsuario;
use App\Models\Usuario;

/**
 * Permisos administrativos.
 *
 * Cambiar el plan de un socio, cambiar el precio de un plan, dar de alta a
 * otro coach o desactivar a alguien: todo eso es del dueño. Recepción entra
 * todos los días y no debería poder tocar nada de esto.
 *
 * Estos permisos tienen un costo real si fallan: un precio mal cambiado
 * afecta el cobro de todos los socios del mes siguiente.
 */
class AdministrativoPolicy
{
    public function verPlanes(Usuario $usuario): bool
    {
        return $usuario->rol->esPersonal();
    }

    public function crearPlanes(Usuario $usuario): bool
    {
        return $usuario->rol === RolUsuario::Administrador;
    }

    public function verUsuarios(Usuario $usuario): bool
    {
        return $usuario->rol === RolUsuario::Administrador;
    }

    public function crearUsuarios(Usuario $usuario): bool
    {
        return $usuario->rol === RolUsuario::Administrador;
    }

    /**
     * Ver el panel con la lista de socios en riesgo.
     *
     * Solo el dueño y recepción. El coach no: la lista de socios que están a
     * punto de irse es información de negocio, y no hay razón para que un
     * empleado nuevo la vea completa.
     */
    public function verPanel(Usuario $usuario): bool
    {
        return $usuario->rol === RolUsuario::Administrador
            || $usuario->rol === RolUsuario::Encargado;
    }
}