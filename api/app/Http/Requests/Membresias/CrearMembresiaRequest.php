<?php

declare(strict_types=1);

namespace App\Http\Requests\Membresias;

use App\Enums\MotivoPeriodoSinCobro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de una membresía.
 *
 * La membresía NO guarda la fecha de pago: guarda el plan y el día en que
 * empezó. El pago va en `cobros`. Ver docs/02, sección 6.
 */
class CrearMembresiaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->puedeGestionarSocios() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'uuid'],
            'iniciada_en' => ['required', 'date', 'before_or_equal:today'],
            'nota' => ['nullable', 'string', 'max:1000'],
            // El mes gratis se carga después, como periodo con inicio y fin.
            // Se acepta acá solo para no obligar al dueño a hacer dos
            // screens, pero se guarda en su propia tabla.
            'mes_gratis_desde' => ['nullable', 'date'],
            'mes_gratis_hasta' => ['nullable', 'date', 'after:mes_gratis_desde'],
        ];
    }
}