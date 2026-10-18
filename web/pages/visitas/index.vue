<script setup lang="ts">
/**
 * VISITAS
 *
 * La lista de entradas y salidas del gimnasio. Se ve de un vistazo quién está
 * adentro y quién lleva una visita abierta demasiado tiempo.
 *
 * "Demasiado tiempo" no es un adorno: una visita de 14 horas es casi siempre
 * un turno que se cayó, y el sistema la cierra solo después de las horas que
 * el gimnasio configuró, dejando el rastro. Ver `gimnasio:cerrar-visitas-abiertas`.
 */
const { visitas } = useApi();

const soloAbiertas = ref(false);
const pagina = ref(1);

const { data, status } = await useAsyncData(
    'visitas',
    () => visitas({ por_pagina: '50', page: String(pagina.value), abiertas: soloAbiertas.value ? '1' : '' }),
    { watch: [soloAbiertas, pagina] },
);

function hora(iso: string | null): string {
    if (iso === null) {
        return '—';
    }

    return new Date(iso).toLocaleTimeString('es-CL', { hour: '2-digit', minute: '2-digit' });
}

function fechaCorta(iso: string | null): string {
    if (iso === null) {
        return '—';
    }

    return new Date(iso).toLocaleDateString('es-CL', { day: '2-digit', month: '2-digit' });
}

function duracion(visita: { entrada_en: string | null; salida_en: string | null }): string {
    if (visita.entrada_en === null) {
        return '—';
    }

    const fin = visita.salida_en === null ? new Date() : new Date(visita.salida_en);
    const minutos = Math.round((fin.getTime() - new Date(visita.entrada_en).getTime()) / 60000);

    if (minutos < 60) {
        return `${minutos} min`;
    }

    return `${Math.floor(minutos / 60)} h ${minutos % 60} min`;
}

useHead({ title: 'Visitas' });
</script>

<template>
    <div class="visitas">
        <header class="visitas__encabezado">
            <h1>Visitas</h1>
            <NuxtLink to="/visitas/nueva" class="boton">Registrar entrada</NuxtLink>
        </header>

        <label class="filtro">
            <input v-model="soloAbiertas" type="checkbox">
            Mostrar solo las que están abiertas
        </label>

        <p v-if="status === 'pending'">Cargando…</p>

        <table v-else class="tabla">
            <thead>
                <tr>
                    <th scope="col">Socio</th>
                    <th scope="col">Día</th>
                    <th scope="col">Entrada</th>
                    <th scope="col">Salida</th>
                    <th scope="col">Duración</th>
                    <th scope="col">Canal</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="visita in data?.data" :key="visita.id">
                    <th scope="row">
                        <NuxtLink :to="`/socios/${visita.socio.id}`">{{ visita.socio.nombre }}</NuxtLink>
                    </th>
                    <td>{{ fechaCorta(visita.entrada_en) }}</td>
                    <td>{{ hora(visita.entrada_en) }}</td>
                    <td>
                        {{ hora(visita.salida_en) }}
                        <span v-if="visita.cerrada_por_sistema" class="marca" title="Nadie registró la salida: la cerró el sistema.">
                            por el sistema
                        </span>
                    </td>
                    <td>{{ duracion(visita) }}</td>
                    <td>
                        <span class="canal" :class="`canal--${visita.canal}`">{{ visita.canal }}</span>
                        <!--
                            El canal manual muestra su motivo. Un corte de luz
                            anotado a mano tiene que poder leerse después.
                        -->
                        <span v-if="visita.motivo_manual" class="motivo" :title="visita.motivo_manual">
                            {{ visita.motivo_manual }}
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>

        <nav v-if="(data?.meta?.last_page ?? 1) > 1" class="paginacion" aria-label="Paginación">
            <button type="button" :disabled="pagina <= 1" @click="pagina -= 1">Anterior</button>
            <span>Página {{ pagina }} de {{ data?.meta.last_page }}</span>
            <button
                type="button"
                :disabled="pagina >= (data?.meta.last_page ?? 1)"
                @click="pagina += 1"
            >
                Siguiente
            </button>
        </nav>
    </div>
</template>

<style scoped>
.visitas { max-width: 72rem; margin: 0 auto; padding: 1.5rem; }
.visitas__encabezado { display: flex; align-items: center; justify-content: space-between; }
.filtro { display: flex; gap: 0.5rem; align-items: center; margin: 1rem 0; }
.tabla { width: 100%; border-collapse: collapse; }
.tabla th, .tabla td { padding: 0.5rem 0.75rem; border-bottom: 1px solid #eee; text-align: left; }
.tabla thead th { font-size: 0.8rem; color: #666; text-transform: uppercase; }
.canal { padding: 0.1rem 0.45rem; border-radius: 999px; font-size: 0.75rem; background: #eef2ee; }
.canal--manual { background: #fdf1e3; color: #8a4b12; }
.marca, .motivo { display: inline-block; margin-left: 0.35rem; font-size: 0.75rem; color: #8a4b12; }
.paginacion { display: flex; gap: 1rem; align-items: center; margin-top: 1rem; }
.boton { padding: 0.5rem 1rem; border: 0; background: #2f6f4f; color: #fff; border-radius: 0.375rem; cursor: pointer; font: inherit; text-decoration: none; }
</style>