<script setup lang="ts">
/**
 * REGISTRAR UNA ENTRADA
 *
 * Esta pantalla es la que recepción usa con dos personas esperando. Por eso
 * el acceso manual pide el motivo SIEMPRE: si el turno se cayó, hay que
 * decirlo, porque en seis meses esa es la única forma de distinguir un corte
 * de luz de un socio que se coló.
 *
 * El formulario no ofrece "entrar y listo" cuando el canal es manual: el
 * campo de motivo aparece con el motivo ya escrito como ejemplo, para que
 * recepción escriba "corte de energía" y no "porque sí".
 */
const { socios, registrarEntrada } = useApi();
const router = useRouter();

const { data } = await useAsyncData('socios-buscar', () => socios({ por_pagina: '50' }));

const busqueda = ref('');
const socioElegido = ref<string | null>(null);
const canal = ref<'dispositivo' | 'manual' | 'aplicacion'>('dispositivo');
const dispositivo = ref('turno-principal');
const motivo = ref('');
const enviando = ref(false);
const error = ref<string | null>(null);

const motivosSugeridos = [
    'Corte de energía.',
    'El turno no leía la huella.',
    'La puerta estaba abierta.',
];

const filtrados = computed(() => {
    const todos = data.value?.data ?? [];
    const texto = busqueda.value.trim().toLowerCase();

    if (texto === '') {
        return todos;
    }

    return todos.filter((socio) => socio.nombre.toLowerCase().includes(texto));
});

const necesitaMotivo = computed(() => canal.value === 'manual');

async function enviar() {
    if (socioElegido.value === null) {
        error.value = 'Elija un socio.';

        return;
    }

    if (necesitaMotivo.value && motivo.value.trim() === '') {
        error.value = 'Un acceso manual necesita un motivo.';

        return;
    }

    error.value = null;
    enviando.value = true;

    try {
        await registrarEntrada({
            socio_id: socioElegido.value,
            canal: canal.value,
            dispositivo: canal.value === 'dispositivo' ? dispositivo.value : null,
            motivo_manual: necesitaMotivo.value ? motivo.value.trim() : null,
        });

        await router.push('/visitas');
    } catch (fallo: unknown) {
        // La API responde 422 con un mensaje escrito para la persona de
        // recepción ("ya tiene una visita abierta desde las 19:12"). Se
        // muestra tal cual, sin reescribirlo.
        const mensaje = (fallo as { data?: { message?: string } })?.data?.message
            ?? 'No se pudo registrar la entrada.';

        error.value = mensaje;
    } finally {
        enviando.value = false;
    }
}

useHead({ title: 'Registrar entrada' });
</script>

<template>
    <div class="entrada">
        <h1>Registrar entrada</h1>

        <form class="formulario" @submit.prevent="enviar">
            <label class="campo">
                <span>Buscar socio</span>
                <input
                    v-model="busqueda"
                    type="search"
                    placeholder="Nombre o apellido"
                    autocomplete="off"
                >
            </label>

            <fieldset class="campo">
                <legend>Socio</legend>
                <ul class="lista">
                    <li v-for="socio in filtrados" :key="socio.id">
                        <label>
                            <input v-model="socioElegido" type="radio" name="socio" :value="socio.id">
                            <span>{{ socio.nombre }}</span>
                            <span v-if="socio.ultima_visita?.abierta" class="adentro">Adentro ahora</span>
                        </label>
                    </li>
                </ul>
                <p v-if="filtrados.length === 0" class="vacio">No hay socios que coincidan.</p>
            </fieldset>

            <label class="campo">
                <span>¿Por dónde entró?</span>
                <select v-model="canal">
                    <option value="dispositivo">Dispositivo (turno)</option>
                    <option value="manual">Manual (a mano)</option>
                    <option value="aplicacion">Aplicación del socio</option>
                </select>
            </label>

            <label v-if="canal === 'dispositivo'" class="campo">
                <span>Qué dispositivo</span>
                <input v-model="dispositivo" type="text" maxlength="80">
            </label>

            <label v-if="necesitaMotivo" class="campo campo--obligatorio">
                <span>¿Por qué se anotó a mano?</span>
                <textarea v-model="motivo" rows="2" maxlength="200" required />
                <small>
                    El acceso manual queda registrado como tal, con su motivo. Sin esto, más adelante
                    no se puede distinguir un corte de luz de algo que no corresponde.
                </small>
            </label>

            <div v-if="necesitaMotivo" class="sugerencias">
                <span>Sugerencias:</span>
                <button
                    v-for="sugerida in motivosSugeridos"
                    :key="sugerida"
                    type="button"
                    @click="motivo = sugerida"
                >
                    {{ sugerida }}
                </button>
            </div>

            <p v-if="error" class="error" role="alert">{{ error }}</p>

            <button type="submit" class="boton" :disabled="enviando">
                {{ enviando ? 'Registrando…' : 'Registrar entrada' }}
            </button>
        </form>
    </div>
</template>

<style scoped>
.entrada { max-width: 42rem; margin: 0 auto; padding: 1.5rem; }
.formulario { display: flex; flex-direction: column; gap: 1rem; }
.campo { display: flex; flex-direction: column; gap: 0.35rem; border: 0; padding: 0; }
.campo span { font-weight: 600; }
.campo small { color: #666; font-size: 0.85rem; }
.campo--obligatorio span::after { content: ' *'; color: #8a1f1f; }
input, select, textarea { padding: 0.5rem; border: 1px solid #ccc; border-radius: 0.375rem; font: inherit; }
.lista { list-style: none; max-height: 16rem; overflow-y: auto; padding: 0; border: 1px solid #eee; border-radius: 0.375rem; }
.lista label { display: flex; align-items: center; gap: 0.5rem; padding: 0.4rem 0.6rem; cursor: pointer; }
.lista label:hover { background: #f6f6f6; }
.adentro { margin-left: auto; font-size: 0.8rem; color: #8a4b12; }
.sugerencias { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; font-size: 0.85rem; color: #666; }
.sugerencias button { padding: 0.2rem 0.6rem; border: 1px solid #ccc; background: #fff; border-radius: 999px; cursor: pointer; font: inherit; font-size: 0.8rem; }
.vacio { color: #666; }
.error { background: #fbeaea; color: #8a1f1f; padding: 0.75rem 1rem; border-radius: 0.5rem; }
.boton { padding: 0.6rem 1.2rem; border: 0; background: #2f6f4f; color: #fff; border-radius: 0.375rem; cursor: pointer; font: inherit; }
.boton[disabled] { opacity: 0.6; cursor: progress; }
</style>