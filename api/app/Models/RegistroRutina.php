<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlGimnasio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de una serie: ejercicio, fecha, kilos y repeticiones.
 *
 * Es aplanado a propósito (una fila por serie, no una fila por día con un
 * JSON adentro) porque la consulta que importa es "¿cuál fue mi mejor marca
 * en press banca?", y esa consulta se contesta con un índice, no con un
 * recorrido en memoria.
 *
 * `peso_clp` no: `peso_kg` es un número decimal, y el socio entrena con
 * kilos, no con pesos de groceries. Queda clarification en docs/02.
 */
class RegistroRutina extends Model
{
    use HasUuids;
    use PerteneceAlGimnasio;

    protected $table = 'registros_rutina';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'peso_kg' => 'decimal:2',
            'repeticiones' => 'integer',
        ];
    }

    public function rutina(): BelongsTo
    {
        return $this->belongsTo(Rutina::class, 'rutina_id');
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