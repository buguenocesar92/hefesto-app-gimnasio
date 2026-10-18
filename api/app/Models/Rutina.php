<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlGimnasio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rutina: la lista de ejercicios que un socio o su coach definieron.
 *
 * NO se guarda nada de salud acá. Ni lesiones, ni diagnósticos, ni lo que el
 * socio dijo que le duele. Esto es la lista de lo que se va a hacer en la
 * sala de máquinas, no la historia clínica de nadie. Un gimnasio no es una
 * clínica. Ver docs/07, sección 3.
 *
 * El valor del módulo no está en la rutina en sí, sino en el contraste con
 * el tiempo: la misma rutina con más kilos el segundo mes es lo que el socio
 * viene a ver. Por eso los registros se guardan con fecha.
 */
class Rutina extends Model
{
    use HasUuids;
    use PerteneceAlGimnasio;

    protected $table = 'rutinas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'vigente_desde' => 'date',
            'vigente_hasta' => 'date',
            'activa' => 'boolean',
        ];
    }

    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'coach_id');
    }

    public function registros(): HasMany
    {
        return $this->hasMany(RegistroRutina::class, 'rutina_id')->orderBy('fecha');
    }
}