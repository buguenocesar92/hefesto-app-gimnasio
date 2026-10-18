<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Un gimnasio. Es el tenant.
 *
 * NO lleva Row Level Security, a propósito: se consulta durante el inicio de
 * sesión, cuando todavía no hay contexto de tenant que poner en la conexión.
 * Es la misma excepción que ya está documentada y compensada en
 * apps/portal-autoservicio/docs/03-MULTITENANCY.md, y se compensa igual acá:
 * la vista `vw_gimnasios_publicos` expone solo tres columnas, y el rol de la
 * aplicación no es superusuario (lo comprueba
 * `php artisan gimnasio:verificar-aislamiento`).
 *
 * `zona_horaria` importa más de lo que parece: una visita que entra a las
 * 23:50 y sale a las 00:10 pertenece al día de la ENTRADA, y ese día se
 * calcula con la zona horaria del gimnasio, no con la del servidor.
 * Ver docs/02, sección 4.
 */
class Tenant extends Model
{
    use HasUuids;

    protected $table = 'tenants';

    protected $guarded = ['id'];

    protected $hidden = [
        'razon_social',
        'rut',
        'correo_contacto',
        'telefono_contacto',
    ];

    /**
     * Zona horaria por defecto de Chile continental.
     *
     * NOTA DE VERIFICACIÓN: el identificador `America/Santiago` viene de la
     * base de datos de zonas horarias IANA y es el que usa el TZ de PostgreSQL.
     * En Chile hay una hora de verano (CLST, UTC-3) y el cambio ocurre en
     * fechas que cambian cada año; por eso se guarda el NOMBRE de la zona y
     * no un desplazamiento fijo en horas. Un offset fijo dejaría todas las
     * visitas mal fechadas en el verano. La lista de zonas válidas se
     * comprueba con la vista `pg_timezone_names` en despliegue.
     */
    public const ZONA_HORARIA_POR_DEFECTO = 'America/Santiago';

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public static function normalizarSlug(string $slug): string
    {
        return Str::lower(trim($slug));
    }

    /** Configuración de negocio que el dueño puede cambiar sin tocar código. */
    public function diasSinVengarComoRiesgo(): int
    {
        return (int) ($this->dias_aviso_inactividad ?? 21);
    }

    /** Horas que puede seguir abierta una visita sin salida antes de cerrarla sola. */
    public function horasParaCerrarVisitaAbierta(): int
    {
        return (int) ($this->horas_visita_abierta_maxima ?? 8);
    }
}