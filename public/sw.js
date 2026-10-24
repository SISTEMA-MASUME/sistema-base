// Service worker mínimo para poder instalar el sistema como app en el celular.
// No guarda páginas en caché: los datos (precios, pedidos) siempre se leen del servidor.
// También recibe los avisos del sistema (p. ej. el recordatorio de las 10 pm de los gastos de la casa).
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', (e) => {
    if (e.request.mode !== 'navigate') return;
    e.respondWith(fetch(e.request).catch(() => new Response(
        '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        + '<body style="font-family:sans-serif;background:#12235a;color:#fff;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;text-align:center;padding:24px">'
        + '<div><h1>Sin conexión</h1><p>Revisa tu internet y vuelve a intentar.</p>'
        + '<button onclick="location.reload()" style="font-size:18px;padding:12px 24px;border:0;border-radius:8px;background:#ef7d22;color:#fff">Reintentar</button></div>',
        { headers: { 'Content-Type': 'text/html; charset=utf-8' } },
    )));
});

self.addEventListener('push', (e) => {
    let d = {};
    try { d = e.data ? e.data.json() : {}; } catch (_) { d = { texto: e.data ? e.data.text() : '' }; }
    e.waitUntil(self.registration.showNotification(d.titulo || 'MASUME', {
        body: d.texto || '',
        icon: '/icono-192.png',
        badge: '/icono-192.png',
        data: { url: d.url || '/' },
    }));
});
self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    const url = (e.notification.data && e.notification.data.url) || '/';
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((ventanas) => {
        for (const v of ventanas) {
            if (v.url.startsWith(self.location.origin) && 'focus' in v) { v.navigate(url); return v.focus(); }
        }
        return self.clients.openWindow(url);
    }));
});
