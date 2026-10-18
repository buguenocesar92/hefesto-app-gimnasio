<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * Arranque de la aplicación para las pruebas.
 *
 * COMPROBACIÓN DE QUE LA BASE ES POSTGRESQL
 * -----------------------------------------
 * Este es el punto donde se evita el falso verde. Si alguien corre las
 * pruebas contra SQLite (por ejemplo, porque es más rápido en su
 * computador), la suite de aislamiento y la de visitas pasarían sin comprobar
 * NADA: SQLite no tiene Row Level Security ni índices únicos parciales.
 *
 * Las dos suites críticas se saltan SOLO si la base no es PostgreSQL, y el
 * salto dice por qué. Un salto es visible; un falso verde no lo es.
 */
trait CreatesApplication
{
    public function createApplication(): \Illuminate\Foundation\Application
    {
        $aplicacion = require __DIR__ . '/../bootstrap/app.php';
        $aplicacion->make(Kernel::class)->bootstrap();

        if ($this->laBaseNoEsPostgreSql()) {
            fwrite(STDERR, PHP_EOL . PHP_EOL
                . 'AVISO: la conexión de pruebas no es PostgreSQL.' . PHP_EOL
                . 'Las pruebas de aislamiento y de visitas NO se van a correr: pasarían sin comprobar nada.'
                . PHP_EOL . PHP_EOL);
        }

        return $aplicacion;
    }

    protected function laBaseNoEsPostgreSql(): bool
    {
        try {
            return DB::connection()->getDriverName() !== 'pgsql';
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * ¿Se puede probar contra esta base?
     *
     * La usan las suites críticas. Si devuelven `false`, la prueba se marca
     * como omitida con una razón, nunca como aprobada.
     */
    protected function sePuedeProbarConEstaBase(): bool
    {
        return ! $this->laBaseNoEsPostgreSql();
    }
}