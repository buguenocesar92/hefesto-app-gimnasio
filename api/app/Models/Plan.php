<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlGimnasio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan contratado: el precio y qué incluye.
 *
 * Un plan es del gimnasio, no del socio. El socio no guarda "el plan" como
 * dato: tiene una membresía que apunta a un plan, y esa membresía tiene su
 * historial de cobros. Si mañana suben el precio, los socios que ya pagaron
 * el mes siguen con el precio que pagaron, porque el cobro guarda el monto.
 */
class Plan extends Model
{
    use HasUuids;
    use PerteneceAlGimnasio;

    protected $table = 'planes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'precio_clp' => 'integer',
            'dias_periodo' => 'integer',
            'visitas_maximas_periodo' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function membresias(): HasMany
    {
        return $this->hasMany(Membresia::class, 'plan_id');
    }
}