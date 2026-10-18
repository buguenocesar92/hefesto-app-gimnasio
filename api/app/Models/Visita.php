<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CanalAcceso;
use App\Models\Concerns\PerteneceAlGimnasio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Visita: un intervalo de permanencia dentro del gimnasio.
 *
 * LA ASISTENCIA ES UNA SERIE DE HECHOS
 * ------------------------------------
 * Un socio puede venir tres veces en un día. El sistema no guarda un
 * contador ni un estado "dentro/fuera": guarda una fila por cada vez que la
 * persona estuvo adentro. La foto del día se arma contando filas, y el
 * gráfico del mes también.
 *
 * La fila se puede corregir, no se borra: si la entrada estaba mal leída, se
 * marca como anulada y queda el rastro. Ver docs/02, sección 4.
 *
 * UNA VISITA ABIERTA A LA VEZ
 * --------------------------
 * Un socio no puede tener dos visitas simultáneas abiertas. Esa regla no la
 * impone esta clase: la impone un índice único parcial en PostgreSQL
 * (`visitas_una_abierta_por_socio`, sobre las filas con `salida_en IS NULL`).
 * Está en la base porque una regla de asistencia que depende de que el
 * código esté bien escrito se rompe el primer día que dos personas lean a la
 * vez en recepción.
 *
 * ZONA HORARIA Y MEDIANOCHE
 * -------------------------
 * La visita pertenece al día de la ENTRADA, y ese día se calcula con la zona
 * horaria del gimnasio. Un socio que entra a las 23:50 y sale a las 00:10
 * cuenta como UNA visita del día anterior, no como dos. La vista
 * `vw_visitas` es la que aplica la regla en SQL.
 */
class Visita extends Model
{
    use HasUuids;
    use PerteneceAlGimnasio;

    protected $table = 'visitas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entrada_en' => 'datetime',
            'salida_en' => 'datetime',
            'canal' => CanalAcceso::class,
            'anulada' => 'boolean',
            'cerrada_por_sistema' => 'boolean',
        ];
    }

    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoAcceso::class, 'visita_id')->orderBy('ocurrido_en');
    }

    public function estaAbierta(): bool
    {
        return $this->salida_en === null && ! $this->anulada;
    }

    /**
     * Minutos dentro del gimnasio.
     *
     * Una visita abierta devuelve los minutos transcurridos hasta ahora, que
     * es lo que el dueño quiere ver en pantalla: "lleva 47 minutos aquí".
     */
    public function duracionMinutos(?Carbon $ahora = null): int
    {
        if ($this->entrada_en === null) {
            return 0;
        }

        $fin = $this->salida_en ?? ($ahora ?? now());

        return max(0, (int) $this->entrada_en->diffInMinutes($fin, false));
    }

    /** Cruce de medianoche: la visita es del día de la entrada. */
    public function cruzaMedianoche(): bool
    {
        if ($this->entrada_en === null || $this->salida_en === null) {
            return false;
        }

        return $this->entrada_en->toDateString() !== $this->salida_en->toDateString();
    }
}