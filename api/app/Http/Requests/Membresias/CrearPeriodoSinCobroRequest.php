<?php

declare(strict_types=1);

namespace App\Http\Requests\Membresias;

use App\Enums\MotivoPeriodoSinCobro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de un periodo sin cobro.
 *
 * ESTE ES EL FORMULARIO DEL MES GRATIS
 * -----------------------------------
 * Pide un rango de fechas, no una casilla de "gratis". Con el rango se
 * puede responder hasta cuándo dura y cuándo vuelve el cobro; con una
 * casilla, no. Ver docs/02, sección 7.
 */
class CrearPeriodoSinCobroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->puedeGestionarSocios() ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after:desde'],
            'motivo' => ['required', Rule::enum(MotivoPeriodoSinCobro::class)],
            'nota' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'desde.required' => 'El periodo sin cobro necesita una fecha de inicio.',
            'hasta.after' => 'El periodo sin cobro tiene que terminar después de empezar.',
            'motivo.required' => 'Diga por qué no se cobra: mes gratis, promoción, cortesía o cierre técnico.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'desde' => 'inicio del periodo sin cobro',
            'hasta' => 'fin del periodo sin cobro',
            'motivo' => 'motivo',
        ];
    }
}