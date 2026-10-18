<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Contexto de gimnasio de la petición.
 *
 * ESTA ES LA MISMA CLASE, CON EL MISMO MECANISMO, QUE LA DEL PORTAL DE
 * AUTOSERVICIO. Ver apps/portal-autoservicio/docs/03-MULTITENANCY.md y
 * apps/portal-autoservicio/api/app/Services/Tenancy/ContextoTenant.php.
 *
 * No se inventó un cuarto enfoque para este producto: se reutilizó el que ya
 * está probado y documentado, porque el problema es exactamente el mismo
 * (una base, varios clientes, datos que no se pueden cruzar).
 *
 * ALCANCE DE TRANSACCIÓN
 * ----------------------
 * Se usa `set_config(..., true)`: el valor desaparece al confirmar o revertir
 * la transacción. Con un `SET` normal (ámbito de sesión) el valor sobreviviría
 * a la petición, y el worker de colas —que reutiliza la misma conexión para
 * trabajos de gimnasios distintos— podría leer los datos de otro gimnasio.
 *
 * Por eso `establecer()` EXIGE estar dentro de una transacción y lanza
 * excepción si no lo está. Falla cerrado, que es lo que importa en un candado.
 */
class ContextoGimnasio
{
    /** Variable de sesión de PostgreSQL que lee la política RLS. */
    public const VARIABLE = 'app.tenant_id';

    /**
     * Fija el gimnasio de la petición en la conexión actual.
     *
     * @throws RuntimeException si no hay una transacción abierta.
     */
    public function establecer(string $gimnasioId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(
                'No se puede fijar el contexto de gimnasio fuera de una transaccion. '
                . 'Use App\Services\Tenancy\ContextoGimnasio::ejecutarAislado().'
            );
        }

        DB::select('SELECT set_config(?, ?, true)', [self::VARIABLE, $gimnasioId]);
    }

    /**
     * Ejecuta una operación dentro de una transacción con el gimnasio fijo.
     * Al salir, PostgreSQL descarta el valor de la variable.
     *
     * @template T
     *
     * @param  Closure(): T  $operacion
     * @return T
     */
    public function ejecutarAislado(string $gimnasioId, Closure $operacion): mixed
    {
        return DB::transaction(function () use ($gimnasioId, $operacion) {
            $this->establecer($gimnasioId);

            return $operacion();
        });
    }

    /**
     * Lee la variable de sesión sin pasar por `public.app_tenant_id()`, que
     * lanza excepción cuando no hay contexto.
     *
     * Si devuelve null, ninguna política RLS de la base va a devolver filas:
     * el aislamiento está cerrado, no abierto.
     */
    public function gimnasioIdEnConexion(): ?string
    {
        $fila = DB::selectOne('SELECT current_setting(?, true) AS valor', [self::VARIABLE]);

        $valor = $fila->valor ?? null;

        return $valor === null || $valor === '' ? null : $valor;
    }

    /** Identificador del gimnasio autenticado, si lo hay. */
    public function gimnasioIdSolicitado(): ?string
    {
        return auth()->user()?->tenant_id;
    }
}