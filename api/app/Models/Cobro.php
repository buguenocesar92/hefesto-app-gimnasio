<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlGimnasio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cobro de un periodo de membresía.
 *
 * `periodo_hasta` es EXCLUSIVO: un cobro del 1 de noviembre al 1 de diciembre
 * cubre el 1 de noviembre incluido y el 30 de noviembre. El 1 de diciembre
 * ya es del periodo siguiente. Es la convención que hace que los periodos se
 * encajen sin huecos ni solapamientos, y la misma que usa `periodos_sin_cobro`.
 *
 * `monto_clp` se guarda en el cobro, no se lee del plan: el plan puede subir
 * de precio el mes que viene y el socio que ya pagó pagó lo que estaba
 * pactado ese día.
 */
class Cobro extends Model
{
    use HasUuids;
    use PerteneceAlGimnasio;

    protected $table = 'cobros';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'periodo_desde' => 'date',
            'periodo_hasta' => 'date',
            'pagado_en' => 'datetime',
            'monto_clp' => 'integer',
            'anulado' => 'boolean',
        ];
    }

    public function membresia(): BelongsTo
    {
        return $this->belongsTo(Membresia::class, 'membresia_id');
    }
}