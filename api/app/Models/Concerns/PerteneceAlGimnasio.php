<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\Tenancy\ContextoGimnasio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Marca a los modelos cuyas filas son de un gimnasio.
 *
 * La tabla `tenants` NO usa este trait: se consulta antes de que exista
 * contexto de tenant (durante el inicio de sesión), y es justamente la
 * excepción que permite resolver a qué gimnasio pertenece la identidad.
 *
 * El trait completa `tenant_id` con el contexto de la conexión actual cuando
 * no se entrega explícitamente. No es la protección: la protección es la
 * Row Level Security, que con `WITH CHECK` rechaza cualquier fila con un
 * `tenant_id` distinto al contexto. Esto solo evita el error de traducción.
 *
 * @mixin Model
 */
trait PerteneceAlGimnasio
{
    public static function bootPerteneceAlGimnasio(): void
    {
        static::creating(function (Model $modelo): void {
            if ($modelo->getAttribute('tenant_id') !== null) {
                return;
            }

            $modelo->setAttribute('tenant_id', app(ContextoGimnasio::class)->gimnasioIdEnConexion());
        });
    }

    /** Un socio, una visita o un plan de OTRO gimnasio no existe: 404, no 403. */
    public function scopeDelGimnasio(Builder $consulta, string $tenantId): Builder
    {
        return $consulta->where('tenant_id', $tenantId);
    }

    public function esDelGimnasio(string $tenantId): bool
    {
        return $this->getAttribute('tenant_id') === $tenantId;
    }
}