<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Socio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Socio>
 */
class SocioFactory extends Factory
{
    protected $model = Socio::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nombre' => $this->faker->firstName(),
            'apellido' => $this->faker->lastName(),
            'documento' => $this->faker->unique()->numerify('##.###.###-#'),
            'nacimiento' => $this->faker->dateTimeBetween('-45 years', '-18 years'),
            'correo' => $this->faker->unique()->safeEmail(),
            'telefono' => '+56 9 ' . $this->faker->numerify('#### ####'),
            'activo' => true,
        ];
    }

    /** Socio suspendido por el dueño, con fecha de vuelta. */
    public function suspendido(int $diasHastaVolver = 20): static
    {
        return $this->state(fn (): array => [
            'suspendida_desde' => now(),
            'suspension_hasta' => now()->addDays($diasHastaVolver),
        ]);
    }
}