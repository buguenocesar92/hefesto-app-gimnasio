<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado de una membresía.
 *
 * OJO: este estado es lo que el gimnasio DECLARA (la situación del contrato y
 * las suspensiones que el dueño aplica). Lo que dice si el socio puede entrar
 * HOY no es este campo, sino si su periodo cubierto llega hasta hoy.
 *
 * Esa diferencia es deliberada: un socio con membresía "activa" y sin cobro
 * de este mes esta al día en el contrato y vencido en la caja. El sistema
 * muestra las dos cosas, porque el dueño las necesita juntas. Ver
 * App\Services\Membresias\CalculadoraPeriodoCubierto.
 */
enum EstadoMembresia: string
{
    case Activa = 'activa';
    case Vencida = 'vencida';
    case Suspendida = 'suspendida';
    case Terminada = 'terminada';

    public function permiteIngreso(): bool
    {
        return $this === self::Activa;
    }
}