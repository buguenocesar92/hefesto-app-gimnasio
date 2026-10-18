<?php

declare(strict_types=1);

namespace App\Http\Requests\Rutinas;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Registro de una serie.
 *
 * `peso_kg` es opcional a propósito: hay ejercicios de peso corporal y hay
 * series de movilidad donde no hay nada que anotar. Lo que no es opcional son
 * las repeticiones, porque sin ellas el registro no sirve para nada.
 */
class RegistrarSerieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->puedeVerRutinas() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ejercicio' => ['required', 'string', 'max:120'],
            'fecha' => ['nullable', 'date', 'before_or_equal:today'],
            'serie' => ['nullable', 'integer', 'min:1', 'max:50'],
            'repeticiones' => ['required', 'integer', 'min:1', 'max:500'],
            'peso_kg' => ['nullable', 'numeric', 'min:0', 'max:600'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'repeticiones.required' => 'Anote cuántas repeticiones hizo.',
            'peso_kg.numeric' => 'El peso va en kilos y no puede ser negativo.',
        ];
    }

    public function fecha(string $campo): ?Carbon
    {
        $valor = $this->input($campo);

        return $valor === null ? null : Carbon::parse((string) $valor);
    }
}