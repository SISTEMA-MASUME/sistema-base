<?php

use Illuminate\Support\Facades\Route;

// Rutas públicas (login) las define la tarea de autenticación en routes/modulos/auth.php
if (file_exists(__DIR__.'/modulos/auth.php')) {
    require __DIR__.'/modulos/auth.php';
}

// Enlaces firmados para compartir PDFs (WhatsApp) sin sesión.
require __DIR__.'/modulos/publico.php';

// API del programa de la PC de Daniel (catálogo de SICAR): sin sesión ni CSRF, con token Bearer.
require __DIR__.'/modulos/sicar-api.php';

// MCP para asistentes de IA (Claude, ChatGPT): OAuth y /mcp con su propia llave.
require __DIR__.'/modulos/mcp-api.php';

// Módulos que el personal de campo (rol campo) no puede abrir: ahí están precios de venta y utilidad.
$sinCampo = ['cotizaciones.php', 'clientes.php', 'proveedores.php', 'sugerencias.php', 'facturacion.php', 'sicar.php', 'catalogo.php', 'solicitudes.php', 'cambios-precio.php', 'articulos.php'];

// Todo lo demás requiere sesión. Cada módulo vive en routes/modulos/<modulo>.php
Route::middleware('auth')->group(function () use ($sinCampo) {
    foreach (glob(__DIR__.'/modulos/*.php') as $archivo) {
        $nombre = basename($archivo);
        if (in_array($nombre, ['auth.php', 'publico.php', 'sicar-api.php', 'mcp-api.php'], true)) {
            continue;
        }
        // Módulo de Ned Tracking: solo su usuario externo; el resto de MASUME le queda cerrado.
        if ($nombre === 'visor.php') {
            // El visor de PDF lo usan también los usuarios de Ned Tracking.
            Route::group([], $archivo);
        } elseif ($nombre === 'ned.php') {
            Route::middleware('solo_ned')->group($archivo);
        } elseif (in_array($nombre, $sinCampo, true)) {
            Route::middleware(['solo_masume', 'sin_campo'])->group($archivo);
        } else {
            Route::middleware('solo_masume')->group($archivo);
        }
    }
});
