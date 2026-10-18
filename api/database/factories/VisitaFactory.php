<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CanalAcceso;
use App\Models\Socio;
use App\Models\Visita;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visita>
 */
class VisitaFactory extends Factory
{
    protected $model = Visita::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $entrada = $this->faker->dateTimeBetween('-30 days', 'now');

        return [
            'socio_id' => Socio::factory(),
            'entrada_en' => $entrada,
            // Por defecto, una visita ya cerrada: el caso normal.
            'salida_en' => (clone $entrada)->modify('+1 hour'),
            'canal' => CanalAcceso::Dispositivo->value,
            'dispositivo' => 'turno-principal',
            'anulada' => false,
            'cerrada_por_sistema' => false,
        ];
    }

    /** Visita sin salida: el caso que hay que tratar, no ignorar. */
    public function abierta(): static
    {
        return $this->state(fn (): array => [
            'entrada_en' => now()->subHours(3),
            'salida_en' => null,
        ]);
    }

    /** Entrada anotada a mano, por corte de energía o turno fallenando. */
    public function manual(?string $motivo = 'Corte de energía; el turno no estaba leyendo.'): static
    {
        return $this->state(fn (): array => [
            'canal' => CanalAcceso::Manual->value,
            'motivo_manual' => $motivo,
        ]);
    }
}