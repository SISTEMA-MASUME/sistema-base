<?php

use App\Http\Controllers\McpController;
use App\Http\Controllers\McpOAuthController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// Conexión de asistentes de IA (Claude, ChatGPT) por MCP + OAuth. routes/web.php lo carga fuera del grupo
// "auth": /mcp y /oauth/token usan su propia llave (Bearer / PKCE), sin CSRF.
Route::get('/.well-known/oauth-protected-resource', [McpOAuthController::class, 'recurso']);
Route::get('/.well-known/oauth-protected-resource/mcp', [McpOAuthController::class, 'recurso']);
Route::get('/.well-known/oauth-authorization-server', [McpOAuthController::class, 'servidor']);
Route::get('/.well-known/oauth-authorization-server/mcp', [McpOAuthController::class, 'servidor']);
Route::get('/.well-known/openid-configuration', [McpOAuthController::class, 'servidor']);

Route::withoutMiddleware([ValidateCsrfToken::class])->group(function () {
    Route::post('/oauth/registro', [McpOAuthController::class, 'registro'])->middleware('throttle:20,1')->name('mcp.registro');
    Route::post('/oauth/token', [McpOAuthController::class, 'token'])->middleware('throttle:60,1')->name('mcp.token');
    Route::match(['get', 'post', 'delete'], '/mcp', McpController::class)->middleware('throttle:240,1')->name('mcp');
});

// Pantalla de permiso: requiere la sesión del admin (si no ha entrado, pasa por el login y regresa).
Route::middleware('auth')->group(function () {
    Route::get('/oauth/autorizar', [McpOAuthController::class, 'pantalla'])->name('mcp.autorizar');
    Route::post('/oauth/autorizar', [McpOAuthController::class, 'autorizar']);
});
