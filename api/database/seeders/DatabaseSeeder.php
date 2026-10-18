<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Semilla principal.
 *
 * Corre el gimnasio de ejemplo. Ver `GimnasioDemoSeeder`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(GimnasioDemoSeeder::class);
    }
}