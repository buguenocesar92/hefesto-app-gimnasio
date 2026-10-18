<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RolUsuario;
use App\Models\IngresoInvitado;
use App\Models\Usuario;
use App\Models\Visita;

/**
 * Permisos sobre la asistencia.
 *
 * Un socio con cuenta propia ve SU historial de visitas y ninguna más. Ver la
 * asistencia de otro socio no le corresponde: es un dato de la persona, y
 * además le diría a un socio con cuenta cuándo viene otro socio, que no es un
 * dato que el gimnasio tenga por qué exponer.
 */
class VisitaPolicy
{
    public function verIndice(Usuario $usuario): bool
    {
        return $usuario->rol->esPersonal();
    }

    public function ver(Usuario $usuario, Visita $visita): bool
    {
        if ($usuario->rol === RolUsuario::Socio) {
            return $this->esSuPropiaVisita($usuario, $visita);
        }

        return $usuario->rol->esPersonal();
    }

    public function entrar(Usuario $usuario, \App\Models\Socio $socio): bool
    {
        // Un socio no puede registrarse su propia entrada: el acceso lo
        // registra quien controla la puerta, no la persona que llega.
        if ($usuario->rol === RolUsuario::Socio) {
            return false;
        }

        return $usuario->puedeRegistrarAcceso();
    }

    public function salir(Usuario $usuario, Visita $visita): bool
    {
        return $usuario->puedeRegistrarAcceso();
    }

    /**
     * El invitado lo registra el personal de recepción, no el coach.
     *
     * Es distinto del acceso de un socio: no verifica ninguna membresía, así
     * que no necesita el mismo nivel de confianza en el registro.
     */
    public function registrarInvitado(Usuario $usuario, string $clase): bool
    {
        return $usuario->puedeRegistrarAcceso();
    }

    private function esSuPropiaVisita(Usuario $usuario, Visita $visita): bool
    {
        $socioDelUsuario = \App\Models\Socio::query()
            ->where('usuario_id', $usuario->id)
            ->first();

        return $socioDelUsuario !== null && $socioDelUsuario->id === $visita->socio_id;
    }
}