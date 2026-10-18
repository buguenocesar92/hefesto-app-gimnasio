<?php

declare(strict_types=1);

namespace App\Http\Requests\Visitas;

use App\Enums\CanalAcceso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de una entrada.
 *
 * LA REGLA DEL ACCESO MANUAL ESTÁ AQUÍ Y TAMBIÉN EN EL SERVICIO
 * -----------------------------------------------------------
 * Está en los dos lados a propósito: acá da un error de validación claro
 * antes de tocar la base, y en `RegistroVisita` porque el servicio se llama
 * también desde la consola, desde el comando por lotes y desde las pruebas,
 * y ninguno de esos caminos pasa por un FormRequest.
 *
 * El motivo obligatorio del acceso manual es lo que separa "el socio entró"
 * de "entró porque se cortó la luz y no había con quién registrarlo".
 */
class RegistrarEntradaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->puedeRegistrarAcceso() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'socio_id' => ['required', 'uuid'],
            'canal' => ['required', Rule::enum(CanalAcceso::class)],
            'momento' => ['nullable', 'date'],
            'dispositivo' => ['nullable', 'string', 'max:80'],
            // Solo aplica cuando el canal es manual.
            'motivo_manual' => [
                Rule::requiredIf($this->input('canal') === CanalAcceso::Manual->value),
                'nullable',
                'string',
                'max:200',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'socio_id.required' => 'Indique el socio que entra.',
            'motivo_manual.required' => 'Un acceso manual necesita un motivo: corte de energía o fallo del turno.',
            'momento.date' => 'La hora de entrada no es válida.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'socio_id' => 'socio',
            'canal' => 'canal de acceso',
            'momento' => 'hora de entrada',
            'dispositivo' => 'dispositivo',
            'motivo_manual' => 'motivo del acceso manual',
        ];
    }

    /** Fecha de la entrada, o el instante actual si no viene. */
    public function fecha(string $campo): ?\Illuminate\Support\Carbon
    {
        $valor = $this->input($campo);

        return $valor === null ? null : \Illuminate\Support\Carbon::parse((string) $valor);
    }
}