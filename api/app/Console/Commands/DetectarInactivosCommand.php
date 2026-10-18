<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Retencion\DetectorDeInactividad;
use App\Services\Tenancy\ContextoGimnasio;
use Illuminate\Console\Command;

/**
 * Genera los avisos de abandono.
 *
 * LA GENTE DEJA DE VENIR Y NADIE SE ENTERA HASTA QUE YA SE FUERON
 * ----------------------------------------------------------
 * Este comando es la razón de existir del sistema. No manda correos ni
 * mensajes: deja la lista en el panel y avisa por consola. Lo que se haga
 * después se conversa con el dueño del gimnasio, no se impone desde el
 * software.
 *
 *   php artisan gimnasio:detectar-inactivos
 */
class DetectarInactivosCommand extends Command
{
    protected $signature = 'gimnasio:detectar-inactivos
        {--dias= : Días sin venir para considerar riesgo (por defecto, los del gimnasio)}
        {--gimnasio= : Limita a un gimnasio, por su identificador}';

    protected $description = 'Detecta socios con membresía vigente que llevan demasiado tiempo sin venir.';

    public function handle(): int
    {
        $contexto = new ContextoGimnasio();
        $detector = new DetectorDeInactividad();
        $dias = $this->option('dias') !== null ? (int) $this->option('dias') : null;

        $gimnasios = Tenant::query()
            ->where('activo', true)
            ->when($this->option('gimnasio') !== null, function ($consulta): void {
                $consulta->whereKey($this->option('gimnasio'));
            })
            ->get();

        $total = 0;

        foreach ($gimnasios as $gimnasio) {
            $avisos = $contexto->ejecutarAislado(
                $gimnasio->id,
                fn () => $detector->registrar($dias)
            );

            if ($avisos->isNotEmpty()) {
                $this->line(sprintf('  "%s": %d socio(s) en riesgo.', $gimnasio->nombre, $avisos->count()));
            }

            $total += $avisos->count();
        }

        $this->info(sprintf('Socios en riesgo de dejar de venir: %d.', $total));

        return self::SUCCESS;
    }
}