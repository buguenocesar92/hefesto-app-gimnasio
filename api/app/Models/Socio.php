<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoMembresia;
use App\Enums\MotivoBloqueoIngreso;
use App\Models\Concerns\PerteneceAlGimnasio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Ficha del socio.
 *
 * Datos personales bajo la Ley 19.628: se guardan los que el gimnasio
 * necesita para cobrar y para dejar entrar (nombre, documento, contacto),
 * y nada más. NO hay columnas de salud, lesión, aptitude ni diagnóstico. Si
 * un socio deja de venir por una lesión, eso se conversó en recepción y no
 * se escribe acá. Ver docs/07, sección 3.
 */
class Socio extends Model
{
    use HasUuids;
    use PerteneceAlGimnasio;

    protected $table = 'socios';

    protected $guarded = ['id'];

    protected $hidden = ['documento'];

    protected function casts(): array
    {
        return [
            'nacimiento' => 'date',
            'suspendida_desde' => 'datetime',
            'suspension_hasta' => 'datetime',
            'activo' => 'boolean',
        ];
    }

    public function membresias(): HasMany
    {
        return $this->hasMany(Membresia::class, 'socio_id')->orderByDesc('iniciada_en');
    }

    public function membresiaVigente(): HasOne
    {
        return $this->hasOne(Membresia::class, 'socio_id')
            ->whereIn('estado', [EstadoMembresia::Activa->value, EstadoMembresia::Suspendida->value])
            ->orderByDesc('iniciada_en');
    }

    public function visitas(): HasMany
    {
        return $this->hasMany(Visita::class, 'socio_id')->orderByDesc('entrada_en');
    }

    public function rutinas(): HasMany
    {
        return $this->hasMany(Rutina::class, 'socio_id')->orderByDesc('creado_en');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function ultimaVisita(): ?Visita
    {
        return $this->visitas()->first();
    }

    public function visitasAbiertas(): HasMany
    {
        return $this->hasMany(Visita::class, 'socio_id')->whereNull('salida_en');
    }

    public function tieneVisitaAbierta(): bool
    {
        return $this->visitasAbiertas()->exists();
    }

    /**
     * Días desde la última visita, o null si nunca vino.
     *
     * Es el número que responde la pregunta que el dueño hace en cinco
     * segundos: "¿cuánto hace que no viene este socio?".
     */
    public function diasSinVisitar(?string $hoy = null): ?int
    {
        $ultima = $this->ultimaVisita()?->entrada_en;

        if ($ultima === null) {
            return null;
        }

        $referencia = $hoy !== null
            ? \Illuminate\Support\Carbon::parse($hoy)->startOfDay()
            : today();

        return (int) $ultima->startOfDay()->diffInDays($referencia, false);
    }

    public function estaSuspendido(?string $ahora = null): bool
    {
        if ($this->suspension_hasta === null) {
            return $this->suspendida_desde !== null;
        }

        $referencia = $ahora !== null ? \Illuminate\Support\Carbon::parse($ahora) : now();

        return $referencia->lessThan($this->suspension_hasta);
    }

    public function motivoDeBloqueo(): MotivoBloqueoIngreso
    {
        if (! $this->activo) {
            return MotivoBloqueoIngreso::SinMembresia;
        }

        if ($this->estaSuspendido()) {
            return MotivoBloqueoIngreso::Suspendida;
        }

        $membresia = $this->membresias()->first();

        if ($membresia === null) {
            return MotivoBloqueoIngreso::SinMembresia;
        }

        if ($membresia->estado === EstadoMembresia::Terminada) {
            return MotivoBloqueoIngreso::Terminada;
        }

        if (! $membresia->cubreHoy()) {
            return MotivoBloqueoIngreso::PeriodoVencido;
        }

        return MotivoBloqueoIngreso::Ninguno;
    }
}