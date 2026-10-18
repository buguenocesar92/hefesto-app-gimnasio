<?php

declare(strict_types=1);

namespace App\Http\Requests\Socios;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de socio.
 *
 * La suspensión es un PERIODO (`suspendida_desde` + `suspension_hasta`), no
 * una bandera. El dueño la levanta solo cuando llega la fecha, y mientras
 * tanto el socio sabe que tiene fecha de vuelta. Un campo `suspendido = true`
 * no puede contestar "¿cuándo vuelve?".
 */
class ActualizarSocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->puedeGestionarSocios() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $socio = $this->route('socio');

        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:120'],
            'apellido' => ['nullable', 'string', 'max:120'],
            'documento' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('socios', 'documento')
                    ->where('tenant_id', $this->user()->tenant_id)
                    ->ignore($socio?->id),
            ],
            'nacimiento' => ['nullable', 'date', 'before:today'],
            'correo' => ['nullable', 'email', 'max:160'],
            'telefono' => ['nullable', 'string', 'max:32'],
            'telefono_emergencia' => ['nullable', 'string', 'max:32'],
            'contacto_emergencia' => ['nullable', 'string', 'max:120'],
            'activo' => ['sometimes', 'boolean'],
            'suspendida_desde' => ['nullable', 'date'],
            'suspension_hasta' => ['nullable', 'date', 'after_or_equal:suspendida_desde'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'suspension_hasta.after_or_equal' => 'La suspensión no puede terminar antes de empezar.',
            'documento.unique' => 'Ya hay otro socio de este gimnasio con ese documento.',
        ];
    }
}