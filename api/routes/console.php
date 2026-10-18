<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Asistencia\ContadoresAsistencia;
use App\Services\Tenancy\ContextoGimnasio;
use Illuminate\Support\Facades\Schedule;

/*
|---------------------------------------------------------------------------
| Tareas programadas
|---------------------------------------------------------------------------
|
| Las tareas que tocan datos de socios se ejecutan POR GIMNASIO y siempre
| dentro de ContextoGimnasio::ejecutarAislado(): el proceso de cola y el
| planificador reutilizan la misma conexión para trabajo de gimnasios
| distintos, y es justo por ahí por donde se cruzarían los datos.
|
| Ver docs/03, sección 6.
|
*/

// Comprobación diaria de que la Row Level Security sigue puesta. Si algún
// día dejara de estarlo, hay que saberlo antes de que llegue un socio.
Schedule::command('gimnasio:verificar-aislamiento')->dailyAt('05:00');

// Los cortes de energía y los fallos del turno dejan entradas sin salida.
// Se cierran solas, marcadas y con el rastro en la bitácora.
Schedule::command('gimnasio:cerrar-visitas-abiertas')->hourlyAt(7);

// La señal que el producto existe para encontrar.
Schedule::command('gimnasio:detectar-inactivos')
    ->dailyAt('08:00')
    ->withoutOverlapping();

/*
| Contadores en memoria
| ---------------------
| Se recalculan al cierre de cada día y quedan en Redis con clave POR
| GIMNASIO. La API nunca cuenta visitas fila por fila para dibujar un
| gráfico. Ver docs/01, sección 7.
|
| El cálculo se hace por gimnasio y dentro del contexto, por la misma razón
| que el resto: el planificador recorre todos los gimnasios con una sola
| conexión.
*/

Schedule::call(function (): void {
    $contexto = new ContextoGimnasio();
    $contadores = new ContadoresAsistencia();

    foreach (Tenant::query()->where('activo', true)->pluck('id') as $gimnasioId) {
        $contexto->ejecutarAislado(
            $gimnasioId,
            function () use ($contadores, $gimnasioId): void {
                $contadores->recalcular($gimnasioId);
            }
        );
    }
})->dailyAt('00:10');

// La detección de abandono ya corre como comando propio, con su candado
// (`withoutOverlapping`) y su salida en consola. No se repite aquí.