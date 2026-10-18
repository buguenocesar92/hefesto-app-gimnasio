<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CanalAcceso;
use App\Enums\EstadoMembresia;
use App\Enums\RolUsuario;
use App\Models\Cobro;
use App\Models\Membresia;
use App\Models\PeriodoSinCobro;
use App\Models\Plan;
use App\Models\RegistroRutina;
use App\Models\Rutina;
use App\Models\Socio;
use App\Models\Tenant;
use App\Models\Usuario;
use App\Models\Visita;
use App\Services\Tenancy\ContextoGimnasio;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Un gimnasio de ejemplo, con socios, planes, visitas y una rutina.
 *
 * LOS DATOS NO SON INVENCIONADOS PARA QUE CUADRE
 * ----------------------------------------------
 * Los socios tienen una historia que el dueño puede reconocer de inmediato:
 * uno viene todas las semanas, otro vino dos veces y nunca volvió, otro está
 * al día con el pago pero lleva tres semanas sin aparecer. Con esos tres, el
 * panel muestra el producto funcionando apenas se levanta.
 *
 * NOTA SOBRE LA RLS
 * -----------------
 * Las tablas de negocio tienen Row Level Security, así que insertar filas
 * "a pelo" falla: `public.app_tenant_id()` no encuentra contexto y lanza
 * excepción. Por eso TODO lo de este seeder corre dentro de
 * `ContextoGimnasio::ejecutarAislado()`. No es un rodeo: es la misma vía que
 * usa la aplicación de verdad. La tabla `tenants` sí se puede escribir
 * directamente, porque no lleva RLS.
 *
 * ADVERTENCIA
 * -----------
 * Los usuarios de ejemplo tienen `authentik_sub` ficticios: NO pueden entrar
 * hasta que sus identidades existan en Authentik con ese mismo `sub`, o hasta
 * que se borre el seeder. Ver docs/04, sección "Datos de desarrollo".
 */
class GimnasioDemoSeeder extends Seeder
{
    public function run(): void
    {
        $contexto = new ContextoGimnasio();

        $gimnasio = Tenant::create([
            'slug' => 'gimnasio-fuerza',
            'nombre' => 'Gimnasio Fuerza',
            'razon_social' => 'Gimnasio Fuerza SpA',
            'rut' => '76.987.654-3',
            'zona_horaria' => 'America/Santiago',
            'correo_contacto' => 'contacto@gimnasiofuerza.cl',
            'telefono_contacto' => '+56 2 2345 6789',
            'dias_aviso_inactividad' => 21,
            'horas_visita_abierta_maxima' => 8,
            'activo' => true,
        ]);

        $contexto->ejecutarAislado($gimnasio->id, function () use ($gimnasio): void {
            $this->cargarGimnasio($gimnasio);
        });

        $this->command?->info('Gimnasio de ejemplo creado: "Gimnasio Fuerza" (slug: gimnasio-fuerza).');
    }

