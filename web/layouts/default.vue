<script setup lang="ts">
/**
 * Layout por defecto: la barra de arriba con las pantallas del día.
 *
 * La barra refleja lo que la persona puede hacer según su rol, pero eso es
 * solo de presentación: la autorización real está en la API, y ocultar un
 * enlace no es una medida de seguridad. Un enlace que no se ve que exista en
 * el menú no impide nada; lo que lo impide es el 404 que devuelve la API.
 */
const { sesion } = useApi();

const { data: sesionActual } = await useAsyncData('sesion', () => sesion().catch(() => null));

const esPersonal = computed(() => sesionActual.value?.usuario?.rol !== 'socio');
const registraAcceso = computed(() => sesionActual.value?.permisos?.registrar_acceso ?? false);
</script>

<template>
    <div class="caja">
        <header class="barra">
            <NuxtLink to="/" class="marca">Gimnasio</NuxtLink>

            <nav v-if="esPersonal" aria-label="Principal">
                <NuxtLink to="/">Panel</NuxtLink>
                <NuxtLink to="/socios">Socios</NuxtLink>
                <NuxtLink to="/visitas">Visitas</NuxtLink>
                <NuxtLink v-if="registraAcceso" to="/visitas/nueva">Registrar entrada</NuxtLink>
            </nav>

            <span v-if="sesionActual?.usuario" class="usuario">
                {{ sesionActual.usuario.nombre }} ({{ sesionActual.usuario.rol }})
            </span>
        </header>

        <main>
            <slot />
        </main>

        <footer class="pie">
            <p>
                Software de Hefesto Studio. Los datos personales se tratan bajo la Ley 19.628.
            </p>
        </footer>
    </div>
</template>

<style scoped>
.caja { min-height: 100vh; display: flex; flex-direction: column; }
.barra { display: flex; align-items: center; gap: 1.5rem; padding: 0.75rem 1.5rem; border-bottom: 1px solid #e2e2e2; background: #fff; }
.marca { font-weight: 700; text-decoration: none; color: #2f6f4f; }
nav { display: flex; gap: 1rem; }
.usuario { margin-left: auto; color: #666; font-size: 0.9rem; }
main { flex: 1; }
.pie { padding: 1rem 1.5rem; border-top: 1px solid #eee; color: #888; font-size: 0.8rem; }
</style>