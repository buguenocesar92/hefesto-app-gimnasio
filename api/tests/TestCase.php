<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Clase base de las pruebas.
 *
 * Las pruebas de este proyecto corren contra PostgreSQL de verdad, porque el
 * aislamiento entre gimnasios y la regla de una visita abierta están
 * implementados con Row Level Security y con un índice único parcial, y
 * ninguna de las dos cosas existe en SQLite.
 *
 * `tests/TestCase.php` falla de forma ruidosa si la conexión de pruebas no es
 * PostgreSQL, en vez de dejar pasar las pruebas sin comprobar nada.
 */
abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
}