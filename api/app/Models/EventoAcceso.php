<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CanalAcceso;
use App\Enums\TipoEventoAcceso;
use App\Models\Concerns\PerteneceAlGimnasio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento de acceso: la bitácora que NO se reescribe.
 *
 * Esta tabla es la que hace que la asistencia sea una serie de eventos y no
 * un estado. `visitas` se actualiza (le pongo la salida), pero acá solo se
 * agrega: entrada, salida, cierre por sistema o anulación.
 *
 * Por qué importa: cuando alguien pregunta "¿por qué este socio tiene una
 * visita de 14 horas?", la respuesta tiene que estar escrita. Con un estado
 * mutable, la pregunta no tiene respuesta porque el estado anterior se
 * perdió. Con la bitácora, sí.
 *
 * Una entrada sin salida es un CASO, no un olvido: el comando
 * `gimnasio:cerrar-visitas-abiertas` la cierra después de las horas que el
 * gimnasio configuró, y deja el evento `cierre_por_sistema` que dice que
 * nadie la cerró, que la cerró el sistema. Ver docs/02, sección 5.
 */
class EventoAcceso extends Model
{
    use HasUuids;
    use PerteneceAlGimnasio;

    protected $table = 'eventos_acceso';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tipo' => TipoEventoAcceso::class,
            'canal' => CanalAcceso::class,
            'ocurrido_en' => 'datetime',
        ];
    }

    public function visita(): BelongsTo
    {
        return $this->belongsTo(Visita::class, 'visita_id');
    }

    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por');
    }
}