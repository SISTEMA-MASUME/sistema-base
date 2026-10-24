<?php

namespace App\Support\Mcp;

use App\Models\Cliente;
use App\Models\SolicitudCotizacion;
use App\Support\Limites;
use App\Support\Nombres;
use App\Support\Solicitudes;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/** El MCP anota en la misma bandeja que la pantalla; los vendedores conservan la toma y el envío. */
final class HerramientasSolicitudes
{
    private const MENSAJES = [
        'required' => 'Falta el campo :attribute.', 'string' => 'El campo :attribute debe ser texto.',
        'integer' => 'El campo :attribute debe ser un número entero.', 'numeric' => 'El campo :attribute debe ser un número.',
        'array' => 'El campo :attribute debe ser una lista con los campos permitidos.',
        'max' => 'El campo :attribute supera el máximo permitido (:max).', 'min' => 'El campo :attribute no alcanza el mínimo permitido (:min).',
        'gt' => 'El campo :attribute debe ser mayor que :value.', 'in' => 'El valor de :attribute no está permitido.',
        'date' => 'La fecha :attribute no es válida.', 'date_format' => 'La fecha :attribute debe tener el formato :format.',
    ];

    public static function definiciones(): array
    {
        $texto = fn (string $d, int $max = 250) => ['type' => 'string', 'description' => $d, 'maxLength' => $max];
        $folio = $texto('Folio de solicitud, por ejemplo S-12 (también acepta 12).', 20);
        $lectura = ['readOnlyHint' => true, 'openWorldHint' => false];

        return [
            ['name' => 'crear_solicitud_por_cotizar', 'title' => 'Anotar en Por cotizar',
                'description' => 'Anota lo que un cliente o prospecto pidió en la bandeja POR COTIZAR, en estado PENDIENTE para que el equipo lo atienda. No crea cotizaciones, no la toma y no manda nada. Usa esta herramienta cuando pidan dejar algo por cotizar. Guarda y reutiliza la misma clave_solicitud en todos los reintentos de esa solicitud; una solicitud distinta necesita otra clave. El cliente puede estar dado de alta o quedar como texto de prospecto. Devuelve folio S-N y enlace.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'clave_solicitud' => $texto('Identificador único de esta solicitud (por ejemplo un UUID). Conserva el mismo valor al reintentar.', 120),
                    'cliente_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'ID de cliente existente, obtenido con buscar_clientes.'],
                    'cliente' => $texto('Nombre del cliente o prospecto si no usas cliente_id. No se da de alta automáticamente.'),
                    'contacto' => $texto('Nombre y datos de contacto (teléfono/correo), tal como llegaron.'),
                    'canal' => ['type' => 'string', 'enum' => array_keys(SolicitudCotizacion::CANALES), 'description' => 'Canal de llegada; por defecto whatsapp.'],
                    'recibida_at' => $texto('Fecha y hora de llegada, AAAA-MM-DD HH:MM:SS o ISO 8601. Sin zona se interpreta en México; si se omite usa ahora.', 40),
                    'detalle' => $texto('Texto original, observaciones y procedencia (por ejemplo anuncio de la página). Interno para el equipo.', 5000),
                    'partidas' => ['type' => 'array', 'maxItems' => 200, 'items' => [
                        'type' => 'object', 'additionalProperties' => false, 'properties' => [
                            'descripcion' => $texto('Artículo solicitado; incluye la marca aquí cuando corresponda.', 2000),
                            'cantidad' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => Limites::CANTIDAD],
                            'unidad' => $texto('PZA, JGO, MT, KIT… (por defecto PZA).', 10),
                            'modelo' => $texto('Modelo o SKU solicitado.'),
                        ], 'required' => ['descripcion', 'cantidad'],
                    ]],
                ], 'required' => ['clave_solicitud']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],
            ['name' => 'buscar_solicitudes', 'title' => 'Buscar en Por cotizar',
                'description' => 'Lista solicitudes de Por cotizar por cliente/prospecto, contacto, detalle o artículo, estado y fechas de llegada; muestra folio S-N, responsable y enlace. No crea ni toma solicitudes.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'texto' => $texto('Cliente, contacto, detalle, descripción o modelo solicitado.'),
                    'estatus' => ['type' => 'string', 'enum' => array_keys(SolicitudCotizacion::ESTATUS)],
                    'desde' => $texto('Fecha de llegada inicial AAAA-MM-DD.', 10),
                    'hasta' => $texto('Fecha de llegada final AAAA-MM-DD.', 10),
                    'limite' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                ]], 'annotations' => $lectura],
            ['name' => 'ver_solicitud', 'title' => 'Ver solicitud por cotizar',
                'description' => 'Detalle de una solicitud por folio S-N: cliente, contacto, partidas, observaciones, responsable, cotización ligada, archivos y bitácora. No cambia su estado.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => ['folio' => $folio], 'required' => ['folio']],
                'annotations' => $lectura],
        ];
    }

    public static function maneja(string $nombre): bool
    {
        return in_array($nombre, ['crear_solicitud_por_cotizar', 'buscar_solicitudes', 'ver_solicitud'], true);
    }

    public static function ejecutar(string $nombre, array $a): array
    {
        return match ($nombre) {
            'crear_solicitud_por_cotizar' => self::crear($a),
            'buscar_solicitudes' => self::buscar($a),
            'ver_solicitud' => self::ver($a),
            default => throw new InvalidArgumentException('Herramienta de solicitudes desconocida.'),
        };
    }

    private static function crear(array $a): array
    {
        $a = Validator::make($a, [
            'clave_solicitud' => ['required', 'string', 'max:120'],
            'cliente_id' => ['nullable', 'integer', 'min:1'],
            'cliente' => ['nullable', 'string', 'max:250'],
            'contacto' => ['nullable', 'string', 'max:250'],
            'canal' => ['sometimes', 'required', 'in:'.implode(',', array_keys(SolicitudCotizacion::CANALES))],
            'recibida_at' => ['sometimes', 'required', 'string', 'max:40', 'date'],
            'detalle' => ['nullable', 'string', 'max:5000'],
            'partidas' => ['nullable', 'array', 'max:200'],
            'partidas.*' => ['array:descripcion,cantidad,unidad,modelo'],
            'partidas.*.descripcion' => ['required', 'string', 'max:2000'],
            'partidas.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:'.Limites::CANTIDAD],
            'partidas.*.unidad' => ['nullable', 'string', 'max:10'],
            'partidas.*.modelo' => ['nullable', 'string', 'max:250'],
        ], self::MENSAJES)->validate();
        if (trim($a['clave_solicitud']) === '') {
            throw new InvalidArgumentException('Indica una clave única para esta solicitud.');
        }
        $clienteTexto = trim($a['cliente'] ?? '');
        $cliente = isset($a['cliente_id']) ? Cliente::find($a['cliente_id']) : Cliente::porNombre($clienteTexto);
        if (isset($a['cliente_id']) && ! $cliente) {
            throw new InvalidArgumentException('Ese cliente no existe o fue borrado. Usa buscar_clientes para escogerlo.');
        }
        if ($cliente && $clienteTexto !== '' && Cliente::porNombre($clienteTexto)?->id !== $cliente->id) {
            throw new InvalidArgumentException('El nombre y el ID del cliente no coinciden. Escoge el cliente correcto.');
        }
        if (! $cliente && $clienteTexto === '') {
            throw new InvalidArgumentException('Escoge un cliente existente o escribe el nombre del prospecto.');
        }
        $detalle = trim($a['detalle'] ?? '');
        $partidas = [];
        foreach ($a['partidas'] ?? [] as $p) {
            if (trim($p['descripcion']) === '') {
                throw new InvalidArgumentException('Cada partida necesita una descripción.');
            }
            $cantidad = (float) $p['cantidad'];
            if (($error = Limites::partida($cantidad, 0)) || round($cantidad, 3) <= 0) {
                throw new InvalidArgumentException($error ?? 'La cantidad mínima es 0.001.');
            }
            $partidas[] = ['descripcion' => trim($p['descripcion']), 'cantidad' => round($cantidad, 3),
                'unidad' => mb_strtoupper(trim($p['unidad'] ?? '') ?: 'PZA'), 'modelo' => trim($p['modelo'] ?? ''), 'ligada' => null];
        }
        if ($partidas === [] && $detalle === '') {
            throw new InvalidArgumentException('Anota lo que pidió el cliente en detalle o en las partidas.');
        }
        $datos = [
            'cliente_id' => $cliente?->id, 'cliente_texto' => $cliente ? null : $clienteTexto,
            'contacto_texto' => trim($a['contacto'] ?? '') ?: null, 'canal' => $a['canal'] ?? 'whatsapp',
            'recibida_at' => isset($a['recibida_at']) ? Carbon::parse($a['recibida_at'], config('app.timezone'))->setTimezone(config('app.timezone')) : now(),
            'detalle' => $detalle ?: null, 'partidas' => $partidas,
        ];
        // La fecha implícita no cambia la huella de un reintento. La huella conserva la petición original.
        $huella = $datos;
        // Resolver un prospecto a un cliente recién dado de alta no cambia la petición original.
        $huella['cliente_id'] = isset($a['cliente_id']) ? (int) $a['cliente_id'] : null;
        $huella['cliente_texto'] = Nombres::llave($clienteTexto);
        $huella['recibida_at'] = isset($a['recibida_at']) ? $datos['recibida_at']->toIso8601String() : null;
        $huella = hash('sha256', json_encode($huella, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $clave = hash('sha256', auth()->id().'|'.trim($a['clave_solicitud']));
        $s = Solicitudes::crear($datos, auth()->user(), $clave, $huella);

        return self::detalle($s) + ['mensaje' => 'Solicitud registrada en Por cotizar. Consulta su estado actual; el equipo puede haberla tomado.'];
    }

    private static function buscar(array $a): array
    {
        $a = Validator::make($a, [
            'texto' => ['nullable', 'string', 'max:250'],
            'estatus' => ['sometimes', 'required', 'in:'.implode(',', array_keys(SolicitudCotizacion::ESTATUS))],
            'desde' => ['sometimes', 'required', 'date_format:Y-m-d'], 'hasta' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'limite' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ], self::MENSAJES)->validate();
        $q = SolicitudCotizacion::with(['cliente', 'tomadaPor', 'creadaPor', 'cotizacion']);
        if (filled($a['texto'] ?? null)) {
            $texto = '%'.addcslashes(trim($a['texto']), '%_\\').'%';
            $q->where(fn ($w) => $w->where('cliente_texto', 'like', $texto)->orWhere('contacto_texto', 'like', $texto)
                ->orWhere('detalle', 'like', $texto)->orWhereRaw('LOWER(partidas) LIKE ?', [mb_strtolower($texto)])
                ->orWhereHas('cliente', fn ($c) => $c->where('nombre', 'like', $texto)));
        }
        if (isset($a['estatus'])) {
            $q->where('estatus', $a['estatus']);
        }
        if (isset($a['desde'])) {
            $q->where('recibida_at', '>=', $a['desde'].' 00:00:00');
        }
        if (isset($a['hasta'])) {
            $q->where('recibida_at', '<=', $a['hasta'].' 23:59:59');
        }

        return ['solicitudes' => $q->orderByDesc('recibida_at')->orderByDesc('id')->limit($a['limite'] ?? 20)->get()->map(fn ($s) => self::resumen($s))->all()];
    }

    private static function ver(array $a): array
    {
        $folio = trim((string) ($a['folio'] ?? ''));
        if (! preg_match('/^(?:S-)?([1-9]\d{0,9})$/i', $folio, $m)) {
            throw new InvalidArgumentException('Indica el folio de solicitud, por ejemplo S-12.');
        }
        $s = SolicitudCotizacion::where('folio', (int) $m[1])->first();
        if (! $s) {
            throw new InvalidArgumentException('No existe la solicitud '.$folio.'.');
        }

        return self::detalle($s);
    }

    private static function resumen(SolicitudCotizacion $s): array
    {
        return [
            'id' => 'solicitud:'.$s->folio, 'folio' => $s->folioTexto(), 'cliente' => $s->nombreCliente(), 'cliente_id' => $s->cliente_id,
            'contacto' => $s->contacto_texto, 'canal' => $s->canal, 'recibida_at' => $s->recibida_at->toIso8601String(),
            'estatus' => $s->estatus, 'responsable' => $s->tomadaPor?->nombreReal(), 'creada_por' => $s->creadaPor?->nombreReal(),
            'cotizacion_folio' => $s->cotizacion?->folio, 'partidas' => count((array) $s->partidas), 'url' => route('solicitudes.ver', $s),
        ];
    }

    private static function detalle(SolicitudCotizacion $s): array
    {
        return array_replace(self::resumen($s), [
            'detalle' => $s->detalle, 'partidas' => $s->partidas ?? [],
            'archivos' => $s->archivos->map(fn ($a) => ['nombre' => $a->nombre, 'url' => route('solicitudes.archivo', $a)])->all(),
            'bitacora' => $s->eventos()->limit(50)->get()->map(fn ($e) => ['tipo' => $e->tipo, 'descripcion' => $e->descripcion,
                'fecha' => $e->created_at?->toIso8601String()])->all(),
        ]);
    }
}
