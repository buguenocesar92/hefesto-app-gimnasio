<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MotivoPeriodoSinCobro;
use App\Models\Concerns\PerteneceAlGimnasio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Periodo sin cobro: tiempo con derecho a entrar durante el cual no se cobra.
 *
 * EL MES GRATIS DE DICIEMBRE
 * --------------------------
 * Muchos contratos tienen un mes gratis. NO se modela con una columna
 * `gratis = true` en la membresía, por una razón práctica: con una bandera
 * no se puede preguntar "¿hasta cuándo dura?", ni "¿cuándo vuelve a empezar
 * el cobro?", ni "¿este socio tuvo un mes gratis en diciembre o en
 * julio?".
 *
 * Con esta tabla, el mes gratis es un PERIODO con inicio y fin propios, al
 * lado de los cobros y con las mismas reglas de encaste. El derecho del socio
 * sigue vigente durante el periodo, y el cobro siguiente arranca el día que
 * el periodo termina.
 *
 * `desde` es INCLUSIVO y `hasta` es EXCLUSIVO, igual que en `cobros`.
 */
class PeriodoSinCobro extends Model
{
    use HasUuids;
    use PerteneceAlGimnasio;

    protected $table = 'periodos_sin_cobro';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'desde' => 'date',
            'hasta' => 'date',
            'motivo' => MotivoPeriodoSinCobro::class,
        ];
    }

    public function membresia(): BelongsTo
    {
        return $this->belongsTo(Membresia::class, 'membresia_id');
    }

    public function dias(): int
    {
        return (int) $this->desde->diffInDays($this->hasta, false);
    }

    public function cubre(?string $fecha): bool
    {
        return $this->desde->toDateString() <= $fecha && $this->hasta->toDateString() > $fecha;
    }
}