<script setup lang="ts">
/**
 * EL PANEL
 * -------
 * La pantalla por la que el dueño entra todas las mañanas.
 *
 * ORDEN DE LA PANTALLA
 * -------------------
 * Arriba, los números de hoy. Abajo, la lista de socios que dejaron de venir.
 * Ese orden no es decorativo: la pregunta del dueño es "¿a quién llamo hoy?",
 * y la respuesta está en la segunda tabla, no en la primera.
 *
 * Las dos señales de cada socio de la lista de riesgo van juntas: días sin
 * venir Y si el pago está al día. Un socio que dejó de venir pero que sigue
 * pagando es recuperable y hay que llamarlo hoy. Uno que además está
 * atrasado es otro problema, y se ve distinto en la misma fila.
 */
const { panel } = useApi();

const { data, error, refresh, status } = await useAsyncData('panel', () => panel());

const dias = computed(() => {
    const serie = data.value?.serie_30_dias ?? {};

    return Object.entries(serie).map(([dia, total]) => ({ dia, total }));
});

const maximoDelMes = computed(() => Math.max(1, ...dias.value.map((d) => d.total)));

/** El día local, no el del servidor. */
function diaCorto(iso: string): string {
    return new Date(`${iso}T12:00:00`).toLocaleDateString('es-CL', {
        day: '2-digit',
        month: '2-digit',
    });
}

