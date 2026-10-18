<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RolUsuario;
use App\Models\Membresia;
use App\Models\Usuario;

/**
 * Permisos sobre membresías y cobros.
 *
 * QUIÉN COBRA
 * -----------
 * Recepción cobra y el dueño cobra. El coach no: no necesita ver cuánto
 * pagó un socio para descargarle una rutina, y el dato existe sin que él lo
 * vea.
 *
 * QUIÉN SUSPENDE
 * --------------
 * Solo el dueño. La suspensión es una excepción comercial, y aceptarla en
 * recepción sin que el dueño lo sepa es cómo un gimnasio pierde plata sin
 * darse cuenta.
 */
class MembresiaPolicy
{
    public function ver(Usuario $usuario, Membresia $membresia): bool
    {
        if ($usuario->rol === RolUsuario::Socio) {
            return $this->esSuPropiaMembresia($usuario, $membresia);
        }

        return $usuario->rol->esPersonal();
    }

    public function crear(Usuario $usuario): bool
    {
        return $usuario->puedeGestenarSocios();
    }

    public function cobrar(Usuario $usuario, Membresia $membresia): bool
    {
        if ($usuario->rol === RolUsuario::Socio) {
            return false;
        }

        // El coach no cobra.
        return $usuario->rol === RolUsuario::Administrador
            || $usuario->rol === RolUsuario::Encargado;
    }

    /**
     * Registrar un periodo sin cobro es dejar de cobrar plata.
     *
     * Por eso es solo del dueño, y no de recepción: un mes gratis abierto por
     * error es plata que no vuelve, y nadie se entera hasta el arqueo.
     */
    public function periodoSinCobro(Usuario $usuario, Membresia $membresia): bool
    {
        return $usuario->rol === RolUsuario::Administrador;
    }

    public function suspender(Usuario $usuario, Membresia $membresia): bool
    {
        return $usuario->rol === RolUsuario::Administrador;
    }

    private function esSuPropiaMembresia(Usuario $usuario, Membresia $membresia): bool
    {
        $socioDelUsuario = \App\Models\Socio::query()
            ->where('usuario_id', $usuario->id)
            ->first();

        return $socioDelUsuario !== null && $socioDelUsuario->id === $membresia->socio_id;
    }
}