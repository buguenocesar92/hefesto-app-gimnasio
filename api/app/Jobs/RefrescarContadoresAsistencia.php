<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Asistencia\ContadoresAsistencia;
use App\Services\Tenancy\ContextoGimnasio;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recalcula los conteos de asistencia de un gimnasio.
 *
 * POR QUÉ ESTE TRABAJO LLEVA EL GIMNASIO DENTRO
 * ----------------------------------------------
 * El worker de colas reutiliza la misma conexión de PostgreSQL para
 * trabajos de gimnasios distintos. Si este trabajo no abriera su propia
 * transacción con el contexto puesto, leería las visitas de todos los
 * gimnasios y las sumaría en un solo número.
 *
 * Por eso el trabajo envuelve TODO lo que hace en
 * `ContextoGimnasio::ejecutarAislado()`, que usa `set_config(..., true)`:
 * el valor desaparece al terminar la transacción y no queda colgando para el
 * trabajo siguiente. Ese es el mismo mecanismo del portal de autoservicio.
 *
 * `ShouldQueue` con `$tries = 3`: si la base no está lista, el trabajo se
 * reintenta en vez de perder el conteo.
 */
class RefrescarContadoresAsistencia implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Intentos antes de dar el trabajo por perdido. */
    public int $tries = 3;

    public function __construct(
        public readonly string $gimnasioId
    ) {}

    public function handle(ContadoresAsistencia $contadores): void
    {
        $contexto = new ContextoGimnasio();

        $contexto->ejecutarAislado(
            $this->gimnasioId,
            function () use ($contadores): void {
                $contadores->recalcular($this->gimnasioId);
            }
        );
    }
}