<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Por dónde entró la persona al gimnasio.
 *
 * La distinción importa por los cortes de energía y los fallos del torno:
 * un acceso manual tiene que quedar marcado como manual, con quién lo hizo
 * y por qué. Si se mezclara con el acceso por dispositivo, un corte de luz
 * parecería un turno funcionando y nadie podría distinguishing uno de otro.
 *
 * `canal` no es un detalle de forma: es evidencia.
 */
enum CanalAcceso: string
{
    /** Lectura del torno o lector de huella/rostro. Lo hizo el equipo. */
    case Dispositivo = 'dispositivo';

    /** Anotado por una persona de recepción. Si no hay más contexto, fue por
     *  corte de energía o turno fallenando. */
    case Manual = 'manual';

    /** El socio lo pidió desde la aplicación. */
    case Aplicacion = 'aplicacion';

    public function exigeResponsable(): bool
    {
        return $this === self::Manual;
    }
}