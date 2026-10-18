<?php

declare(strict_types=1);

namespace App\Http\Requests\Visitas;

use App\Enums\CanalAcceso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del ingreso de un invitado.
 *
 * MÍNIMO DE DATOS, A PROPÓSITO
 * ----------------------------
 * Solo se pide el nombre. El gimnasio no necesita el documento ni el
 * teléfono de alguien que viene una vez, y pedir esos datos sería tomar
 * datos personales sin un motivo que los justifique (Ley 19.628,
 * finalidad). Ver docs/07, sección 3.
 */
class RegistrarInvitadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->puedeRegistrarAcceso() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:120'],
            'acompana_a_socio_id' => ['nullable', 'uuid'],
            'entrada_en' => ['nullable', 'date'],
            'salida_en' => ['nullable', 'date', 'after:entrada_en'],
            'canal' => ['nullable', Rule::enum(CanalAcceso::class)],
            'motivo' => ['nullable', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nombre.required' => 'El invitado necesita un nombre para quedar registrado.',
            'salida_en.after' => 'La salida no puede ser anterior a la entrada.',
        ];
    }
}