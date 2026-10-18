<?php

declare(strict_types=1);

namespace App\Http\Requests\Membresias;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro de un cobro.
 *
 * `periodo_hasta` es EXCLUSIVO y tiene que ser posterior a `periodo_desde`.
 * La validación de que el periodo no se solape con otro cobro la hace el
 * servicio, que es el que puede ver el historial completo.
 */
class RegistrarCobroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->puedeGestionarSocios() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'periodo_desde' => ['required', 'date'],
            'periodo_hasta' => ['required', 'date', 'after:periodo_desde'],
            'monto_clp' => ['required', 'integer', 'min:0'],
            'medio_pago' => ['nullable', 'string', 'max:40'],
            'pagado_en' => ['nullable', 'date'],
            'comprobante' => ['nullable', 'string', 'max:80'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'periodo_hasta.after' => 'El periodo termina el día que empieza el siguiente, no el mismo día.',
            'monto_clp.required' => 'Indique el monto cobrado en pesos.',
        ];
    }
}