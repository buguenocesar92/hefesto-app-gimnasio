<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolUsuario;
use App\Models\Concerns\PerteneceAlGimnasio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Usuario del sistema: personal del gimnasio o socio con cuenta propia.
 *
 * La identidad la demuestra Authentik; el permiso lo decide el gimnasio, en
 * la columna `rol`. Un grupo de Authentik solo sirve para el primer alta del
 * administrador.
 *
 * En `usuarios` NO se guarda información de salud, ni lesiones, ni aptos ni
 * diagnósticos: el gimnasio no es una clínica. Ver docs/07.
 */
class Usuario extends Model
{
    use HasUuids;
    use PerteneceAlGimnasio;

    protected $table = 'usuarios';

    protected $guarded = ['id'];

    protected $hidden = ['authentik_sub'];

    protected function casts(): array
    {
        return [
            'rol' => RolUsuario::class,
            'activo' => 'boolean',
            'ultimo_acceso' => 'datetime',
        ];
    }

    public function socios(): HasOne
    {
        return $this->hasOne(Socio::class, 'usuario_id');
    }

    public function gymnasio(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function esAdministrador(): bool
    {
        return $this->rol === RolUsuario::Administrador;
    }

    /** Recepción y administración registran entradas; el coach no. */
    public function puedeRegistrarAcceso(): bool
    {
        return in_array($this->rol, [RolUsuario::Administrador, RolUsuario::Encargado], true);
    }

    public function puedeGestionarSocios(): bool
    {
        return in_array($this->rol, [RolUsuario::Administrador, RolUsuario::Encargado], true);
    }

    public function puedeVerRutinas(): bool
    {
        return $this->rol->esPersonal();
    }
}