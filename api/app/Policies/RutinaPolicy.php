<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RolUsuario;
use App\Models\Rutina;
use App\Models\Usuario;

/**
 * Permisos sobre rutinas.
 *
 * UN GIMNASIO NO ES UNA CLÍNICA
 * ----------------------------
 * Estas policies no restringen datos de salud porque no hay datos de salud
 * en el sistema. Una rutina es la lista de ejercicios con fecha, kilos y
 * repeticiones: el valor está en que el socio vea su progreso, no en
 * prescribirle nada. Ver docs/07, sección 3.
 *
 * El socio ve y edita su propia rutina; el coach ve las de los socios del
 * gimnasio; recepción no entra, porque no es su trabajo.
 */
class RutinaPolicy
{
    public function verIndice(Usuario $usuario): bool
    {
        return $usuario->rol->esPersonal() || $usuario->rol === RolUsuario::Socio;
    }

    public function ver(Usuario $usuario, Rutina $rutina): bool
    {
        if ($usuario->rol === RolUsuario::Socio) {
            return $this->esSuPropiaRutina($usuario, $rutina);
        }

        return $usuario->rol === RolUsuario::Coach || $usuario->rol === RolUsuario::Administrador;
    }

    public function crear(Usuario $usuario): bool
    {
        return $usuario->rol === RolUsuario::Socio
            || $usuario->rol === RolUsuario::Coach
            || $usuario->rol === RolUsuario::Administrador;
    }

    public function registrarSerie(Usuario $usuario, Rutina $rutina): bool
    {
        return $this->ver($usuario, $rutina);
    }

    private function esSuPropiaRutina(Usuario $usuario, Rutina $rutina): bool
    {
        $socioDelUsuario = \App\Models\Socio::query()
            ->where('usuario_id', $usuario->id)
            ->first();

        return $socioDelUsuario !== null && $socioDelUsuario->id === $rutina->socio_id;
    }
}