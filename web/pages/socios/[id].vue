<script setup lang="ts">
/**
 * FICHA DEL SOCIO
 *
 * Las dos señales van juntas y en el mismo lugar: "¿cuándo vino por última
 * vez?" y "¿cuándo pagó por última vez?".
 *
 * No van separadas porque el dueño las necesita juntas para decidir. Un
 * sistema que muestra solo la asistencia pierde la cobranza, y uno que
 * muestra solo la cobranza pierde la señal de abandono, que es la que se
 * puede recuperar.
 */
const ruta = useRoute();
const { socio, registrarSalida } = useApi();

const identificador = String(ruta.params.id ?? '');

const { data, error, refresh } = await useAsyncData(`socio-${identificador}`, () => socio(identificador));

const salidaError = ref<string | null>(null);

async function registrarLaSalida() {
    const visita = data.value?.socio?.ultima_visita;

    if (!visita?.abierta) {
        return;
    }

    salidaError.value = null;

    try {
        await registrarSalida(String(identificador), { canal: 'dispositivo' });
        await refresh();
    } catch (fallo: unknown) {
        salidaError.value = (fallo as { data?: { message?: string } })?.data?.message
            ?? 'No se pudo registrar la salida.';
    }
}

function fecha(iso: string | null): string {
    if (iso === null) {
        return '—';
    }

    return new Date(iso).toLocaleString('es-CL', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

useHead({ title: () => data.value?.socio.nombre ?? 'Socio' });
</script>

<template>
    <div class="ficha">
        <div v-if="error" class="aviso" role="alert">
            No encontrado.
        </div>

        <template v-else-if="data">
            <header>
                <h1>{{ data.socio.nombre }}</h1>
                <p class="subtitulo">
                    {{ data.socio.membresia?.plan ?? 'Sin plan' }}
                    <span v-if="data.socio.membresia" class="estado">
                        {{ data.socio.membresia.estado }}
                    </span>
                </p>
            </header>

            <!-- LAS DOS SEÑALES. -->
            <section class="senalas">
                <article class="senal">
                    <h2>Asistencia</h2>
                    <dl>
                        <dt>Última visita</dt>
                        <dd>{{ fecha(data.estado.ultima_visita_en) }}</dd>
                        <dt>Días sin venir</dt>
                        <dd>
                            <strong v-if="data.estado.dias_sin_venir !== null">
                                {{ data.estado.dias_sin_venir }}
                            </strong>
                            <strong v-else>Nunca ha venido</strong>
                        </dd>
                        <dt>Visitas registradas</dt>
                        <dd>{{ data.estado.visitas_totales }}</dd>
                    </dl>
                </article>

                <article class="senal">
                    <h2>Cobranza</h2>
                    <dl>
                        <dt>Último pago</dt>
                        <dd>{{ fecha(data.estado.ultimo_pago) }}</dd>
                        <dt>¿Puede entrar hoy?</dt>
                        <dd>
                            <strong v-if="data.estado.cubre_hoy">Sí</strong>
                            <strong v-else class="no">No: {{ data.estado.motivo_bloqueo }}</strong>
                        </dd>
                    </dl>
                </article>
            </section>

            <!-- Una visita abierta es un CASO, no un dato faltante. -->
            <section v-if="data.socio.ultima_visita?.abierta" class="abierta">
                <h2>Está adentro ahora</h2>
                <p>
                    Entró a las {{ fecha(data.socio.ultima_visita.entrada_en) }} y todavía no hay salida
                    registrada.
                </p>
                <button type="button" class="boton" @click="registrarLaSalida">
                    Registrar salida
                </button>
                <p v-if="salidaError" class="error" role="alert">{{ salidaError }}</p>
            </section>

            <section v-if="data.socio.suspension_hasta" class="suspension">
                <h2>Suspensión vigente</h2>
                <p>Hasta el {{ fecha(data.socio.suspension_hasta) }}.</p>
            </section>
        </template>
    </div>
</template>

<style scoped>
.ficha { max-width: 60rem; margin: 0 auto; padding: 1.5rem; }
.subtitulo { color: #666; display: flex; gap: 0.75rem; align-items: center; }
.estado { padding: 0.1rem 0.5rem; background: #eef2ee; border-radius: 999px; font-size: 0.8rem; }
.senalas { display: flex; flex-wrap: wrap; gap: 1rem; margin: 1.5rem 0; }
.senal { flex: 1 1 18rem; padding: 1rem; border: 1px solid #e2e2e2; border-radius: 0.5rem; }
.senal h2 { margin-top: 0; font-size: 1rem; text-transform: uppercase; letter-spacing: 0.04em; color: #666; }
dl { display: grid; grid-template-columns: auto 1fr; gap: 0.35rem 1rem; margin: 0; }
dt { color: #666; }
dd { margin: 0; }
.no { color: #8a1f1f; }
.abierta, .suspension { padding: 1rem; border-radius: 0.5rem; background: #fdf6ec; margin-bottom: 1rem; }
.abierta h2, .suspension h2 { margin-top: 0; }
.aviso { background: #fbeaea; color: #8a1f1f; padding: 0.75rem 1rem; border-radius: 0.5rem; }
.error { color: #8a1f1f; }
.boton { padding: 0.5rem 1rem; border: 0; background: #2f6f4f; color: #fff; border-radius: 0.375rem; cursor: pointer; font: inherit; }
</style>