function horaDe(iso: string | null): string {
    if (iso === null) {
        return 'Nunca ha venido';
    }

    return new Date(iso).toLocaleString('es-CL', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

useHead({ title: 'Panel' });
</script>

<template>
    <div class="panel">
        <header class="panel__encabezado">
            <h1>Panel de hoy</h1>
            <p class="panel__fecha">
                {{ data?.hoy }} · {{ status === 'pending' ? 'Cargando…' : '' }}
            </p>
        </header>

        <div v-if="error" class="aviso aviso--error" role="alert">
            No se pudo cargar el panel. Si el problema sigue, revise el estado del servicio.
        </div>

        <!-- Números de hoy. -->
        <section class="tarjetas" aria-label="Resumen del día">
            <article class="tarjeta">
                <span class="tarjeta__valor">{{ data?.contadores?.visitas_hoy ?? '—' }}</span>
                <span class="tarjeta__etiqueta">Visitas hoy</span>
            </article>
            <article class="tarjeta">
                <span class="tarjeta__valor">{{ data?.contadores?.visitas_ayer ?? '—' }}</span>
                <span class="tarjeta__etiqueta">Visitas ayer</span>
            </article>
            <article class="tarjeta">
                <span class="tarjeta__valor">{{ data?.contadores?.visitas_abiertas ?? '—' }}</span>
                <span class="tarjeta__etiqueta">Adentro ahora</span>
            </article>
            <article class="tarjeta">
                <span class="tarjeta__valor">{{ data?.contadores?.socios_activos ?? '—' }}</span>
                <span class="tarjeta__etiqueta">Socios activos</span>
            </article>
            <article class="tarjeta">
                <span class="tarjeta__valor">{{ data?.contadores?.invitados_hoy ?? '—' }}</span>
                <span class="tarjeta__etiqueta">Invitados hoy</span>
            </article>
        </section>

        <!-- Serie de treinta días. -->
        <section class="grafico" aria-label="Visitas de los últimos treinta días">
            <h2>Últimos 30 días</h2>
            <ol class="grafico__barras">
                <li
                    v-for="dia in dias"
                    :key="dia.dia"
                    class="grafico__barra"
                    :title="`${dia.dia}: ${dia.total} visitas`"
                >
                    <span
                        class="grafico__relleno"
                        :style="{ height: `${(dia.total / maximoDelMes) * 100}%` }"
                    />
                    <span class="grafico__etiqueta">{{ diaCorto(dia.dia) }}</span>
                </li>
            </ol>
        </section>

        <!-- LA LISTA QUE IMPORTA. -->
        <section class="riesgo" aria-label="Socios que dejaron de venir">
            <h2>
                Dejaron de venir
                <span class="riesgo__conteo">{{ data?.en_riesgo?.length ?? 0 }}</span>
            </h2>

            <p v-if="(data?.en_riesgo?.length ?? 0) === 0" class="vacio">
                Nadie está en riesgo. Todos los socios con membresía vigente vinieron hace poco.
            </p>

            <table v-else class="tabla">
                <caption class="visualmente-oculto">
                    Socios con membresía vigente que llevan más días sin venir de los configurados
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Socio</th>
                        <th scope="col">Días sin venir</th>
                        <th scope="col">Última visita</th>
                        <th scope="col">Último pago</th>
                        <th scope="col">Situación</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="fila in data?.en_riesgo" :key="fila.aviso_id">
                        <th scope="row">
                            <NuxtLink :to="`/socios/${fila.socio_id}`">{{ fila.nombre }}</NuxtLink>
                        </th>
                        <td>{{ fila.dias_sin_venir }}</td>
                        <td>{{ horaDe(fila.ultima_visita_en) }}</td>
                        <td>{{ fila.ultimo_pago ? horaDe(fila.ultimo_pago) : 'Sin registro' }}</td>
                        <td>
                            <!--
                                Las dos señales juntas. "Al día pero no viene" es el
                                caso recuperable y el que hay que mirar primero.
                            -->
                            <span
                                v-if="fila.pago_al_dia"
                                class="etiqueta etiqueta--alerta"
                                title="Dejó de venir pero la membresía está al día: es recuperable."
                            >
                                Al día, pero no viene
                            </span>
                            <span v-else class="etiqueta etiqueta--vencido" title="También está atrasado en el pago.">
                               Atascado en pago
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Otro problema distinto, no el mismo. -->
        <section v-if="(data?.nunca_vinieron?.length ?? 0) > 0" class="nunca" aria-label="Socios que nunca han venido">
            <h2>Nunca han venido</h2>
            <p class="nota">
                No es lo mismo que dejar de venir: es que no arrancaron. Tiene otra solución.
            </p>
            <ul>
                <li v-for="socio in data?.nunca_vinieron" :key="socio.id">
                    <NuxtLink :to="`/socios/${socio.id}`">
                        {{ socio.nombre }} {{ socio.apellido ?? '' }}
                    </NuxtLink>
                    <span class="nota"> — membresía desde el {{ socio.iniciada_en }}</span>
                </li>
            </ul>
        </section>

        <button type="button" class="boton" @click="refresh()">Actualizar</button>
    </div>
</template>

<style scoped>
.panel { max-width: 72rem; margin: 0 auto; padding: 1.5rem; }
.panel__encabezado { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; }
.panel__fecha { color: #666; font-size: 0.9rem; }

.tarjetas { display: flex; flex-wrap: wrap; gap: 0.75rem; margin: 1.5rem 0; }
.tarjeta {
    flex: 1 1 8rem; display: flex; flex-direction: column; gap: 0.25rem;
    padding: 1rem; border: 1px solid #e2e2e2; border-radius: 0.5rem; background: #fff;
}
.tarjeta__valor { font-size: 2rem; font-weight: 700; line-height: 1; }
.tarjeta__etiqueta { color: #666; font-size: 0.85rem; }

.grafico__barras { display: flex; align-items: flex-end; gap: 2px; height: 8rem; list-style: none; padding: 0; }
.grafico__barra { flex: 1; display: flex; flex-direction: column; justify-content: flex-end; height: 100%; }
.grafico__relleno { display: block; background: #2f6f4f; border-radius: 2px 2px 0 0; min-height: 2px; }
.grafico__etiqueta { font-size: 0.6rem; color: #888; text-align: center; writing-mode: vertical-rl; }

.tabla { width: 100%; border-collapse: collapse; margin-top: 0.75rem; }
.tabla th, .tabla td { padding: 0.5rem 0.75rem; border-bottom: 1px solid #eee; text-align: left; }
.tabla thead th { font-size: 0.8rem; color: #666; text-transform: uppercase; letter-spacing: 0.03em; }

.etiqueta { display: inline-block; padding: 0.15rem 0.5rem; border-radius: 999px; font-size: 0.8rem; }
.etiqueta--alerta { background: #fdf1e3; color: #8a4b12; }
.etiqueta--vencido { background: #fbeaea; color: #8a1f1f; }

.vacio { color: #555; padding: 1rem 0; }
.nota { color: #666; font-size: 0.85rem; }
.aviso { padding: 0.75rem 1rem; border-radius: 0.5rem; margin: 1rem 0; }
.aviso--error { background: #fbeaea; color: #8a1f1f; }
.boton { margin-top: 1.5rem; padding: 0.5rem 1rem; border: 1px solid #2f6f4f; background: #2f6f4f; color: #fff; border-radius: 0.375rem; cursor: pointer; }
.visualmente-oculto { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
</style>