    private function cargarGimnasio(Tenant $gimnasio): void
    {
        // --- Personal ---------------------------------------------------------
        $administrador = Usuario::create([
            'authentik_sub' => (string) \Illuminate\Support\Str::uuid(),
            'correo' => 'duena@gimnasiofuerza.cl',
            'nombre' => 'María',
            'apellido' => 'Fernández',
            'rol' => RolUsuario::Administrador->value,
            'activo' => true,
            'ultimo_acceso' => now(),
        ]);

        $recepcion = Usuario::create([
            'authentik_sub' => (string) \Illuminate\Support\Str::uuid(),
            'correo' => 'recepcion@gimnasiofuerza.cl',
            'nombre' => 'Jorge',
            'apellido' => 'Muñoz',
            'telefono' => '+56 9 8877 6655',
            'rol' => RolUsuario::Encargado->value,
            'activo' => true,
            'ultimo_acceso' => now(),
        ]);

        $coach = Usuario::create([
            'authentik_sub' => (string) \Illuminate\Support\Str::uuid(),
            'correo' => 'coach@gimnasiofuerza.cl',
            'nombre' => 'Camila',
            'apellido' => 'Rojas',
            'rol' => RolUsuario::Coach->value,
            'activo' => true,
            'ultimo_acceso' => now(),
        ]);

        // --- Planes -----------------------------------------------------------
        $planMensual = Plan::create([
            'nombre' => 'Mensual',
            'descripcion' => 'Acceso ilimitado durante 30 días.',
            'precio_clp' => 29900,
            'dias_periodo' => 30,
            'visitas_maximas_periodo' => null,
            'activo' => true,
        ]);

        $planTrimestral = Plan::create([
            'nombre' => 'Trimestral',
            'descripcion' => 'Acceso ilimitado durante 90 días, con un mes gratis de diciembre.',
            'precio_clp' => 74900,
            'dias_periodo' => 90,
            'visitas_maximas_periodo' => null,
            'activo' => true,
        ]);

        $planDiario = Plan::create([
            'nombre' => 'Diario',
            'descripcion' => 'Ocho visitas por mes.',
            'precio_clp' => 18900,
            'dias_periodo' => 30,
            'visitas_maximas_periodo' => 8,
            'activo' => true,
        ]);

        // --- Socio 1: el que viene todas las semanas --------------------------
        $constante = Socio::create([
            'nombre' => 'Andrés',
            'apellido' => 'Peralta',
            'documento' => '15.221.334-5',
            'nacimiento' => '1992-03-14',
            'correo' => 'andres.peralta@ejemplo.cl',
            'telefono' => '+56 9 5544 3322',
            'activo' => true,
        ]);

        $membresiaConstante = Membresia::create([
            'socio_id' => $constante->id,
            'plan_id' => $planMensual->id,
            'iniciada_en' => now()->subMonths(4)->toDateString(),
            'estado' => EstadoMembresia::Activa->value,
        ]);

        // Tres meses de cobros, con el mes en curso todavía pagado.
        $meses = [
            now()->subMonths(3)->startOfMonth(),
            now()->subMonths(2)->startOfMonth(),
            now()->subMonths(1)->startOfMonth(),
            now()->startOfMonth(),
        ];

        foreach ($meses as $inicio) {
            Cobro::create([
                'membresia_id' => $membresiaConstante->id,
                'periodo_desde' => $inicio->toDateString(),
                'periodo_hasta' => $inicio->copy()->addMonth()->toDateString(),
                'monto_clp' => 29900,
                'medio_pago' => 'tarjeta',
                'pagado_en' => $inicio->copy()->addDay(),
                'anulado' => false,
                'registrado_por' => $recepcion->id,
            ]);
        }

        // Cuatro visitas por semana durante las últimas tres semanas: este es
        // el socio que el sistema NO tiene que marcar.
        for ($semana = 1; $semana <= 3; $semana++) {
            foreach ([1, 3, 5] as $dia) {
                $entrada = now()->subWeeks($semana)->startOfDay()->addHours(19)->addMinutes(5);

                $visita = Visita::create([
                    'socio_id' => $constante->id,
                    'entrada_en' => $entrada,
                    'salida_en' => $entrada->copy()->addHour(),
                    'canal' => CanalAcceso::Dispositivo->value,
                    'dispositivo' => 'turno-principal',
                    'registrado_por' => null,
                    'anulada' => false,
                ]);

                \App\Models\EventoAcceso::create([
                    'visita_id' => $visita->id,
                    'socio_id' => $constante->id,
                    'tipo' => 'entrada',
                    'canal' => CanalAcceso::Dispositivo->value,
                    'ocurrido_en' => $entrada,
                ]);

                \App\Models\EventoAcceso::create([
                    'visita_id' => $visita->id,
                    'socio_id' => $constante->id,
                    'tipo' => 'salida',
                    'canal' => CanalAcceso::Dispositivo->value,
                    'ocurrido_en' => $entrada->copy()->addHour(),
                ]);
            }
        }

        // --- Socio 2: el que dejó de venir, pero está al día con el pago ------
        // ESTE ES EL CASO QUE EL PRODUCTO EXISTE PARA VER. En el panel aparece
        // arriba, con "pago al día" en verde, que es la combinación
        // recuperable.
        $enRiesgo = Socio::create([
            'nombre' => 'Patricia',
            'apellido' => 'Silva',
            'documento' => '18.774.221-0',
            'nacimiento' => '1988-07-02',
            'correo' => 'patricia.silva@ejemplo.cl',
            'telefono' => '+56 9 2211 8899',
            'activo' => true,
        ]);

        $membresiaEnRiesgo = Membresia::create([
            'socio_id' => $enRiesgo->id,
            'plan_id' => $planMensual->id,
            'iniciada_en' => now()->subMonths(6)->toDateString(),
            'estado' => EstadoMembresia::Activa->value,
        ]);

        $inicioMesActual = now()->startOfMonth();

        Cobro::create([
            'membresia_id' => $membresiaEnRiesgo->id,
            'periodo_desde' => $inicioMesActual->toDateString(),
            'periodo_hasta' => $inicioMesActual->copy()->addMonth()->toDateString(),
            'monto_clp' => 29900,
            'medio_pago' => 'efectivo',
            'pagado_en' => $inicioMesActual->copy()->addDay(),
            'anulado' => false,
            'registrado_por' => $recepcion->id,
        ]);

        // Última visita hace 26 días: pasa el umbral de 21 del gimnasio.
        $ultimaVisita = now()->subDays(26)->setTime(18, 30);

        Visita::create([
            'socio_id' => $enRiesgo->id,
            'entrada_en' => $ultimaVisita,
            'salida_en' => $ultimaVisita->copy()->addMinutes(75),
            'canal' => CanalAcceso::Dispositivo->value,
            'dispositivo' => 'turno-principal',
            'anulada' => false,
        ]);

        // --- Socio 3: mensual del año pasado, con el mes gratis de diciembre --
        // ESTE SOCIO MUESTRA POR QUÉ EL MES GRATIS NO ES UNA CASILLA.
        // Compró en noviembre, y en diciembre no se le cobra: el cobro de
        // enero arranca el día que el mes gratis termina.
        $trimestral = Socio::create([
            'nombre' => 'Sebastián',
            'apellido' => 'Contreras',
            'documento' => '17.445.908-2',
            'nacimiento' => '1995-11-23',
            'telefono' => '+56 9 6677 1122',
            'activo' => true,
        ]);

        $membresiaTrimestral = Membresia::create([
            'socio_id' => $trimestral->id,
            'plan_id' => $planTrimestral->id,
            'iniciada_en' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'estado' => EstadoMembresia::Activa->value,
        ]);

        $noviembre = now()->subMonths(2)->startOfMonth();
        $diciembre = now()->subMonths(1)->startOfMonth();
        $enero = now()->startOfMonth();

        Cobro::create([
            'membresia_id' => $membresiaTrimestral->id,
            'periodo_desde' => $noviembre->toDateString(),
            'periodo_hasta' => $diciembre->toDateString(),
            'monto_clp' => 74900,
            'medio_pago' => 'transferencia',
            'pagado_en' => $noviembre->copy()->addDay(2),
            'anulado' => false,
            'registrado_por' => $recepcion->id,
        ]);

        // El mes gratis: un PERIODO, no una bandera. Va de diciembre a enero,
        // y el socio mantiene el derecho a entrar durante todo ese tiempo.
        PeriodoSinCobro::create([
            'membresia_id' => $membresiaTrimestral->id,
            'desde' => $diciembre->toDateString(),
            'hasta' => $enero->toDateString(),
            'motivo' => 'mes_gratis',
            'nota' => 'Mes gratis de diciembre pactado en el contrato.',
            'registrado_por' => $administrador->id,
        ]);

        Cobro::create([
            'membresia_id' => $membresiaTrimestral->id,
            'periodo_desde' => $enero->toDateString(),
            'periodo_hasta' => $enero->copy()->addMonths(2)->toDateString(),
            'monto_clp' => 74900,
            'medio_pago' => 'transferencia',
            'pagado_en' => $enero->copy()->addDay(1),
            'anulado' => false,
            'registrado_por' => $recepcion->id,
        ]);

        // --- Socio 4: hoy, con una visita que cruza la medianoche -----------
        // A las 23:50 entra y a las 00:10 sale. Es UNA visita del día de la
        // entrada, no dos visitas de dos días.
        $cruzaMedianoche = Socio::create([
            'nombre' => 'Valentina',
            'apellido' => 'Ríos',
            'documento' => '19.663.120-7',
            'nacimiento' => '1999-01-08',
            'activo' => true,
        ]);

        Membresia::create([
            'socio_id' => $cruzaMedianoche->id,
            'plan_id' => $planDiario->id,
            'iniciada_en' => now()->subMonth()->toDateString(),
            'estado' => EstadoMembresia::Activa->value,
        ]);

        Cobro::create([
            'membresia_id' => Membresia::query()->where('socio_id', $cruzaMedianoche->id)->value('id'),
            'periodo_desde' => now()->subMonth()->toDateString(),
            'periodo_hasta' => now()->addDays(6)->toDateString(),
            'monto_clp' => 18900,
            'medio_pago' => 'tarjeta',
            'pagado_en' => now()->subMonth(),
            'anulado' => false,
            'registrado_por' => $recepcion->id,
        ]);

        $entradaTarde = now()->subHour()->setTime(23, 50);

        $visitaNocturna = Visita::create([
            'socio_id' => $cruzaMedianoche->id,
            'entrada_en' => $entradaTarde,
            'salida_en' => $entradaTarde->copy()->addMinutes(20),
            'canal' => CanalAcceso::Manual->value,
            'registrado_por' => $recepcion->id,
            'motivo_manual' => 'El turno no leía la huella; se anotó a mano.',
            'anulada' => false,
        ]);

        \App\Models\EventoAcceso::create([
            'visita_id' => $visitaNocturna->id,
            'socio_id' => $cruzaMedianoche->id,
            'tipo' => 'entrada',
            'canal' => CanalAcceso::Manual->value,
            'registrado_por' => $recepcion->id,
            'motivo' => 'El turno no leía la huella; se anotó a mano.',
            'ocurrido_en' => $entradaTarde,
        ]);

        \App\Models\EventoAcceso::create([
            'visita_id' => $visitaNocturna->id,
            'socio_id' => $cruzaMedianoche->id,
            'tipo' => 'salida',
            'canal' => CanalAcceso::Manual->value,
            'registrado_por' => $recepcion->id,
            'ocurrido_en' => $entradaTarde->copy()->addMinutes(20),
        ]);

        // --- Socio 5: suspendido por el dueño ---------------------------------
        $suspendido = Socio::create([
            'nombre' => 'Cristián',
            'apellido' => 'Araya',
            'documento' => '14.332.887-K',
            'nacimiento' => '1983-09-30',
            'suspendida_desde' => now()->subDays(10),
            'suspension_hasta' => now()->addDays(20),
            'activo' => true,
        ]);

        $membresiaSuspendida = Membresia::create([
            'socio_id' => $suspendido->id,
            'plan_id' => $planMensual->id,
            'iniciada_en' => now()->subMonths(3)->toDateString(),
            'estado' => EstadoMembresia::Suspendida->value,
            'suspendida_desde' => now()->subDays(10),
            'suspension_hasta' => now()->addDays(20),
        ]);

        Cobro::create([
            'membresia_id' => $membresiaSuspendida->id,
            'periodo_desde' => now()->startOfMonth()->toDateString(),
            'periodo_hasta' => now()->startOfMonth()->copy()->addMonth()->toDateString(),
            'monto_clp' => 29900,
            'medio_pago' => 'efectivo',
            'pagado_en' => now()->startOfMonth()->addDay(),
            'anulado' => false,
            'registrado_por' => $recepcion->id,
        ]);

        // --- Socio 6: nunca ha venido ----------------------------------------
        // Es un problema distinto del abandono y aparece aparte en el panel.
        $nuncaVino = Socio::create([
            'nombre' => 'Fernanda',
            'apellido' => 'Vera',
            'documento' => '20.118.554-3',
            'nacimiento' => '2000-04-17',
            'activo' => true,
        ]);

        $membresiaNuncaVino = Membresia::create([
            'socio_id' => $nuncaVino->id,
            'plan_id' => $planMensual->id,
            'iniciada_en' => now()->subDays(12)->toDateString(),
            'estado' => EstadoMembresia::Activa->value,
        ]);

        Cobro::create([
            'membresia_id' => $membresiaNuncaVino->id,
            'periodo_desde' => now()->subDays(12)->toDateString(),
            'periodo_hasta' => now()->addDays(18)->toDateString(),
            'monto_clp' => 29900,
            'medio_pago' => 'tarjeta',
            'pagado_en' => now()->subDays(12),
            'anulado' => false,
            'registrado_por' => $recepcion->id,
        ]);

        // --- Una rutina, para el socio constante ------------------------------
        $rutina = Rutina::create([
            'socio_id' => $constante->id,
            'coach_id' => $coach->id,
            'nombre' => 'Rutina de fuerza, tres veces por semana',
            'descripcion' => 'Cuatro ejercicios, tres series cada uno, progresando de peso.',
            'vigente_desde' => now()->subMonths(2)->toDateString(),
            'activa' => true,
        ]);

        $ejercicios = [
            ['Press banca', 60.0, 8],
            ['Sentadilla trasera', 80.0, 10],
            ['Remo con barra', 45.0, 10],
            ['Peso muerto rumano', 70.0, 8],
        ];

        foreach (range(1, 8) as $semana) {
            $fecha = now()->subWeeks($semana)->startOfDay();

            foreach ($ejercicios as $indice => $ejercicio) {
                // Progreso: cada semana un poco más de peso.
                $peso = $ejercicio[1] + ($semana * 2.5);

                foreach (range(1, 3) as $serie) {
                    RegistroRutina::create([
                        'rutina_id' => $rutina->id,
                        'socio_id' => $constante->id,
                        'ejercicio' => $ejercicio[0],
                        'fecha' => $fecha->toDateString(),
                        'serie' => $serie,
                        'repeticiones' => $ejercicio[2],
                        'peso_kg' => $peso,
                        'canal' => 'aplicacion',
                    ]);
                }
            }
        }

        // --- Un invitado, que NO cuenta como visita de socio ------------------
        // Vive en otra tabla. Ningún conteo de socios lo puede ver.
        \App\Models\IngresoInvitado::create([
            'acompana_a_socio_id' => $constante->id,
            'nombre' => 'Invitado de Andrés',
            'entrada_en' => now()->subDays(3)->setTime(20, 0),
            'salida_en' => now()->subDays(3)->setTime(21, 30),
            'canal' => CanalAcceso::Manual->value,
            'registrado_por' => $recepcion->id,
            'motivo' => 'Traía un acompañante.',
        ]);

        $this->command?->info(sprintf(
            '  %d socios, %d planes, %d membresías, %d visitas, %d registros de rutina y 1 invitado.',
            Socio::count(),
            Plan::count(),
            Membresia::count(),
            Visita::count(),
            RegistroRutina::count()
        ));
    }
}