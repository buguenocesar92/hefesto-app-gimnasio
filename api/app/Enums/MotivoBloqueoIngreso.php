<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Por qué una membresía no permite el ingreso hoy.
 *
 * Se separa del `EstadoMembresia` a proposito: uno es lo que el dueño puso
 * (suspendida), el otro es una conclusión del sistema (el periodo cubierto
 * termino). El dueño los ve juntos, pero no son lo mismo.
 */
enum MotivoBloqueoIngreso: string
{
    /** Periodo cubierto vigente y membresía activa. */
    case Ninguno = 'ninguno';

    /** El socio no tiene membresía. */
    case SinMembresia = 'sin_membresia';

    /** La membresía está suspendida por el dueño, con fecha de término. */
    case Suspendida = 'suspendida';

    /** La membresía terminó. */
    case Terminada = 'terminada';

    /** El último cobro o periodo sin cobro ya no llega a hoy. */
    case PeriodoVencido = 'periodo_vencido';

    /** Ya tiene una visita abierta: no puede entrar dos veces. */
    case VisitaAbierta = 'visita_abierta';
}