<?php

namespace App\Services\Ia;

use App\Models\IaConsumo;
use App\Models\PedidoConciliacion;
use App\Support\CatalogoSat;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Elige con IA (OpenAI, el mismo modelo económico de las OC) la clave SAT c_ClaveProdServ de cada artículo.
 * La plataforma le da candidatas del catálogo oficial; la IA puede proponer otra, pero solo se acepta si
 * existe en el catálogo del SAT. Nunca la genérica 01010101. Una sola petición por lote (sin background).
 */
class SugeridorClaveSat
{
    private const URL = 'https://api.openai.com/v1/responses';

    public const POR_PETICION = 10;

    private const INSTRUCCIONES = <<<'TXT'
Eres experto en facturación electrónica mexicana (CFDI 4.0) y en ferretería industrial.
Para cada artículo elige la clave de producto o servicio del SAT (catálogo c_ClaveProdServ, 8 dígitos) que
mejor describe QUÉ ES el artículo (no para qué se usa ni de qué material es).
- Prefiere una de las "candidatas" (vienen del catálogo oficial). Si ninguna corresponde, puedes dar otra clave
  del catálogo que conozcas con certeza.
- Elige la clave más específica que corresponda (nivel clase o producto), no una familia genérica si hay una exacta.
- Nunca uses 01010101.
- Si el artículo trae "clave_actual" y es correcta, devuélvela.
- confianza: "alta" si es claramente esa; "media" si hay otra parecida posible; "baja" si la descripción no alcanza.
- motivo: una frase corta en español.
Responde solo con el JSON del esquema.
TXT;

    public function __construct(private readonly CatalogoSat $catalogo = new CatalogoSat) {}

    /**
     * @param  list<array{id: int, descripcion: string, unidad?: ?string, clave_actual?: ?string}>  $articulos
     * @return array<int, array{clave: string, confianza: string, motivo: string, nombre: string}> por id; solo claves válidas
     */
    public function sugerir(array $articulos): array
    {
        if (blank(config('services.openai.key'))) {
            throw new RuntimeException('Falta configurar la llave de OpenAI (OPENAI_API_KEY) en el servidor.');
        }
        $tope = (float) config('services.openai.tope_mensual_usd');
        if (PedidoConciliacion::gastoDelMes() >= $tope) {
            throw new RuntimeException('Se alcanzó el tope mensual de IA (US$'.number_format($tope, 2).'). Pídele a un administrador que lo suba (IA_TOPE_MENSUAL_USD) o espera al próximo mes.');
        }

        $resultado = [];
        foreach (array_chunk($articulos, self::POR_PETICION) as $lote) {
            $resultado += $this->lote($lote);
        }

        return $resultado;
    }

    /** @param  list<array{id: int, descripcion: string, unidad?: ?string, clave_actual?: ?string}>  $lote */
    private function lote(array $lote): array
    {
        $entrada = [];
        foreach ($lote as $a) {
            $candidatas = $this->catalogo->candidatos($a['descripcion'], 30)
                ->map(fn ($s) => $s->clave.' — '.$s->descripcion)->all();
            $actual = trim((string) ($a['clave_actual'] ?? ''));
            if ($actual !== '' && ($nombre = $this->catalogo->nombre($actual))) {
                array_unshift($candidatas, $actual.' — '.$nombre);
            }
            $entrada[] = ['id' => $a['id'], 'descripcion' => $a['descripcion'], 'unidad' => $a['unidad'] ?? null,
                'clave_actual' => $actual ?: null, 'candidatas' => array_values(array_unique($candidatas))];
        }

        $modelo = (string) config('services.openai.modelo');
        $r = $this->enviar([
            'model' => $modelo,
            'reasoning' => ['effort' => 'low'],
            'max_output_tokens' => 4000,
            'store' => false,
            'input' => [
                ['role' => 'developer', 'content' => [['type' => 'input_text', 'text' => self::INSTRUCCIONES]]],
                ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => json_encode(['articulos' => $entrada], JSON_UNESCAPED_UNICODE)]]],
            ],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'claves_sat_v1', 'strict' => true, 'schema' => self::esquema()]],
        ]);

        $entradaTokens = (int) ($r['usage']['input_tokens'] ?? 0);
        $salidaTokens = (int) ($r['usage']['output_tokens'] ?? 0);
        IaConsumo::create(['uso' => 'clave_sat', 'modelo' => $modelo, 'tokens_entrada' => $entradaTokens, 'tokens_salida' => $salidaTokens,
            'costo_usd' => PedidoConciliacion::calcularCosto($modelo, $entradaTokens, $salidaTokens), 'user_id' => auth()->id()]);

        if (($r['status'] ?? null) !== 'completed') {
            throw new RuntimeException('La IA no terminó de responder ('.($r['status'] ?? 'sin estado').'). Vuelve a intentar.');
        }
        $texto = '';
        foreach ($r['output'] ?? [] as $item) {
            foreach (($item['type'] ?? null) === 'message' ? ($item['content'] ?? []) : [] as $c) {
                if (($c['type'] ?? null) === 'output_text') {
                    $texto .= $c['text'] ?? '';
                }
            }
        }
        $datos = json_decode($texto, true);
        if (! is_array($datos) || ! is_array($datos['articulos'] ?? null)) {
            throw new RuntimeException('La IA devolvió una respuesta que no se puede leer. Vuelve a intentar.');
        }

        $ids = array_column($lote, 'id');
        $claves = array_map(fn ($x) => preg_replace('/\D/', '', (string) ($x['clave'] ?? '')), $datos['articulos']);
        $nombres = $this->catalogo->nombres($claves);
        $salida = [];
        foreach ($datos['articulos'] as $i => $x) {
            $id = (int) ($x['id'] ?? 0);
            $clave = $claves[$i];
            if (! in_array($id, $ids, true) || $clave === CatalogoSat::GENERICA || ! isset($nombres[$clave])) {
                continue; // clave inventada o genérica: no se acepta
            }
            $salida[$id] = ['clave' => $clave, 'nombre' => $nombres[$clave],
                'confianza' => in_array($x['confianza'] ?? '', ['alta', 'media', 'baja'], true) ? $x['confianza'] : 'baja',
                'motivo' => mb_substr((string) ($x['motivo'] ?? ''), 0, 200)];
        }

        return $salida;
    }

    private function enviar(array $cuerpo): array
    {
        try {
            return Http::withToken((string) config('services.openai.key'))->acceptJson()
                ->connectTimeout(10)->timeout(120)
                ->retry(2, 2000, when: fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && ($e->response->status() === 429 || $e->response->status() >= 500)))
                ->post(self::URL, $cuerpo)->throw()->json() ?? [];
        } catch (RequestException $e) {
            $s = $e->response->status();
            throw new RuntimeException(match (true) {
                $s === 401 => 'La llave de OpenAI no es válida o fue revocada.',
                $s === 429 => 'OpenAI está saturado o se alcanzó el límite de la cuenta. Intenta en unos minutos.',
                $s >= 500 => 'OpenAI no está disponible por el momento. Intenta en unos minutos.',
                default => 'OpenAI rechazó la solicitud (HTTP '.$s.').',
            }, $s, $e);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No hubo conexión con OpenAI. Intenta de nuevo.', 0, $e);
        }
    }

    public static function esquema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['articulos'],
            'properties' => ['articulos' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false, 'required' => ['id', 'clave', 'confianza', 'motivo'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'clave' => ['type' => 'string'],
                    'confianza' => ['type' => 'string', 'enum' => ['alta', 'media', 'baja']],
                    'motivo' => ['type' => 'string'],
                ],
            ]]],
        ];
    }
}
