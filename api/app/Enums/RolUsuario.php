<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles del gimnasio.
 *
 * La identidad la demuestra Authentik; el permiso lo decide el gimnasio.
 * El grupo de Authentik solo sirve para el primer alta del administrador.
 */
enum RolUsuario: string
{
    /** Dueño o gerente: ve todo lo de su gimnasio, incluido lo administrativo. */
    case Administrador = 'administrador';

    /** Recepción: registra entradas, cobra y gestiona socios. No cambia planes ni usuarios. */
    case Encargado = 'encargado';

    /** Coach: ve la ficha de los socios a su cargo y las rutinas. No cobra ni cambia estados. */
    case Coach = 'coach';

    /** Socio con cuenta propia: solo ve su asistencia, su membresía y sus rutinas. */
    case Socio = 'socio';

    public function esPersonal(): bool
    {
        return $this !== self::Socio;
    }

    /** Un socio nunca administra nada del gimnasio. */
    public function administraGimnasio(): bool
    {
        return $this === self::Administrador;
    }
}