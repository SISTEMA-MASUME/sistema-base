<?php

namespace App\Support;

use App\Models\Ajuste;
use App\Models\AvisoSuscripcion;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;

/**
 * Avisos al celular (notificaciones push del navegador, sin apps ni servicios de paga). Las llaves VAPID del sistema se
 * crean solas la primera vez y se guardan en `ajustes` (nadie tiene que editar el .env). En iPhone el sistema debe estar
 * agregado a la pantalla de inicio para poder recibirlos.
 */
final class Avisos
{
    private const PUBLICA = 'vapid_publica';

    private const PRIVADA = 'vapid_privada';

    /** Llave pública para que el navegador se suscriba (base64url). */
    public static function llavePublica(): string
    {
        return self::claves()['publica'];
    }

    /** @return array{publica: string, privada: string} */
    private static function claves(): array
    {
        $pub = Ajuste::obtener(self::PUBLICA);
        $priv = Ajuste::obtener(self::PRIVADA);
        if ($pub && $priv) {
            return ['publica' => $pub, 'privada' => $priv];
        }
        // Primera vez: se crean y se guardan sin pisar las de otro proceso que llegue al mismo tiempo.
        $k = VAPID::createVapidKeys();
        Ajuste::query()->insertOrIgnore(['clave' => self::PUBLICA, 'valor' => $k['publicKey'], 'created_at' => now(), 'updated_at' => now()]);
        Ajuste::query()->insertOrIgnore(['clave' => self::PRIVADA, 'valor' => $k['privateKey'], 'created_at' => now(), 'updated_at' => now()]);

        return ['publica' => (string) Ajuste::obtener(self::PUBLICA), 'privada' => (string) Ajuste::obtener(self::PRIVADA)];
    }

    /**
     * Guarda (o renueva) el celular de un usuario.
     *
     * @param  array{endpoint?: mixed, keys?: array{p256dh?: mixed, auth?: mixed}}  $sub  lo que entrega PushSubscription.toJSON()
     */
    public static function suscribir(User $u, array $sub, ?string $agente): AvisoSuscripcion
    {
        $endpoint = is_string($sub['endpoint'] ?? null) ? trim($sub['endpoint']) : '';
        $p256dh = is_string($sub['keys']['p256dh'] ?? null) ? $sub['keys']['p256dh'] : '';
        $auth = is_string($sub['keys']['auth'] ?? null) ? $sub['keys']['auth'] : '';
        if (! str_starts_with($endpoint, 'https://') || strlen($endpoint) > 2000
            || ! preg_match('/^[A-Za-z0-9_-]{40,200}$/', $p256dh) || ! preg_match('/^[A-Za-z0-9_-]{10,100}$/', $auth)) {
            throw new DomainException('El celular no mandó bien sus datos para avisos. Vuelve a intentar.');
        }

        return AvisoSuscripcion::updateOrCreate(['endpoint_hash' => hash('sha256', $endpoint)], [
            'user_id' => $u->id, 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth,
            'agente' => $agente !== null ? mb_substr($agente, 0, 255) : null,
        ]);
    }

    public static function quitar(User $u, string $endpoint): void
    {
        AvisoSuscripcion::where('user_id', $u->id)->where('endpoint_hash', hash('sha256', trim($endpoint)))->delete();
    }

    /**
     * Manda un aviso a todos los celulares de esos usuarios. Los celulares que ya no existen (el navegador los dio de baja)
     * se borran. Devuelve cuántos avisos se entregaron.
     *
     * @param  Collection<int, User>|list<User>  $usuarios
     */
    public static function enviar(iterable $usuarios, string $titulo, string $texto, string $url): int
    {
        $ids = collect($usuarios)->pluck('id')->all();
        $subs = AvisoSuscripcion::whereIn('user_id', $ids)->get();
        if ($subs->isEmpty()) {
            return 0;
        }
        $k = self::claves();
        $cliente = app()->bound(ClientInterface::class.'.avisos') ? app(ClientInterface::class.'.avisos') : null;
        $push = new WebPush(['VAPID' => ['subject' => (string) config('app.url'), 'publicKey' => $k['publica'], 'privateKey' => $k['privada']]],
            ['TTL' => 3600, 'urgency' => 'normal'], $cliente, logger: Log::channel());
        $carga = json_encode(['titulo' => $titulo, 'texto' => $texto, 'url' => $url], JSON_UNESCAPED_UNICODE);

        $entregados = 0;
        foreach ($subs as $s) {
            try {
                $r = $push->sendOneNotification(Subscription::create([
                    'endpoint' => $s->endpoint, 'publicKey' => $s->p256dh, 'authToken' => $s->auth, 'contentEncoding' => 'aes128gcm',
                ]), $carga);
            } catch (\Throwable $e) {
                RegistroErrores::registrar($e);

                continue;
            }
            if ($r->isSuccess()) {
                $s->update(['ultimo_envio_at' => now()]);
                $entregados++;
            } elseif ($r->isSubscriptionExpired()) {
                $s->delete();
            } else {
                Log::warning('Aviso al celular no entregado: '.$r->getReason());
            }
        }

        return $entregados;
    }
}
