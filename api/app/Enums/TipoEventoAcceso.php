<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de evento en la bitácora de acceso.
 *
 * La asistencia NO es un estado del socio, es una SERIE DE EVENTOS. Un socio
 * puede entrar tres veces en el día y cada entrada es su propio hecho, con su
 * hora. Esta tabla `eventos_acceso` es la que no se reescribe: si algo sale
 * mal (una entrada mal leída, un socio que se quedó dormido en el sauna), se
 * agrega un evento de anulación en vez de editar el pasado.
 */
enum TipoEventoAcceso: string
{
    case Entrada = 'entrada';
    case Salida = 'salida';

    /** La visita se dio por cerrada sin que nadie registrara la salida. */
    case CierrePorSistema = 'cierre_por_sistema';

    /** La visita se anula entera: fue un error de registro. No borra, corrige. */
    case Anulacion = 'anulacion';

    public function cierraVisita(): bool
    {
        return $this !== self::Entrada;
    }
}