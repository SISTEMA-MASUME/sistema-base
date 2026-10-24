import './bootstrap';
import './sugerencias';
import './partidas';
import './tablero';
import './compartir';
import './revision-cotizaciones';

// Edge y Chrome "autollenan" varios campos a la vez con lo que escribiste antes (en el cotizador
// llenaba Modelo y Costo con IVA con el mismo número). En el sistema no se usa el autollenado.
function sinAutollenado(raiz = document) {
    raiz.querySelectorAll('input.campo:not([autocomplete]), textarea.campo:not([autocomplete])').forEach((el) => {
        el.setAttribute('autocomplete', 'off');
    });
}
document.addEventListener('DOMContentLoaded', () => sinAutollenado());
document.addEventListener('livewire:init', () => {
    window.Livewire.hook('commit', ({ succeed }) => succeed(() => queueMicrotask(() => sinAutollenado())));

    // El CDN de Hostinger a veces contesta "vuelve a intentar" (307 a la MISMA dirección /livewire/update); el navegador
    // reintenta solo y la acción sí se guarda, pero Livewire ve la respuesta como "redirigida" y mandaba la ventana a
    // /livewire/update → pantalla "405 Método no permitido" (Rosa y Alberto, 22-oct-2026). Esa respuesta se usa normal.
    // Las redirecciones de verdad (p. ej. al login cuando expira la sesión) terminan en otra dirección y no se tocan.
    window.Livewire.hook('request', ({ respond }) => respond((r) => {
        const res = r.response;
        if (res.redirected && new URL(res.url).pathname.startsWith('/livewire/')) {
            r.response = new Response(res.body, { status: res.status, statusText: res.statusText, headers: res.headers });
        }
    }));
});

// Celular / app instalada: los PDF y archivos del sistema se abren en el visor propio (/visor-pdf), que tiene la
// flecha "← Regresar". Abrirlos en otra pestaña hacía que al cerrarla con la X se saliera del programa.
const enCelular = () => window.matchMedia('(display-mode: standalone)').matches || window.matchMedia('(pointer: coarse)').matches;
const RUTAS_ARCHIVO = /(\/pdf$|\/oc-cliente$|\/archivos?\/|\/imagen$)/;

window.abrirPdf = (href, titulo = '') => {
    const u = new URL(href, location.href);
    if (u.origin !== location.origin || !enCelular()) {
        window.open(u.href, '_blank');
        return;
    }
    const destino = new URL('/visor-pdf', location.origin);
    destino.searchParams.set('u', u.pathname + u.search);
    destino.searchParams.set('volver', location.pathname + location.search);
    if (titulo) destino.searchParams.set('t', titulo);
    location.href = destino.href;
};

document.addEventListener('click', (e) => {
    const a = e.target.closest && e.target.closest('a[target="_blank"]');
    if (!a || a.hasAttribute('download') || e.defaultPrevented || e.ctrlKey || e.metaKey || !enCelular()) return;
    const u = new URL(a.href, location.href);
    if (u.origin !== location.origin || !RUTAS_ARCHIVO.test(u.pathname)) return;
    e.preventDefault();
    window.abrirPdf(u.href, (a.dataset.titulo || a.textContent || '').trim().slice(0, 80));
});
