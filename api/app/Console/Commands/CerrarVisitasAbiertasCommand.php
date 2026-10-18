<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Visita;
use App\Services\Asistencia\RegistroVisita;
use App\Services\Tenancy\ContextoGimnasio;
use Illuminate\Console\Command;

/**
 * Cierra las visitas que quedaron abiertas sin que nadie registrara la salida.
 *
 * UNA ENTRADA SIN SALIDA ES UN CASO, NO UN OLVIDO
 * ----------------------------------------------
 * Pasa siempre: se corta la luz, el socio se queda adentro, y cuando vuelve
 * la luz el turno ya cerró. La visita queda abierta.
 *
 * Qué NO hace este comando:
 *  - no borra la visita;
 *  - no inventa una hora de salida "real";
 *  - no la marca como si alguien la hubiera cerrado.
 *
 * Le pone la hora en que el sistema la cerró, marca `cerrada_por_sistema` y
 * deja un evento en la bitácora que dice exactamente eso. La diferencia entre
 * "el socio salió a las 21:30" y "el sistema cerró la visita a las 23:00
 * porque nadie estaba en la casilla" queda escrita, y recepción puede
 * corregirla si hace falta.
 *
 *   php artisan gimnasio:cerrar-visitas-abiertas
 */
class CerrarVisitasAbiertasCommand extends Command
{
    protected $signature = 'gimnasio:cerrar-visitas-abiertas
        {--horas= : Sobrescribe las horas máximas configuradas en el gimnasio}
        {--gimnasio= : Limita a un gimnasio, por su identificador}';

    protected $description = 'Cierra las visitas abiertas que superaron el máximo de horas, dejando rastro en la bitácora.';

    public function handle(): int
    {
        $registro = new RegistroVisita();
        $contexto = new ContextoGimnasio();
        $horasForzadas = $this->option('horas');
        $gimnasioPedido = $this->option('gimnasio');

        $gimnasios = Tenant::query()
            ->where('activo', true)
            ->when($gimnasioPedido !== null, function ($consulta) use ($gimnasioPedido): void {
                $consulta->whereKey($gimnasioPedido);
            })
            ->get();

        $total = 0;

        foreach ($gimnasios as $gimnasio) {
            // Todo el trabajo ocurre DENTRO del contexto del gimnasio. El
            // worker reutiliza la misma conexión para trabajos de gimnasios
            // distintos, y es justo por ahí por donde se cruzarían los datos.
            $cerradas = $contexto->ejecutarAislado($gimnasio->id, function () use ($gimnasio, $registro, $horasForzadas): int {
                $horas = $horasForzadas !== null ? (int) $horasForzadas : $gimnasio->horasParaCerrarVisitaAbierta();

                $visitas = Visita::query()
                    ->whereNull('salida_en')
                    ->where('anulada', false)
                    ->where('entrada_en', '<=', now()->subHours($horas))
                    ->get();

                foreach ($visitas as $visita) {
                    $registro->cerrarPorSistema(
                        $visita,
                        $visita->entrada_en->copy()->addHours($horas),
                        sprintf(
                            'Cerrada automáticamente a las %s horas sin salida registrada (máximo del gimnasio: %d h).',
                            $horas,
                            $horas
                        )
                    );
                }

                return $visitas->count();
            });

            if ($cerradas > 0) {
                $this->line(sprintf('  "%s": %d visita(s) cerrada(s) por el sistema.', $gimnasio->nombre, $cerradas));
            }

            $total += $cerradas;
        }

        $this->info(sprintf('Visitas cerradas por el sistema: %d.', $total));

        return self::SUCCESS;
    }
}