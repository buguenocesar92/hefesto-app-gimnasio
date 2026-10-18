<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoMembresia;
use App\Models\Concerns\PerteneceAlGimnasio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Membresía: el vínculo entre un socio y un plan, con su historial.
 *
 * La membresía NO guarda "la fecha de pago". La fecha de pago vive en
 * `cobros`, porque un socio paga todos los meses y el dueño necesita ver la
 * lista de pagos, no un único campo que se sobreescribe cada vez.
 *
 * Esto resuelve la pregunta que el dueño hace siempre: "¿el socio que dejó
 * de venir el mes pasado, pagó?". Con un solo campo `ultimo_pago` la
 * respuesta se pierde en cuanto llega el cobro siguiente.
 */
class Membresia extends Model
{
    use HasUuids;
    use PerteneceAlGimnasio;

    protected $table = 'membresias';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoMembresia::class,
            'iniciada_en' => 'date',
            'terminada_en' => 'date',
            'suspendida_desde' => 'datetime',
            'suspension_hasta' => 'datetime',
        ];
    }

    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function cobros(): HasMany
    {
        return $this->hasMany(Cobro::class, 'membresia_id')->orderByDesc('periodo_hasta');
    }

    public function periodosSinCobro(): HasMany
    {
        return $this->hasMany(PeriodoSinCobro::class, 'membresia_id')->orderByDesc('hasta');
    }

    /** Último cobro vigente (no anulado). */
    public function ultimoCobro(): ?Cobro
    {
        return $this->cobros()->where('anulado', false)->first();
    }

    /** Último periodo sin cobro que todavía no terminó. */
    public function ultimoPeriodoSinCobro(): ?PeriodoSinCobro
    {
        return $this->periodosSinCobro()
            ->where('desde', '<=', today())
            ->where('hasta', '>', today())
            ->first();
    }

    /**
     * ¿El derecho del socio llega hasta hoy?
     *
     * Se calcula con los cobros VIGENTES más los periodos sin cobro. El mes
     * gratis de diciembre no es una bandera: es un periodo con inicio y fin,
     * y por eso extiende la cobertura sin cobrar nada.
     */
    public function cubreHoy(?string $fecha = null): bool
    {
        $referencia = $fecha ?? today()->toDateString();

        $cobro = $this->cobros()->where('anulado', false)->where('periodo_hasta', '>=', $referencia)->first();

        if ($cobro !== null) {
            return true;
        }

        $periodoGratis = $this->periodosSinCobro()
            ->where('desde', '<=', $referencia)
            ->where('hasta', '>', $referencia)
            ->first();

        return $periodoGratis !== null;
    }

    /**
     * Fecha en que termina el derecho vigente, o null si ya venció.
     *
     * Si el último cobro y el último periodo sin cobro se tocan (el mes
     * gratis termina el día que empieza el cobro siguiente), se toma el
     * mayor de los dos: partir el periodo gratis de nuevo.
     */
    public function finDeCoberturaVigente(?string $fecha = null): ?string
    {
        $referencia = $fecha ?? today()->toDateString();

        $cobro = $this->cobros()->where('anulado', false)->where('periodo_hasta', '>=', $referencia)->first();
        $periodoGratis = $this->periodosSinCobro()
            ->where('desde', '<=', $referencia)
            ->where('hasta', '>', $referencia)
            ->first();

        $fines = array_values(array_filter([
            $cobro?->periodo_hasta,
            $periodoGratis?->hasta,
        ]));

        if ($fines === []) {
            return null;
        }

        sort($fines);

        return (string) end($fines);
    }

    /** Fecha del último pago: la que el dueño mira junto a la asistencia. */
    public function fechaUltimoPago(): ?string
    {
        return $this->cobros()
            ->where('anulado', false)
            ->whereNotNull('pagado_en')
            ->max('pagado_en');
    }
}