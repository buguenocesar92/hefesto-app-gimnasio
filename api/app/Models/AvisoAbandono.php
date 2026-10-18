<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PerteneceAlGimnasio;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aviso de abandono: la señal que este producto existe para encontrar.
 *
 * No es un envío de correo ni una notificación: es una fila que dice "este
 * socio tiene una membresía vigente y no viene desde hace 23 días". El dueño
 * la ve en el panel y decide qué hacer. El sistema no llama a nadie ni manda
 * mensajes por su cuenta: eso se decide con el cliente, no se impone.
 *
 * Se registra con fecha (detectado_en) y se cierra cuando el socio vuelve
 * (cerrado_en). Así se puede medir si el aviso sirvió de algo, que es lo
 * único que permite decidir si se sigue pagando el sistema.
 */
class AvisoAbandono extends Model
{
    use HasUuids;
    use PerteneceAlGimnasio;

    protected $table = 'avisos_abandono';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'ultima_visita_en' => 'datetime',
            'dias_sin_venir' => 'integer',
            'detectado_en' => 'datetime',
            'cerrado_en' => 'datetime',
            'pago_al_dia' => 'boolean',
        ];
    }

    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    public function estaAbierto(): bool
    {
        return $this->cerrado_en === null;
    }
}