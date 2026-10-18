<?php

declare(strict_types=1);

namespace App\Http\Requests\Socios;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de socio.
 *
 * Lo que NO se pide, también es una decisión: no hay diagnóstico, ni lesión,
 * ni aptitud, ni peso. Un gimnasio no es una clínica. Ver docs/07.
 *
 * `nacimiento` existe porque Chile tiene tarifa estudiante, y esa tarifa se
 * aplica con la edad, no con un documento de salud.
 */
class StoreSocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->puedeGestionarSocios() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:120'],
            'apellido' => ['nullable', 'string', 'max:120'],
            'documento' => [
                'nullable',
                'string',
                'max:20',
                // Único por gimnasio, no global: el índice de la tabla es
                // (tenant_id, documento). Ver migración 4.
                Rule::unique('socios', 'documento')->where('tenant_id', $this->user()->tenant_id),
            ],
            'nacimiento' => ['nullable', 'date', 'before:today'],
            'correo' => ['nullable', 'email', 'max:160'],
            'telefono' => ['nullable', 'string', 'max:32'],
            'telefono_emergencia' => ['nullable', 'string', 'max:32'],
            'contacto_emergencia' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nombre.required' => 'El socio necesita un nombre.',
            'documento.unique' => 'Ya hay un socio de este gimnasio con ese documento.',
            'nacimiento.before' => 'La fecha de nacimiento no puede ser futura.',
            'correo.email' => 'El correo no tiene un formato válido.',
        ];
    }
}