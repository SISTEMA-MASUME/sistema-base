<?php

namespace App\Http\Controllers;

use App\Models\Mcp\McpToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth 2.1 mínimo para que Claude y ChatGPT se conecten al MCP (/mcp): registro dinámico del cliente,
 * autorización con PKCE (S256) que solo el admin aprueba, y llaves con renovación.
 */
class McpOAuthController extends Controller
{
    private function json(array $datos, int $status = 200): JsonResponse
    {
        return response()->json($datos, $status, ['Access-Control-Allow-Origin' => '*', 'Cache-Control' => 'no-store']);
    }

    /** /.well-known/oauth-protected-resource */
    public function recurso(): JsonResponse
    {
        return $this->json([
            'resource' => url('/mcp'),
            'authorization_servers' => [url('/')],
            'scopes_supported' => ['masume'],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Sistema MASUME',
        ]);
    }

    /** /.well-known/oauth-authorization-server (y openid-configuration) */
    public function servidor(): JsonResponse
    {
        return $this->json([
            'issuer' => url('/'),
            'authorization_endpoint' => url('/oauth/autorizar'),
            'token_endpoint' => url('/oauth/token'),
            'registration_endpoint' => url('/oauth/registro'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'scopes_supported' => ['masume'],
        ]);
    }

    /** Registro dinámico (RFC 7591): Claude/ChatGPT se dan de alta solos; no da acceso por sí mismo. */
    public function registro(Request $request): JsonResponse
    {
        $uris = array_values(array_filter((array) $request->input('redirect_uris', []), fn ($u) => is_string($u)
            && (str_starts_with($u, 'https://') || preg_match('#^http://(localhost|127\.0\.0\.1)(:\d+)?/#', $u))));
        if ($uris === []) {
            return $this->json(['error' => 'invalid_redirect_uri', 'error_description' => 'Falta redirect_uris (https).'], 400);
        }
        $clientId = 'mcp_'.Str::random(32);
        DB::table('mcp_clientes')->insert([
            'client_id' => $clientId,
            'nombre' => mb_substr((string) $request->input('client_name', 'Asistente'), 0, 120),
            'redirect_uris' => json_encode($uris),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->json([
            'client_id' => $clientId,
            'client_id_issued_at' => now()->timestamp,
            'client_name' => $request->input('client_name', 'Asistente'),
            'redirect_uris' => $uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }

    /** Dominios de los asistentes conocidos (Claude y ChatGPT) y de pruebas en la propia computadora. */
    private const DESTINOS_CONOCIDOS = ['claude.ai', 'claude.com', 'anthropic.com', 'chatgpt.com', 'openai.com', 'localhost', '127.0.0.1'];

    /**
     * A qué sitio se mandará la llave y si es uno conocido. Cualquiera puede registrarse con el nombre que quiera
     * (p. ej. "Claude"), así que la pantalla de permiso enseña el dominio real (auditoría oct-2026).
     *
     * @return array{host: string, conocido: bool}
     */
    public static function destino(string $redirect): array
    {
        $host = strtolower((string) parse_url($redirect, PHP_URL_HOST));
        $conocido = $host !== '' && collect(self::DESTINOS_CONOCIDOS)->contains(fn ($d) => $host === $d || str_ends_with($host, '.'.$d));

        return ['host' => $host, 'conocido' => $conocido];
    }

    private function cliente(?string $clientId): ?object
    {
        return $clientId ? DB::table('mcp_clientes')->where('client_id', $clientId)->first() : null;
    }

    /** Pantalla "¿Permitir que Claude/ChatGPT entre al sistema?" (requiere sesión de admin). */
    public function pantalla(Request $request)
    {
        $cliente = $this->cliente($request->query('client_id'));
        $redirect = (string) $request->query('redirect_uri');
        abort_unless($cliente && in_array($redirect, json_decode($cliente->redirect_uris, true), true), 400, 'Conexión no reconocida. Vuelve a agregar el conector.');
        abort_unless($request->query('response_type') === 'code' && $request->query('code_challenge')
            && $request->query('code_challenge_method', 'S256') === 'S256', 400, 'Solicitud incompleta (falta PKCE).');
        abort_unless($request->user()->esAdmin(), 403, 'Solo el administrador puede conectar asistentes de IA.');

        return view('mcp.autorizar', [
            'cliente' => $cliente,
            'destino' => self::destino($redirect),
            'parametros' => array_filter($request->only(['client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'scope', 'resource']), 'is_string'),
        ]);
    }

    public function autorizar(Request $request): Response
    {
        abort_unless($request->user()->esAdmin(), 403);
        $cliente = $this->cliente($request->input('client_id'));
        $redirect = (string) $request->input('redirect_uri');
        abort_unless($cliente && in_array($redirect, json_decode($cliente->redirect_uris, true), true), 400);
        $state = $request->input('state');
        $separador = str_contains($redirect, '?') ? '&' : '?';

        if (! $request->boolean('permitir')) {
            return redirect()->away($redirect.$separador.http_build_query(array_filter(['error' => 'access_denied', 'state' => $state])));
        }
        // Destino desconocido: solo con la confirmación expresa de la pantalla.
        abort_unless(self::destino($redirect)['conocido'] || $request->boolean('confirmo_destino'), 422,
            'Ese asistente manda la llave a un sitio que no es Claude ni ChatGPT. Si de verdad es tuyo, marca la confirmación.');
        $codigo = Str::random(48);
        DB::table('mcp_codigos')->insert([
            'codigo_hash' => McpToken::hash($codigo),
            'client_id' => $cliente->client_id,
            'user_id' => $request->user()->id,
            'redirect_uri' => $redirect,
            'code_challenge' => (string) $request->input('code_challenge'),
            'expira_at' => now()->addMinutes(10),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->away($redirect.$separador.http_build_query(array_filter(['code' => $codigo, 'state' => $state])));
    }

    public function token(Request $request): JsonResponse
    {
        return match ($request->input('grant_type')) {
            'authorization_code' => $this->canjearCodigo($request),
            'refresh_token' => $this->renovar($request),
            default => $this->json(['error' => 'unsupported_grant_type'], 400),
        };
    }

    private function canjearCodigo(Request $request): JsonResponse
    {
        $fila = DB::table('mcp_codigos')->where('codigo_hash', McpToken::hash((string) $request->input('code')))->first();
        $verificador = (string) $request->input('code_verifier');
        $reto = rtrim(strtr(base64_encode(hash('sha256', $verificador, true)), '+/', '-_'), '=');
        $clientId = $request->input('client_id') ?? $request->getUser();
        if (! $fila || now()->greaterThan($fila->expira_at) || $verificador === '' || ! hash_equals($fila->code_challenge, $reto)
            || ($clientId && $clientId !== $fila->client_id)
            || ($request->filled('redirect_uri') && $request->input('redirect_uri') !== $fila->redirect_uri)) {
            return $this->json(['error' => 'invalid_grant'], 400);
        }
        // Un código sirve una sola vez: se gasta hasta que todo cuadra, y solo gana quien de verdad lo borró
        // (dos canjes al mismo tiempo no sacan dos llaves; un intento con datos malos no lo inutiliza).
        if (DB::table('mcp_codigos')->where('id', $fila->id)->delete() !== 1) {
            return $this->json(['error' => 'invalid_grant'], 400);
        }
        $usuario = User::find($fila->user_id);
        if (! $usuario?->esAdmin() || ! $usuario->activo) {
            return $this->json(['error' => 'invalid_grant'], 400);
        }
        $nombre = $this->cliente($fila->client_id)?->nombre ?: 'Asistente';

        return $this->respuestaToken(...McpToken::emitir($usuario, $nombre, $fila->client_id));
    }

    private function renovar(Request $request): JsonResponse
    {
        $t = McpToken::where('refresh_hash', McpToken::hash((string) $request->input('refresh_token')))->whereNull('revocado_at')->first();
        $clientId = $request->input('client_id') ?? $request->getUser();
        if (! $t || ($t->refresh_expira_at && $t->refresh_expira_at->isPast()) || ! $t->user?->esAdmin() || ! $t->user->activo
            || ($clientId && $t->client_id && $clientId !== $t->client_id)) {
            return $this->json(['error' => 'invalid_grant'], 400);
        }
        // Se rota: la llave anterior deja de servir. Solo una renovación gana si llegan dos al mismo tiempo.
        if (McpToken::whereKey($t->id)->whereNull('revocado_at')->update(['revocado_at' => now()]) !== 1) {
            return $this->json(['error' => 'invalid_grant'], 400);
        }

        return $this->respuestaToken(...McpToken::emitir($t->user, $t->nombre, $t->client_id));
    }

    private function respuestaToken(McpToken $t, string $acceso, ?string $refresh): JsonResponse
    {
        return $this->json(array_filter([
            'access_token' => $acceso,
            'token_type' => 'Bearer',
            'expires_in' => $t->expira_at ? (int) now()->diffInSeconds($t->expira_at) : null,
            'refresh_token' => $refresh,
            'scope' => 'masume',
        ]));
    }
}
