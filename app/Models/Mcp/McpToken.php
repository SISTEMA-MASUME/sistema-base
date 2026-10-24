<?php

namespace App\Models\Mcp;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Llave de acceso de un asistente de IA (Claude, ChatGPT) al MCP del sistema. Solo se guarda su hash. */
class McpToken extends Model
{
    protected $table = 'mcp_tokens';

    protected $fillable = ['nombre', 'client_id', 'user_id', 'token_hash', 'refresh_hash', 'expira_at', 'refresh_expira_at', 'ultimo_uso_at', 'revocado_at'];

    protected $casts = ['expira_at' => 'datetime', 'refresh_expira_at' => 'datetime', 'ultimo_uso_at' => 'datetime', 'revocado_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $valor): string
    {
        return hash('sha256', $valor);
    }

    /**
     * Crea una llave. Devuelve [modelo, access_token en claro, refresh_token en claro|null].
     *
     * @return array{0: self, 1: string, 2: ?string}
     */
    public static function emitir(User $user, string $nombre, ?string $clientId, bool $personal = false): array
    {
        $acceso = 'msm_'.Str::random(48);
        $refresh = $personal ? null : 'msr_'.Str::random(48);
        $t = self::create([
            'nombre' => $nombre,
            'client_id' => $clientId,
            'user_id' => $user->id,
            'token_hash' => self::hash($acceso),
            'refresh_hash' => $refresh ? self::hash($refresh) : null,
            'expira_at' => $personal ? null : now()->addDays(7),
            'refresh_expira_at' => $refresh ? now()->addDays(180) : null,
        ]);

        return [$t, $acceso, $refresh];
    }

    /** Usuario de una llave válida (no revocada, no vencida, admin activo). */
    public static function usuarioDe(?string $acceso): ?User
    {
        if (! $acceso) {
            return null;
        }
        $t = self::where('token_hash', self::hash($acceso))->whereNull('revocado_at')->first();
        if (! $t || ($t->expira_at && $t->expira_at->isPast())) {
            return null;
        }
        $u = $t->user;
        if (! $u || ! $u->activo || ! $u->esAdmin()) {
            return null;
        }
        if (! $t->ultimo_uso_at || $t->ultimo_uso_at->lt(now()->subMinutes(5))) {
            $t->forceFill(['ultimo_uso_at' => now()])->saveQuietly();
        }

        return $u;
    }
}
