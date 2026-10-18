<?php

declare(strict_types=1);

namespace App\Http\Requests\Visitas;

use App\Enums\CanalAcceso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Carbon;

/**
 * Validación de una salida.
 *
 * Un acceso manual de salida también necesita motivo: si el turno se cayó al
 * volver, el socio salió pero nadie lo registró por dispositivo, y eso es un
 * dato distinto del que entró a las 19:00 y salió a las 20:00 por el lector.
 */
class CerrarVisitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->puedeRegistrarAcceso() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'canal' => ['required', Rule::enum(CanalAcceso::class)],
            'momento' => ['nullable', 'date'],
            'motivo' => [
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
            'motivo.required' => 'Un cierre manual necesita un motivo.',
            'momento.date' => 'La hora de salida no es válida.',
        ];
    }

    public function fecha(string $campo): ?Carbon
    {
        $valor = $this->input($campo);

        return $valor === null ? null : Carbon::parse((string) $valor);
    }
}