<?php

namespace App\Livewire\Mcp;

use App\Models\Mcp\McpToken;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Conexión con IA (solo admin): dirección del MCP para Claude y ChatGPT, asistentes conectados (con opción
 * de quitarles el acceso) y llave personal para Claude Code u otra sesión.
 */
#[Layout('layouts.app')]
#[Title('Conexión con IA')]
class Conexion extends Component
{
    /** Llave personal recién creada: se muestra una sola vez. */
    public string $llaveNueva = '';

    public string $mensaje = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
    }

    public function crearLlave(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        [, $acceso] = McpToken::emitir(auth()->user(), 'Llave personal', null, personal: true);
        $this->llaveNueva = $acceso;
        $this->mensaje = '';
    }

    public function revocar(int $id): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        $t = McpToken::whereNull('revocado_at')->findOrFail($id);
        // Se revocan también las llaves renovadas de la misma conexión.
        McpToken::whereNull('revocado_at')->where('user_id', $t->user_id)
            ->when($t->client_id, fn ($q) => $q->where('client_id', $t->client_id), fn ($q) => $q->whereKey($t->id))
            ->update(['revocado_at' => now()]);
        $this->llaveNueva = '';
        $this->mensaje = "Se quitó el acceso a {$t->nombre}.";
    }

    public function render()
    {
        // Una fila por conexión (la llave más reciente de cada asistente).
        $activas = McpToken::whereNull('revocado_at')->where(fn ($q) => $q->whereNull('expira_at')->orWhere('expira_at', '>', now())->orWhere('refresh_expira_at', '>', now()))
            ->orderByDesc('id')->get()->unique(fn ($t) => $t->client_id ?? 'personal-'.$t->id)->values();

        return view('livewire.mcp.conexion', ['activas' => $activas, 'urlMcp' => url('/mcp')]);
    }
}
