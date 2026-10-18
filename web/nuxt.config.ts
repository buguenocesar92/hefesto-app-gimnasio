/**
 * Configuración de Nuxt.
 *
 * LA INTERFAZ ES UN BFF, NO UN CLIENTE SUELTO
 * --------------------------------------------
 * Nuxt corre con SSR y se usa como servidor intermediario: la cookie de
 * sesión viaja desde el navegador al servidor de Nuxt y de ahí a la API. El
 * navegador nunca ve el token.
 *
 * Por eso `apiBase` es una variable de servidor (`runtimeConfig`), no `public`:
 * la dirección interna de la API no se publica en el bundle del cliente.
 */
export default defineNuxtConfig({
    compatibilityDate: '2026-01-01',

    devtools: { enabled: false },

    ssr: true,

    runtimeConfig: {
        // Dirección interna de la API, solo visible en el servidor.
        apiBase: process.env.NUXT_API_BASE ?? 'http://localhost:8080',

        public: {
            nombreAplicacion: 'Gimnasio',
        },
    },

    app: {
        head: {
            htmlAttrs: { lang: 'es-CL' },
            title: 'Gimnasio: asistencia y socios',
            meta: [
                { charset: 'utf-8' },
                { name: 'viewport', content: 'width=device-width, initial-scale=1' },
                {
                    name: 'description',
                    content: 'Panel de asistencia real por socio y aviso de abandono para gimnasios.',
                },
            ],
        },
    },

    // La API es la fuente de los datos; Nuxt no guarda copia de socios en su
    // propio estado global. Así no hay dos verdades que puedan discrepar.
    css: [],

    nitro: {
        routeRules: {
            // El panel y la ficha del socio son datos personales: no se
            // guardan en ninguna caché pública ni compartida.
            '/panel': { swr: false },
            '/socios/**': { swr: false },
        },
    },
});