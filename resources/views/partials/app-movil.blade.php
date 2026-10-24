{{-- Instalar el sistema como app en el celular (Android: "Instalar app"; iPhone: "Agregar a inicio"). --}}
@php($gastoRapido = false)
<link rel="manifest" href="{{ $gastoRapido ? '/gasto-rapido.webmanifest' : '/manifest.webmanifest' }}">
<meta name="theme-color" content="{{ $gastoRapido ? '#1f3d36' : '#12235a' }}">
<link rel="apple-touch-icon" href="{{ $gastoRapido ? '/gasto-rapido-180.png' : '/apple-touch-icon.png' }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ $gastoRapido ? 'Gasto rápido' : 'MASUME' }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
    }
</script>
