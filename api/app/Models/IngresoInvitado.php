<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CanalAcceso;
use App\Models\Concerns\PerteneceAlGimnasio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ingreso de invitado.
 *
 * UN INVITADO NO ES UN SOCIO, Y POR ESO NO ESTÁ EN ESTA TABLA.
 *
 * La decisión es de modelo de datos, no de estilo: si el invitado se guardara
 * en `visitas` con un `socio_id` nulo, cada conteo del sistema tendría que
 * acordarse de excluirlo, y el día que uno se olvide, el panel muestra más
 * visitas de las que hubo. Aquí los invitados viven en su propia tabla, y
 * ninguna consulta de asistencia de socios los puede tocar.
 *
 * Consecuencia que hay que aceptar: un invitado no tiene historial ni
 * membresía, y no cuenta para nada. Si mañana el gimnasio quiere contar
 * "personas que entraron", esa es OTRA métrica, aparte.
 *
 * Datos: solo el nombre. No se pide documento ni teléfono, porque el
 * gimnasio no los necesita para dejar pasar a alguien. Ver docs/07.
 */
class IngresoInvitado extends Model
{
    use HasUuids;
    use PerteneceAlGimnasio;

    protected $table = 'ingresos_invitados';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entrada_en' => 'datetime',
            'salida_en' => 'datetime',
            'canal' => CanalAcceso::class,
        ];
    }

    public function socioAcompanante(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'acompana_a_socio_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'registrado_por');
    }
}