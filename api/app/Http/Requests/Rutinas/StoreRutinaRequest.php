<?php

declare(strict_types=1);

namespace App\Http\Requests\Rutinas;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de una rutina.
 *
 * Solo el nombre, la descripción y desde cuándo rige. No hay campos de
 * intensidad, de DMS, de restricción por lesión ni de contraindicaciones:
 * un gimnasio no es una clínica. Ver docs/07, sección 3.
 */
class StoreRutinaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->puedeVerRutinas() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'vigente_desde' => ['nullable', 'date', 'before_or_equal:today'],
            'vigente_hasta' => ['nullable', 'date', 'after:vigente_desde'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nombre.required' => 'La rutina necesita un nombre.',
            'vigente_hasta.after' => 'La rutina no puede terminar antes de empezar.',
        ];
    }
}