<?php

namespace App\Http\Controllers;

use App\Models\Mcp\McpToken;
use App\Support\Mcp\Herramientas;
use App\Support\RegistroErrores;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Servidor MCP del sistema (JSON-RPC por HTTP, sin sesión y sin procesos permanentes: cabe en Hostinger).
 * Lo usan Claude y ChatGPT con la llave OAuth que autorizó el admin, o una llave personal.
 */
class McpController extends Controller
{
    public const VERSIONES = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const INSTRUCCIONES = 'Sistema interno de MASUME Industrial (ferretería/herramienta industrial en Guadalajara). '
        .'Datos en español, moneda MXN, IVA 16%. Costos, proveedores y utilidad son INTERNOS: nunca los pongas en textos para clientes. '
        .'Puedes consultar, crear cotizaciones en BORRADOR y EDITAR las que ya existen (editar_cotizacion; si te piden cambios a una cotización, edítala, no hagas otra). Puedes eliminar borradores que sobren (eliminar_cotizacion). Nada se manda al cliente desde aquí. '
        .'Por cotizar es una bandeja distinta: si piden anotar una solicitud para que el equipo la atienda, usa crear_solicitud_por_cotizar, nunca crear_cotizacion_borrador. Consulta con buscar_solicitudes y ver_solicitud. Si falta la herramienta, avisa y no sustituyas el módulo. Conserva clave_solicitud al reintentar y confirma el folio S-N y su enlace. '
        .'El tablero tipo Trello es INTERNO de MASUME, no el servicio Trello. Usa columnas_pedidos, convertir_cotizacion_a_pedido (todas las partidas incluidas, sin OC y con motivo explícito) y mover_pedido. Una columna no marca material entregado ni facturado. El sistema deja la OC pendiente según las reglas del cliente; no inventes un número de OC. Si ya existe el pedido, no lo dupliques. Confirma su folio P-N, columna y enlace. '
        .'precio = costo × (1 + utilidad/100). Para cotizar igual que antes usa precios_anteriores. '
        .'Finanzas (solo lectura): cobranza, nomina, gastos, tablero, mis_finanzas, vacaciones y buscar_ventas. '
        .'Gastos PERSONALES de la casa de Daniel y Paty (súper, gasolina, comida fuera…): registrar_gasto_casa y resumen_casa; nunca los mezcles con los de MASUME. '
        .'Para guardar un gasto (p. ej. con un comprobante del banco) usa registrar_gasto: categoría de la lista, cuenta existente, una clave_gasto por gasto (folio o clave de rastreo) y confirma el gasto guardado. '
        .'Semana de comisión = lunes a sábado; comisión = 10% de la utilidad cobrada.';

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('GET') || $request->isMethod('DELETE')) {
            return response('', 405, ['Allow' => 'POST']);
        }

        $usuario = McpToken::usuarioDe($request->bearerToken());
        if (! $usuario) {
            return response()->json(['error' => 'invalid_token', 'error_description' => 'Falta autorizar la conexión.'], 401, [
                'WWW-Authenticate' => 'Bearer resource_metadata="'.url('/.well-known/oauth-protected-resource').'"',
            ]);
        }
        Auth::setUser($usuario);

        $cuerpo = $request->json()->all();
        if ($cuerpo === []) {
            return $this->respuesta($this->error(null, -32700, 'JSON inválido.'));
        }
        $lote = array_is_list($cuerpo);
        $respuestas = [];
        foreach ($lote ? $cuerpo : [$cuerpo] as $mensaje) {
            $r = $this->atender(is_array($mensaje) ? $mensaje : []);
            if ($r !== null) {
                $respuestas[] = $r;
            }
        }
        if ($respuestas === []) {
            return response('', 202);
        }

        return $this->respuesta($lote ? $respuestas : $respuestas[0]);
    }

    private function respuesta(array $datos): JsonResponse
    {
        return response()->json($datos, 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function error(mixed $id, int $codigo, string $mensaje): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $codigo, 'message' => $mensaje]];
    }

    private function ok(mixed $id, array $resultado): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $resultado === [] ? new \stdClass : $resultado];
    }

    /** @return array|null null para notificaciones (no llevan respuesta) */
    private function atender(array $m): ?array
    {
        $id = $m['id'] ?? null;
        $metodo = (string) ($m['method'] ?? '');
        if (! array_key_exists('id', $m)) {
            return null;   // notifications/initialized, notifications/cancelled…
        }
        $p = (array) ($m['params'] ?? []);

        return match ($metodo) {
            'initialize' => $this->ok($id, [
                'protocolVersion' => in_array($p['protocolVersion'] ?? null, self::VERSIONES, true) ? $p['protocolVersion'] : self::VERSIONES[0],
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'masume', 'title' => 'Sistema MASUME', 'version' => '1.3.0'],
                'instructions' => self::INSTRUCCIONES,
            ]),
            'ping' => $this->ok($id, []),
            'tools/list' => $this->ok($id, ['tools' => Herramientas::definiciones()]),
            'tools/call' => $this->llamar($id, (string) ($p['name'] ?? ''), (array) ($p['arguments'] ?? [])),
            'resources/list' => $this->ok($id, ['resources' => []]),
            'prompts/list' => $this->ok($id, ['prompts' => []]),
            default => $this->error($id, -32601, "Método no soportado: {$metodo}"),
        };
    }

    private function llamar(mixed $id, string $nombre, array $argumentos): array
    {
        if (! collect(Herramientas::definiciones())->contains('name', $nombre)) {
            return $this->error($id, -32602, "Herramienta desconocida: {$nombre}");
        }
        try {
            $datos = Herramientas::ejecutar($nombre, $argumentos);

            return $this->ok($id, [
                'content' => [['type' => 'text', 'text' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]],
                'structuredContent' => $datos,
                'isError' => false,
            ]);
        } catch (ValidationException $e) {
            return $this->ok($id, ['content' => [['type' => 'text', 'text' => implode(' ', $e->validator->errors()->all())]], 'isError' => true]);
        } catch (InvalidArgumentException|DomainException $e) {
            return $this->ok($id, ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
        } catch (\Throwable $e) {
            $codigo = RegistroErrores::registrar($e)?->codigo;

            return $this->ok($id, ['content' => [['type' => 'text', 'text' => 'Error interno del sistema'.($codigo ? " ({$codigo})" : '').'. Intenta de nuevo.']], 'isError' => true]);
        }
    }
}
