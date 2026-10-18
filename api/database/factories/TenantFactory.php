<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $nombre = $this->faker->unique()->company();

        return [
            'slug' => \Illuminate\Support\Str::slug($nombre),
            'nombre' => $nombre,
            'razon_social' => $nombre . ' SpA',
            'zona_horaria' => Tenant::ZONA_HORARIA_POR_DEFECTO,
            'correo_contacto' => $this->faker->unique()->safeEmail(),
            'telefono_contacto' => '+56 2 2' . $this->faker->numerify('###') . ' 0000',
            'dias_aviso_inactividad' => 21,
            'horas_visita_abierta_maxima' => 8,
            'activo' => true,
        ];
    }
}