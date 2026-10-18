<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Por qué existe un periodo sin cobro.
 *
 * El caso que motiva este enum es el mes gratis de diciembre que muchos
 * contratos tienen. NO se modela con una bandera `gratis = true` en la
 * membresía, sino como un PERIODO con inicio y fin propios dentro de
 * `periodos_sin_cobro`.
 *
 * La diferencia importa porque un mes gratis no es un descuento: es tiempo
 * durante el cual el socio sigue teniendo derecho a entrar y el gimnasio no
 * cobra nada. Con una bandera no se puede preguntar "¿hasta cuándo?" ni
 * "¿cuándo vuelve a empezar el cobro?", y esas son justamente las dos
 * preguntas que aparecen en recepción.
 */
enum MotivoPeriodoSinCobro: string
{
    case MesGratis = 'mes_gratis';
    case Promocion = 'promocion';
    case Cortesia = 'cortesia';
    case CierreTecnico = 'cierre_tecnico';

    public function descripcion(): string
    {
        return match ($this) {
            self::MesGratis => 'Mes sin cobro pactado en el contrato.',
            self::Promocion => 'Periodo promocional sin costo.',
            self::Cortesia => 'Cortesía del gimnasio.',
            self::CierreTecnico => 'El gimnasio no operó y no se cobra.',
        };
    }
}