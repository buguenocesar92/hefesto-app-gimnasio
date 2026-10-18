/**
 * Cliente de la API, desde el servidor.
 *
 * POR QUÉ UN COMPOSABLE Y NO UN `$fetch` SUELTO
 * ----------------------------------------------
 * El composable agrega tres cosas que siempre tienen que estar juntas:
 *
 *  1. La cookie de sesión, que viaja en el servidor de Nuxt y de ahí a la API.
 *     El navegador nunca ve el token.
 *  2. La ruta base de la API, que es configuración de servidor.
 *  3. El manejo de 401, que significa "la sesión caducó" y hay que mandar a
 *     la pantalla de entrada, en vez de mostrar un error raro al usuario.
 *
 * Si alguien escribe un `$fetch('/api/v1/socios')` suelto en un componente,
 * se pierde la cookie y la respuesta es 401 siempre. Por eso este es el
 * único camino a la API.
 */
export function useApi() {
    const config = useRuntimeConfig();
    const router = useRouter();

    async function pedir<T>(ruta: string, opciones: Parameters<typeof $fetch<T>>[1] = {}): Promise<T> {
        try {
            return await $fetch<T>(`${config.apiBase}/api/v1${ruta}`, {
                // La sesión viaja en la cookie, de origen en origen.
                credentials: 'include',
                ...opciones,
            });
        } catch (error: unknown) {
            const estado = (error as { statusCode?: number })?.statusCode;

            if (estado === 401) {
                await navigateTo('/entrar', { redirectCode: 302 });
                throw error;
            }

            if (estado === 404) {
                // Un 404 acá significa que el recurso es de otro gimnasio o
                // que no existe. El mensaje no dice cuál de las dos cosas,
                // y esa es la decisión correcta: un 403 confirmaría que el
                // recurso existe.
                throw createError({
                    statusCode: 404,
                    statusMessage: 'No encontrado.',
                });
            }

            throw error;
        }
    }

    return {
        pedir,
        panel: () => pedir<Panel>('/panel'),
        socios: (consulta: Record<string, string> = {}) =>
            pedir<Pagina<Socio>>('/socios', { query: consulta }),
        socio: (id: string) => pedir<{ socio: Socio; estado: EstadoSocio }>(`/socios/${id}`),
        visitas: (consulta: Record<string, string> = {}) =>
            pedir<Pagina<Visita>>('/visitas', { query: consulta }),
        registrarEntrada: (cuerpo: Entrada) => pedir<{ visita: Visita }>('/visitas/entrada', {
            method: 'POST',
            body: cuerpo,
        }),
        registrarSalida: (id: string, cuerpo: Salida) =>
            pedir<{ visita: Visita }>(`/visitas/${id}/salida`, {
                method: 'POST',
                body: cuerpo,
            }),
        sesion: () => pedir<{ usuario: UsuarioSesion }>('/sesion'),
    };
}

// --- Tipos compartidos con la API ------------------------------------------

export interface UsuarioSesion {
    id: string;
    nombre: string;
    correo: string;
    rol: 'administrador' | 'encargado' | 'coach' | 'socio';
    gimnasio_id: string;
}

export interface Socio {
    id: string;
    nombre: string;
    nombre_solo: string;
    apellido: string | null;
    documento: string | null;
    nacimiento: string | null;
    correo: string | null;
    telefono: string | null;
    activo: boolean;
    suspendida_desde: string | null;
    suspension_hasta: string | null;
    membresia: {
        id: string;
        plan: string | null;
        estado: string;
        iniciada_en: string | null;
    } | null;
    ultima_visita: {
        entrada_en: string | null;
        salida_en: string | null;
        abierta: boolean;
    } | null;
}

export interface EstadoSocio {
    socio_id: string;
    ultima_visita_en: string | null;
    dias_sin_venir: number | null;
    visitas_totales: number;
    membresia_id: string | null;
    plan: string | null;
    estado_membresia: string | null;
    ultimo_pago: string | null;
    cubre_hoy: boolean;
    motivo_bloqueo: string;
}

export interface Visita {
    id: string;
    entrada_en: string | null;
    salida_en: string | null;
    abierta: boolean;
    duracion_minutos: number;
    canal: 'dispositivo' | 'manual' | 'aplicacion';
    motivo_manual: string | null;
    cerrada_por_sistema: boolean;
    anulada: boolean;
    cruza_medianoche: boolean;
    socio: { id: string; nombre: string };
}

export interface Panel {
    hoy: string;
    contadores: Record<string, number>;
    serie_30_dias: Record<string, number>;
    en_riesgo: Array<{
        aviso_id: string;
        socio_id: string;
        nombre: string;
        dias_sin_venir: number;
        ultima_visita_en: string | null;
        ultimo_pago: string | null;
        pago_al_dia: boolean;
        observacion: string | null;
    }>;
    nunca_vinieron: Array<{ id: string; nombre: string; apellido: string | null; iniciada_en: string | null }>;
}

export interface Pagina<T> {
    data: T[];
    meta: { current_page: number; last_page: number; total: number };
}

export interface Entrada {
    socio_id: string;
    canal: 'dispositivo' | 'manual' | 'aplicacion';
    dispositivo?: string | null;
    motivo_manual?: string | null;
}

export interface Salida {
    canal: 'dispositivo' | 'manual' | 'aplicacion';
    motivo?: string | null;
}