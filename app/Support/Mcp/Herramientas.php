<?php

namespace App\Support\Mcp;

use App\Enums\EstadoCotizacion;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\Venta;
use App\Support\EliminarCotizacion;
use App\Support\Folios;
use App\Support\Limites;
use App\Support\Nombres;
use App\Support\Precios;
use App\Support\UnificarDuplicados;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Herramientas del MCP de MASUME para asistentes de IA (Claude, ChatGPT).
 * Cotizaciones y tablero interno: nunca mandan documentos al cliente ni generan OC a proveedores.
 */
final class Herramientas
{
    public const LIMITE = 50;

    /** @return list<array> definiciones para tools/list */
    public static function definiciones(): array
    {
        $texto = fn (string $d) => ['type' => 'string', 'description' => $d];
        $entero = fn (string $d) => ['type' => 'integer', 'description' => $d];
        $lectura = ['readOnlyHint' => true, 'openWorldHint' => false];
        $partida = [
            'type' => 'object',
            'properties' => [
                'descripcion' => $texto('Descripción de la partida (sale en el PDF).'),
                'cantidad' => ['type' => 'number'],
                'unidad' => $texto('PZA, JGO, MT, CAJA… (por defecto PZA).'),
                'marca' => $texto('Marca (opcional).'),
                'modelo' => $texto('Modelo o SKU (interno, no sale al cliente).'),
                'proveedor' => $texto('Proveedor (interno).'),
                'costo' => ['type' => 'number', 'description' => 'Costo unitario SIN IVA (interno).'],
                'utilidad_pct' => ['type' => 'number', 'description' => 'Utilidad en % sobre el costo. Con costo calcula el precio.'],
                'precio' => ['type' => 'number', 'description' => 'Precio unitario SIN IVA al cliente (si no se da, se calcula con costo y utilidad).'],
                'tiempo_entrega' => $texto('Tiempo de entrega (opcional).'),
            ],
            'required' => ['descripcion', 'cantidad'],
        ];

        return [
            ['name' => 'search', 'title' => 'Buscar en el sistema',
                'description' => 'Busca cotizaciones, pedidos, clientes y proveedores de MASUME por texto (nombre, folio, OC, descripción). Devuelve ids para usar con fetch.',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => $texto('Texto a buscar')], 'required' => ['query']], 'annotations' => $lectura],
            ['name' => 'fetch', 'title' => 'Ver detalle',
                'description' => 'Detalle completo de un resultado de search (id como "cotizacion:10600", "pedido:49", "cliente:12", "proveedor:5", "oc:7").',
                'inputSchema' => ['type' => 'object', 'properties' => ['id' => $texto('Id devuelto por search')], 'required' => ['id']], 'annotations' => $lectura],
            ['name' => 'buscar_cotizaciones', 'title' => 'Buscar cotizaciones',
                'description' => 'Lista cotizaciones (más recientes primero) filtrando por cliente, texto en partidas, estado o fechas.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'cliente' => $texto('Parte del nombre del cliente'), 'texto' => $texto('Texto en la descripción o modelo de alguna partida'),
                    'estado' => ['type' => 'string', 'enum' => array_column(EstadoCotizacion::cases(), 'value')],
                    'desde' => $texto('Fecha inicial AAAA-MM-DD'), 'hasta' => $texto('Fecha final AAAA-MM-DD'), 'limite' => $entero('Máximo 50 (20 por defecto)'),
                ]], 'annotations' => $lectura],
            ['name' => 'ver_cotizacion', 'title' => 'Ver cotización',
                'description' => 'Cotización por folio con todas sus partidas: costo, proveedor, utilidad y precio (datos internos).',
                'inputSchema' => ['type' => 'object', 'properties' => ['folio' => $entero('Folio, p. ej. 10600')], 'required' => ['folio']], 'annotations' => $lectura],
            ['name' => 'precios_anteriores', 'title' => 'Precios anteriores',
                'description' => 'Partidas cotizadas antes que coinciden con un texto (descripción o modelo): fecha, cliente, cantidad, costo, utilidad, precio y proveedor. Útil para cotizar igual que antes.',
                'inputSchema' => ['type' => 'object', 'properties' => ['texto' => $texto('Palabras de la descripción o el modelo'), 'cliente' => $texto('Solo de este cliente (opcional)'), 'limite' => $entero('Máximo 50 (20 por defecto)')], 'required' => ['texto']], 'annotations' => $lectura],
            ['name' => 'buscar_ventas', 'title' => 'Buscar ventas (facturas y notas)',
                'description' => 'Finanzas: facturas y notas de venta con costo, utilidad, estatus de pago y origen (sheets, sicar, manual). '
                    .'Filtra por texto (cliente, folio o concepto), estatus, origen, tipo y fechas de factura. Incluye un resumen con totales.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'texto' => $texto('Cliente, folio o concepto'),
                    'estatus' => ['type' => 'string', 'enum' => ['pendiente', 'pagada', 'cancelada']],
                    'origen' => ['type' => 'string', 'enum' => ['sheets', 'sicar', 'manual']],
                    'tipo' => ['type' => 'string', 'enum' => ['factura', 'nota']],
                    'desde' => $texto('Fecha de factura inicial AAAA-MM-DD'),
                    'hasta' => $texto('Fecha de factura final AAAA-MM-DD'),
                    'limite' => $entero('Máximo 200 (50 por defecto)'),
                ]], 'annotations' => $lectura],
            ['name' => 'buscar_pedidos', 'title' => 'Buscar pedidos',
                'description' => 'Pedidos (tarjetas del tablero) por cliente, número de OC del cliente o lista del tablero.',
                'inputSchema' => ['type' => 'object', 'properties' => ['texto' => $texto('Cliente o número de OC'), 'incluir_archivados' => ['type' => 'boolean'], 'limite' => $entero('Máximo 50 (20 por defecto)')]], 'annotations' => $lectura],
            ['name' => 'ver_pedido', 'title' => 'Ver pedido',
                'description' => 'Pedido por folio (P-49 → 49) con partidas, estado de cada una, OC a proveedores y totales.',
                'inputSchema' => ['type' => 'object', 'properties' => ['folio' => $entero('Folio del pedido')], 'required' => ['folio']], 'annotations' => $lectura],
            ['name' => 'buscar_clientes', 'title' => 'Buscar clientes',
                'description' => 'Clientes por nombre con contactos, correo, teléfono y reglas (portal, OC después de facturar).',
                'inputSchema' => ['type' => 'object', 'properties' => ['texto' => $texto('Parte del nombre')], 'required' => ['texto']], 'annotations' => $lectura],
            ['name' => 'buscar_proveedores', 'title' => 'Buscar proveedores',
                'description' => 'Proveedores por nombre con sus contactos (vendedores y teléfonos).',
                'inputSchema' => ['type' => 'object', 'properties' => ['texto' => $texto('Parte del nombre')], 'required' => ['texto']], 'annotations' => $lectura],
            ['name' => 'ordenes_proveedor', 'title' => 'OC a proveedores',
                'description' => 'Órdenes de compra a proveedores (más recientes primero), filtrando por proveedor o estado.',
                'inputSchema' => ['type' => 'object', 'properties' => ['proveedor' => $texto('Parte del nombre del proveedor'), 'estado' => $texto('emitida, enviada, recibida o cancelada'), 'limite' => $entero('Máximo 50 (20 por defecto)')]], 'annotations' => $lectura],
            ['name' => 'crear_cotizacion_borrador', 'title' => 'Crear cotización en borrador',
                'description' => 'Crea una cotización NUEVA en BORRADOR (no se manda a nadie; una persona la revisa en el sistema). Si piden anotar en Por cotizar, usa crear_solicitud_por_cotizar, no esta herramienta. El cliente debe existir (usa buscar_clientes); si no existe, pon crear_cliente=true.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'cliente' => $texto('Nombre del cliente'), 'crear_cliente' => ['type' => 'boolean', 'description' => 'Darlo de alta si no existe'],
                    'notas' => $texto('Notas para el PDF (opcional)'), 'partidas' => ['type' => 'array', 'items' => $partida, 'minItems' => 1],
                ], 'required' => ['cliente', 'partidas']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false]],
            ['name' => 'agregar_partidas', 'title' => 'Agregar partidas a un borrador',
                'description' => 'Agrega partidas al final de una cotización que sigue en BORRADOR.',
                'inputSchema' => ['type' => 'object', 'properties' => ['folio' => $entero('Folio de la cotización en borrador'), 'partidas' => ['type' => 'array', 'items' => $partida, 'minItems' => 1]], 'required' => ['folio', 'partidas']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false]],
            ['name' => 'editar_cotizacion', 'title' => 'Editar una cotización',
                'description' => 'Cambia una cotización que ya existe (en BORRADOR o ENVIADA; si ya se envió, el sistema lo anota como nueva revisión). '
                    .'Usa ver_cotizacion para saber el número (orden) de cada partida. En "cambios" solo pon los campos que cambian; si das costo o '
                    .'utilidad sin precio, el precio se recalcula. "quitar" borra partidas por su número y "agregar" pone nuevas al final. '
                    .'Úsala en vez de crear otra cotización cuando te pidan modificar una. No manda nada al cliente.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'folio' => $entero('Folio de la cotización'),
                    'cliente' => $texto('Cambiar el cliente (debe existir)'),
                    'notas' => $texto('Nuevas notas para el PDF (texto vacío las borra)'),
                    'vigencia_dias' => $entero('Días de vigencia'),
                    'cambios' => ['type' => 'array', 'description' => 'Partidas a modificar', 'items' => [
                        'type' => 'object',
                        'properties' => ['partida' => $entero('Número (orden) de la partida')] + array_diff_key($partida['properties'], [])
                            + ['incluir' => ['type' => 'boolean', 'description' => 'false = no sale en el PDF ni suma']],
                        'required' => ['partida'],
                    ]],
                    'quitar' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Números (orden) de partidas a borrar'],
                    'agregar' => ['type' => 'array', 'items' => $partida, 'description' => 'Partidas nuevas al final'],
                ], 'required' => ['folio']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false]],
            ['name' => 'eliminar_cotizacion', 'title' => 'Eliminar una cotización en borrador',
                'description' => 'Elimina una cotización que sigue en BORRADOR (por ejemplo una que se creó de más). Las enviadas o que ya son pedido no se eliminan desde aquí.',
                'inputSchema' => ['type' => 'object', 'properties' => ['folio' => $entero('Folio de la cotización en borrador')], 'required' => ['folio']],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]],
            ...HerramientasFinanzas::definiciones(),
            ...HerramientasGastos::definiciones(),
            ...HerramientasSolicitudes::definiciones(),
            ...HerramientasPedidos::definiciones(),
        ];
    }

    public static function ejecutar(string $nombre, array $a): array
    {
        if (HerramientasPedidos::maneja($nombre)) {
            return HerramientasPedidos::ejecutar($nombre, $a);
        }
        if (HerramientasSolicitudes::maneja($nombre)) {
            return HerramientasSolicitudes::ejecutar($nombre, $a);
        }
        if (HerramientasGastos::maneja($nombre)) {
            return HerramientasGastos::ejecutar($nombre, $a);
        }
        if (HerramientasFinanzas::maneja($nombre)) {
            return HerramientasFinanzas::ejecutar($nombre, $a);
        }

        return match ($nombre) {
            'search' => self::search((string) ($a['query'] ?? '')),
            'fetch' => self::fetch((string) ($a['id'] ?? '')),
            'buscar_cotizaciones' => self::buscarCotizaciones($a),
            'ver_cotizacion' => self::cotizacion(self::folioCot($a['folio'] ?? null)),
            'precios_anteriores' => self::preciosAnteriores($a),
            'buscar_ventas' => self::buscarVentas($a),
            'buscar_pedidos' => self::buscarPedidos($a),
            'ver_pedido' => self::pedido((int) ($a['folio'] ?? 0)),
            'buscar_clientes' => self::buscarClientes((string) ($a['texto'] ?? '')),
            'buscar_proveedores' => self::buscarProveedores((string) ($a['texto'] ?? '')),
            'ordenes_proveedor' => self::ordenes($a),
            'crear_cotizacion_borrador' => self::crearBorrador($a),
            'agregar_partidas' => self::agregarPartidas($a),
            'editar_cotizacion' => self::editarCotizacion($a),
            'eliminar_cotizacion' => self::eliminarCotizacion($a),
            default => throw new InvalidArgumentException("Herramienta desconocida: {$nombre}"),
        };
    }

    // ---------------------------------------------------------------- finanzas

    private static function buscarVentas(array $a): array
    {
        $fecha = fn (string $k) => isset($a[$k]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $a[$k]) ? (string) $a[$k] : null;
        $q = Venta::query()
            ->when(filled($a['texto'] ?? null), function ($q) use ($a) {
                foreach (array_slice(preg_split('/\s+/u', trim((string) $a['texto'])) ?: [], 0, 6) as $p) {
                    $q->where(fn ($w) => $w->where('cliente_nombre', 'like', self::like($p))->orWhere('folio', 'like', self::like($p))
                        ->orWhere('concepto', 'like', self::like($p)));
                }
            })
            ->when(isset(Venta::ESTATUS[$a['estatus'] ?? '']), fn ($q) => $q->where('estatus', $a['estatus']))
            ->when(in_array($a['origen'] ?? null, ['sheets', 'sicar', 'manual'], true), fn ($q) => $q->where('origen', $a['origen']))
            ->when(isset(Venta::TIPOS[$a['tipo'] ?? '']), fn ($q) => $q->where('tipo', $a['tipo']))
            ->when($fecha('desde'), fn ($q, $d) => $q->where('fecha', '>=', $d))
            ->when($fecha('hasta'), fn ($q, $h) => $q->where('fecha', '<=', $h.' 23:59:59'));

        $t = (clone $q)->toBase()->selectRaw("COUNT(*) n, SUM(CASE WHEN estatus <> 'cancelada' THEN subtotal ELSE 0 END) venta, "
            ."SUM(CASE WHEN estatus <> 'cancelada' THEN costo ELSE 0 END) costo, SUM(CASE WHEN estatus = 'pendiente' THEN total ELSE 0 END) por_cobrar")->first();
        $limite = max(1, min(200, (int) ($a['limite'] ?? 50)));

        return [
            'resumen' => [
                'documentos' => (int) $t->n,
                'venta_sin_iva' => round((float) $t->venta, 2),
                'costo' => round((float) $t->costo, 2),
                'utilidad' => round((float) $t->venta - (float) $t->costo, 2),
                'por_cobrar_con_iva' => round((float) $t->por_cobrar, 2),
                'por_origen' => (clone $q)->toBase()->selectRaw('origen, COUNT(*) n')->groupBy('origen')->pluck('n', 'origen')->all(),
            ],
            'ventas' => $q->orderByDesc('fecha')->orderByDesc('id')->limit($limite)->get()->map(fn (Venta $v) => [
                'folio' => $v->folio, 'tipo' => $v->tipo, 'fecha' => $v->fecha->toDateString(), 'cliente' => $v->cliente_nombre,
                'subtotal' => (float) $v->subtotal, 'costo' => (float) $v->costo, 'utilidad' => $v->utilidad(), 'total' => (float) $v->total,
                'estatus' => $v->estatus, 'fecha_pago' => $v->fecha_pago?->toDateString(), 'portal' => $v->estatus_portal,
                'origen' => $v->origen, 'sicar_id' => $v->sicar_id, 'factura_sustituta' => $v->factura_sustituta,
                'notas' => $v->notas ? mb_strimwidth($v->notas, 0, 160, '…') : null,
            ])->all(),
        ];
    }

    // ---------------------------------------------------------------- utilidades

    private static function limite(array $a): int
    {
        return max(1, min(self::LIMITE, (int) ($a['limite'] ?? 20)));
    }

    private static function like(string $t): string
    {
        return '%'.addcslashes(trim($t), '%_\\').'%';
    }

    private static function folioCot(mixed $f): Cotizacion
    {
        $c = Cotizacion::with(['cliente', 'contacto', 'vendedor'])->where('folio', (int) preg_replace('/\D/', '', (string) $f))->first();
        if (! $c) {
            throw new InvalidArgumentException("No existe la cotización {$f}.");
        }

        return $c;
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) ? round((float) $v, 4) : null;
    }

    private static function resumenCot(Cotizacion $c): array
    {
        return ['id' => 'cotizacion:'.$c->folio, 'folio' => $c->folio, 'cliente' => $c->cliente?->nombre, 'fecha' => $c->fecha?->toDateString(),
            'estado' => $c->estado?->value, 'total' => (float) $c->total, 'vendedor' => $c->vendedor?->name, 'url' => route('cotizaciones.editar', $c)];
    }

    // ---------------------------------------------------------------- consultas

    private static function search(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return ['results' => []];
        }
        $r = [];
        $digitos = preg_replace('/\D/', '', $q);
        if ($digitos !== '') {
            foreach (Cotizacion::with('cliente')->where('folio', (int) $digitos)->limit(1)->get() as $c) {
                $r[] = ['id' => 'cotizacion:'.$c->folio, 'title' => "Cotización {$c->folio} · {$c->cliente?->nombre}", 'url' => route('cotizaciones.editar', $c)];
            }
            foreach (Pedido::with('cliente')->where('folio', (int) $digitos)->orWhere('numero_oc_cliente', 'like', self::like($q))->limit(5)->get() as $p) {
                $r[] = ['id' => 'pedido:'.$p->folio, 'title' => "Pedido P-{$p->folio} · {$p->cliente?->nombre}".($p->numero_oc_cliente ? " · OC {$p->numero_oc_cliente}" : ''), 'url' => url('/pedidos?pedido='.$p->id)];
            }
        }
        foreach (Cliente::where('nombre', 'like', self::like($q))->limit(5)->get() as $c) {
            $r[] = ['id' => 'cliente:'.$c->id, 'title' => "Cliente {$c->nombre}", 'url' => url('/clientes/'.$c->id)];
        }
        foreach (Proveedor::where('nombre', 'like', self::like($q))->limit(5)->get() as $p) {
            $r[] = ['id' => 'proveedor:'.$p->id, 'title' => "Proveedor {$p->nombre}", 'url' => url('/proveedores/'.$p->id)];
        }
        $cots = Cotizacion::with('cliente')->whereHas('partidas', fn ($w) => $w->where('descripcion', 'like', self::like($q))->orWhere('modelo', 'like', self::like($q)))
            ->latest('fecha')->limit(10)->get();
        foreach ($cots as $c) {
            $r[] = ['id' => 'cotizacion:'.$c->folio, 'title' => "Cotización {$c->folio} · {$c->cliente?->nombre} · ".$c->fecha?->format('d/m/Y'), 'url' => route('cotizaciones.editar', $c)];
        }

        return ['results' => array_values(collect($r)->unique('id')->all())];
    }

    private static function fetch(string $id): array
    {
        [$tipo, $valor] = array_pad(explode(':', $id, 2), 2, '');
        $datos = match ($tipo) {
            'cotizacion' => self::cotizacion(self::folioCot($valor)),
            'pedido' => self::pedido((int) $valor),
            'cliente' => self::cliente((int) $valor),
            'proveedor' => self::proveedor((int) $valor),
            'oc' => self::orden((int) $valor),
            default => throw new InvalidArgumentException('Id no reconocido. Usa el id que devuelve search.'),
        };

        return ['id' => $id, 'title' => $datos['titulo'] ?? $id, 'text' => json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'url' => $datos['url'] ?? null, 'metadata' => ['tipo' => $tipo]];
    }

    private static function buscarCotizaciones(array $a): array
    {
        $q = Cotizacion::with(['cliente:id,nombre', 'vendedor:id,name'])
            ->when(! empty($a['cliente']), fn ($w) => $w->whereHas('cliente', fn ($c) => $c->where('nombre', 'like', self::like($a['cliente']))))
            ->when(! empty($a['texto']), fn ($w) => $w->whereHas('partidas', fn ($p) => $p->where('descripcion', 'like', self::like($a['texto']))->orWhere('modelo', 'like', self::like($a['texto']))))
            ->when(! empty($a['estado']), fn ($w) => $w->where('estado', $a['estado']))
            ->when(! empty($a['desde']), fn ($w) => $w->whereDate('fecha', '>=', $a['desde']))
            ->when(! empty($a['hasta']), fn ($w) => $w->whereDate('fecha', '<=', $a['hasta']))
            ->orderByDesc('fecha')->orderByDesc('folio')->limit(self::limite($a));

        return ['cotizaciones' => $q->get()->map(fn ($c) => self::resumenCot($c))->all()];
    }

    private static function cotizacion(Cotizacion $c): array
    {
        $c->load(['partidas.proveedor:id,nombre']);

        return self::resumenCot($c) + [
            'titulo' => "Cotización {$c->folio} · {$c->cliente?->nombre}",
            'revision' => (int) $c->revision,
            'contacto' => $c->contacto?->nombre,
            'vigencia_dias' => $c->vigencia_dias,
            'forma_pago' => $c->forma_pago,
            'notas' => $c->notas,
            'subtotal' => (float) $c->subtotal, 'iva' => (float) $c->iva,
            'partidas' => $c->partidas->sortBy('orden')->values()->map(fn (CotizacionPartida $p) => [
                'orden' => $p->orden, 'incluida' => (bool) $p->incluir, 'descripcion' => $p->descripcion, 'marca' => $p->marca, 'modelo' => $p->modelo,
                'cantidad' => (float) $p->cantidad, 'unidad' => $p->unidad, 'proveedor' => $p->proveedor?->nombre, 'vendedor_proveedor' => $p->proveedor_vendedor,
                'costo_sin_iva' => $p->costo !== null ? (float) $p->costo : null, 'utilidad_pct' => $p->utilidad_pct !== null ? (float) $p->utilidad_pct : null,
                'precio_sin_iva' => (float) $p->precio, 'importe' => (float) $p->importe, 'tiempo_entrega' => $p->tiempo_entrega, 'link_compra' => $p->link_compra,
            ])->all(),
        ];
    }

    private static function preciosAnteriores(array $a): array
    {
        $palabras = array_filter(preg_split('/\s+/', trim((string) ($a['texto'] ?? ''))) ?: []);
        if ($palabras === []) {
            throw new InvalidArgumentException('Escribe qué buscar.');
        }
        $q = CotizacionPartida::query()->with(['cotizacion:id,folio,cliente_id,fecha,estado', 'cotizacion.cliente:id,nombre', 'proveedor:id,nombre'])
            ->where(function ($w) use ($palabras) {
                foreach ($palabras as $p) {
                    $w->where(fn ($x) => $x->where('descripcion', 'like', self::like($p))->orWhere('modelo', 'like', self::like($p)));
                }
            })
            ->when(! empty($a['cliente']), fn ($w) => $w->whereHas('cotizacion.cliente', fn ($c) => $c->where('nombre', 'like', self::like($a['cliente']))))
            ->where('precio', '>', 0)
            ->orderByDesc('id')->limit(self::limite($a));

        return ['partidas' => $q->get()->map(fn (CotizacionPartida $p) => [
            'folio' => $p->cotizacion?->folio, 'fecha' => $p->cotizacion?->fecha?->toDateString(), 'cliente' => $p->cotizacion?->cliente?->nombre,
            'estado_cotizacion' => $p->cotizacion?->estado?->value, 'descripcion' => $p->descripcion, 'marca' => $p->marca, 'modelo' => $p->modelo,
            'cantidad' => (float) $p->cantidad, 'unidad' => $p->unidad, 'proveedor' => $p->proveedor?->nombre,
            'costo_sin_iva' => $p->costo !== null ? (float) $p->costo : null, 'utilidad_pct' => $p->utilidad_pct !== null ? (float) $p->utilidad_pct : null, 'precio_sin_iva' => (float) $p->precio,
        ])->all()];
    }

    private static function buscarPedidos(array $a): array
    {
        $t = trim((string) ($a['texto'] ?? ''));
        $q = Pedido::with(['cliente:id,nombre', 'responsable:id,name'])
            ->when(empty($a['incluir_archivados']), fn ($w) => $w->whereNull('archivado_at'))
            ->when($t !== '', fn ($w) => $w->where(fn ($x) => $x->where('numero_oc_cliente', 'like', self::like($t))
                ->orWhereHas('cliente', fn ($c) => $c->where('nombre', 'like', self::like($t)))
                ->orWhere('folio', (int) preg_replace('/\D/', '', $t) ?: -1)))
            ->orderByDesc('id')->limit(self::limite($a));

        return ['pedidos' => $q->get()->map(fn (Pedido $p) => self::resumenPedido($p))->all()];
    }

    private static function resumenPedido(Pedido $p): array
    {
        return ['id' => 'pedido:'.$p->folio, 'folio' => 'P-'.$p->folio, 'titulo' => $p->titulo(), 'cliente' => $p->cliente?->nombre,
            'oc_cliente' => $p->numero_oc_cliente, 'lista' => $p->columna?->etiqueta(), 'responsable' => $p->responsable?->name,
            'fecha_compromiso' => $p->fecha_compromiso?->toDateString(), 'etiquetas' => $p->etiquetas(), 'total_venta' => (float) $p->total_venta,
            'total_costo' => (float) $p->total_costo, 'archivado' => $p->archivado_at !== null, 'url' => url('/pedidos?pedido='.$p->id)];
    }

    private static function pedido(int $folio): array
    {
        $p = Pedido::with(['cliente', 'responsable', 'partidas.proveedor:id,nombre', 'ordenesProveedor.proveedor:id,nombre'])->where('folio', $folio)->first();
        if (! $p) {
            throw new InvalidArgumentException("No existe el pedido P-{$folio}.");
        }

        return self::resumenPedido($p) + [
            'notas' => $p->notas,
            'partidas' => $p->partidas->sortBy('orden')->values()->map(fn ($x) => [
                'descripcion' => $x->descripcion, 'cantidad' => (float) $x->cantidad, 'unidad' => $x->unidad, 'estado' => $x->estado?->etiqueta(),
                'facturada' => (bool) $x->facturada, 'proveedor' => $x->proveedor?->nombre, 'costo_sin_iva' => $x->costo !== null ? (float) $x->costo : null,
                'precio_sin_iva' => (float) $x->precio, 'link_compra' => $x->link_compra,
            ])->all(),
            'ordenes_proveedor' => $p->ordenesProveedor->map(fn ($o) => ['id' => 'oc:'.$o->id, 'folio' => $o->folioTexto(), 'proveedor' => $o->proveedor?->nombre,
                'estado' => $o->estado?->etiqueta(), 'total_costo' => (float) $o->total_costo])->all(),
        ];
    }

    private static function buscarClientes(string $t): array
    {
        return ['clientes' => Cliente::where('nombre', 'like', self::like($t))->orderBy('nombre')->limit(20)->get()->map(fn ($c) => self::cliente($c->id))->all()];
    }

    private static function cliente(int $id): array
    {
        $c = Cliente::with('contactos')->find($id);
        if (! $c) {
            throw new InvalidArgumentException('No existe ese cliente.');
        }

        return ['id' => 'cliente:'.$c->id, 'titulo' => $c->nombre, 'nombre' => $c->nombre, 'razon_social' => $c->razon_social, 'rfc' => $c->rfc,
            'correo' => $c->correo, 'telefono' => $c->telefono, 'direccion' => $c->direccion, 'dias_credito' => $c->dias_credito,
            'oc_despues_de_facturar' => (bool) $c->oc_despues_de_facturar, 'sube_factura_portal' => (bool) $c->sube_factura_portal, 'portal_url' => $c->portal_url,
            'contactos' => $c->contactos->map(fn ($k) => ['nombre' => $k->nombre, 'correo' => $k->correo, 'telefono' => $k->telefono])->all(),
            'cotizaciones' => $c->cotizaciones()->count(), 'url' => url('/clientes/'.$c->id)];
    }

    private static function buscarProveedores(string $t): array
    {
        return ['proveedores' => Proveedor::where('nombre', 'like', self::like($t))->orderBy('nombre')->limit(20)->get()->map(fn ($p) => self::proveedor($p->id))->all()];
    }

    private static function proveedor(int $id): array
    {
        $p = Proveedor::with('contactos')->find($id);
        if (! $p) {
            throw new InvalidArgumentException('No existe ese proveedor.');
        }

        return ['id' => 'proveedor:'.$p->id, 'titulo' => $p->nombre, 'nombre' => $p->nombre, 'telefono' => $p->telefono, 'correo' => $p->correo,
            'contactos' => $p->contactos->map(fn ($k) => ['nombre' => $k->nombre, 'telefono' => $k->telefono, 'correo' => $k->correo, 'principal' => (bool) $k->principal])->all(),
            'url' => url('/proveedores/'.$p->id)];
    }

    private static function ordenes(array $a): array
    {
        $q = OrdenProveedor::with(['proveedor:id,nombre', 'pedido:id,folio,cliente_id', 'pedido.cliente:id,nombre'])
            ->when(! empty($a['proveedor']), fn ($w) => $w->whereHas('proveedor', fn ($p) => $p->where('nombre', 'like', self::like($a['proveedor']))))
            ->when(! empty($a['estado']), fn ($w) => $w->where('estado', $a['estado']))
            ->orderByDesc('id')->limit(self::limite($a));

        return ['ordenes' => $q->get()->map(fn ($o) => self::orden($o->id, $o))->all()];
    }

    private static function orden(int $id, ?OrdenProveedor $o = null): array
    {
        $o ??= OrdenProveedor::with(['proveedor', 'pedido.cliente'])->find($id);
        if (! $o) {
            throw new InvalidArgumentException('No existe esa OC.');
        }

        return ['id' => 'oc:'.$o->id, 'titulo' => $o->folioTexto().' · '.$o->proveedor?->nombre, 'folio' => $o->folioTexto(), 'proveedor' => $o->proveedor?->nombre,
            'estado' => $o->estado?->etiqueta(), 'metodo_pago' => $o->metodo_pago, 'total_costo' => (float) $o->total_costo,
            'pedido' => $o->pedido ? 'P-'.$o->pedido->folio : null, 'cliente' => $o->pedido?->cliente?->nombre, 'fecha' => $o->created_at?->toDateString(),
            'url' => url('/ordenes-proveedor/'.$o->id)];
    }

    // ---------------------------------------------------------------- borradores

    private static function crearBorrador(array $a): array
    {
        $nombre = trim((string) ($a['cliente'] ?? ''));
        $llave = Nombres::llave($nombre);
        if ($llave === '') {
            throw new InvalidArgumentException('Falta el cliente.');
        }
        $cliente = Cliente::porNombre($nombre)
            ?? Cliente::get(['id', 'nombre'])->first(fn ($c) => UnificarDuplicados::errorDeDedo(UnificarDuplicados::base($nombre), UnificarDuplicados::base($c->nombre)));
        if (! $cliente) {
            if (empty($a['crear_cliente'])) {
                $parecidos = Cliente::where('nombre', 'like', self::like(mb_substr($nombre, 0, 6)))->limit(8)->pluck('nombre')->all();
                throw new InvalidArgumentException("No existe el cliente \"{$nombre}\". Parecidos: ".($parecidos ? implode(', ', $parecidos) : 'ninguno').'. Usa el nombre exacto o crear_cliente=true.');
            }
            if (Cliente::borradoConNombre($nombre)) {
                throw new InvalidArgumentException("El cliente \"{$nombre}\" fue borrado en el sistema; usa otro.");
            }
            $cliente = Cliente::create(['nombre' => Nombres::limpiar($nombre)]);
        }

        $cot = DB::transaction(function () use ($cliente, $a) {
            $cot = Cotizacion::create([
                'folio' => Folios::siguiente(),
                'cliente_id' => $cliente->id,
                'vendedor_id' => auth()->id(),
                'fecha' => now()->toDateString(),
                'vigencia_dias' => config('masume.cotizacion.vigencia_dias', 15),
                'condiciones_entrega' => config('masume.cotizacion.condiciones_entrega'),
                'notas' => isset($a['notas']) ? mb_substr((string) $a['notas'], 0, 3000) : null,
                'estado' => EstadoCotizacion::Borrador,
            ]);
            self::guardarPartidas($cot, (array) ($a['partidas'] ?? []));

            return $cot;
        });

        return ['creada' => true, 'mensaje' => 'Cotización creada en BORRADOR. Revísala en el sistema antes de mandarla.'] + self::cotizacion(self::folioCot($cot->folio));
    }

    private static function agregarPartidas(array $a): array
    {
        $cot = self::folioCot($a['folio'] ?? null);
        if ($cot->estado !== EstadoCotizacion::Borrador) {
            throw new InvalidArgumentException("La cotización {$cot->folio} ya no está en borrador; no se le agregan partidas desde aquí.");
        }
        DB::transaction(fn () => self::guardarPartidas($cot, (array) ($a['partidas'] ?? [])));

        return ['agregadas' => count((array) ($a['partidas'] ?? []))] + self::cotizacion(self::folioCot($cot->folio));
    }

    private static function editarCotizacion(array $a): array
    {
        $cot = self::folioCot($a['folio'] ?? null);
        if (! in_array($cot->estado, [EstadoCotizacion::Borrador, EstadoCotizacion::Enviada], true)) {
            throw new InvalidArgumentException("La cotización {$cot->folio} está {$cot->estado->etiqueta()}; solo se editan las que están en borrador o enviadas.");
        }
        $hecho = [];
        DB::transaction(function () use ($cot, $a, &$hecho) {
            $enc = [];
            if (filled($a['cliente'] ?? null)) {
                $cliente = Cliente::porNombre((string) $a['cliente'])
                    ?? throw new InvalidArgumentException('No existe el cliente "'.$a['cliente'].'". Búscalo con buscar_clientes.');
                if ($cliente->id !== $cot->cliente_id) {
                    $enc['cliente_id'] = $cliente->id;
                    $enc['contacto_id'] = null;
                }
            }
            if (array_key_exists('notas', $a)) {
                $enc['notas'] = filled($a['notas']) ? mb_substr((string) $a['notas'], 0, 3000) : null;
            }
            if (isset($a['vigencia_dias'])) {
                $enc['vigencia_dias'] = max(1, min(365, (int) $a['vigencia_dias']));
            }
            if ($enc) {
                $cot->update($enc);
                $hecho[] = 'datos de la cotización';
            }

            $partidas = $cot->partidas()->get()->keyBy('orden');
            foreach ((array) ($a['cambios'] ?? []) as $c) {
                $n = (int) ($c['partida'] ?? 0);
                $p = $partidas[$n] ?? throw new InvalidArgumentException("No existe la partida {$n} en la cotización {$cot->folio}.");
                self::cambiarPartida($p, $c);
                $hecho[] = "partida {$n}";
            }

            foreach (array_unique(array_map('intval', (array) ($a['quitar'] ?? []))) as $n) {
                $p = $partidas[$n] ?? throw new InvalidArgumentException("No existe la partida {$n} en la cotización {$cot->folio}.");
                $p->delete();
                $hecho[] = "quitó la partida {$n}";
            }

            if (! empty($a['agregar'])) {
                self::guardarPartidas($cot, (array) $a['agregar']);
                $hecho[] = count((array) $a['agregar']).' partida(s) nueva(s)';
            }
            if ($hecho === []) {
                throw new InvalidArgumentException('No pediste ningún cambio: usa cambios, quitar, agregar, cliente, notas o vigencia_dias.');
            }
            $cot->recalcularTotales();
        });

        return ['editada' => true, 'cambios' => $hecho, 'mensaje' => 'Cotización actualizada. No se mandó nada al cliente.'] + self::cotizacion(self::folioCot($cot->folio));
    }

    /** Cambia solo los campos que vienen; con costo o utilidad sin precio, recalcula el precio. */
    private static function cambiarPartida(CotizacionPartida $p, array $c): void
    {
        $nuevo = [];
        if (array_key_exists('descripcion', $c)) {
            $desc = trim((string) $c['descripcion']);
            if ($desc === '') {
                throw new InvalidArgumentException('La descripción no puede quedar vacía.');
            }
            $nuevo['descripcion'] = mb_strtoupper(mb_substr($desc, 0, 2000));
        }
        if (array_key_exists('cantidad', $c)) {
            $cant = self::num($c['cantidad']);
            if ($cant === null || $cant <= 0) {
                throw new InvalidArgumentException('La cantidad debe ser mayor a 0.');
            }
            $nuevo['cantidad'] = $cant;
        }
        foreach (['marca' => 250, 'modelo' => 250, 'tiempo_entrega' => 250] as $campo => $max) {
            if (array_key_exists($campo, $c)) {
                $nuevo[$campo] = filled($c[$campo]) ? mb_substr((string) $c[$campo], 0, $max) : null;
            }
        }
        if (array_key_exists('unidad', $c)) {
            $nuevo['unidad'] = mb_substr(mb_strtoupper(trim((string) $c['unidad'])) ?: 'PZA', 0, 10);
        }
        if (array_key_exists('incluir', $c)) {
            $nuevo['incluir'] = (bool) $c['incluir'];
        }
        if (array_key_exists('proveedor', $c)) {
            $nuevo['proveedor_id'] = filled($c['proveedor']) ? self::proveedorPorNombre((string) $c['proveedor'])->id : null;
        }

        $costo = array_key_exists('costo', $c) ? self::num($c['costo']) : ($p->costo !== null ? (float) $p->costo : null);
        $utilidad = array_key_exists('utilidad_pct', $c) ? self::num($c['utilidad_pct']) : ($p->utilidad_pct !== null ? (float) $p->utilidad_pct : null);
        $precio = array_key_exists('precio', $c) ? self::num($c['precio']) : null;
        if (array_key_exists('costo', $c)) {
            $nuevo['costo'] = $costo;
        }
        if ($precio !== null) {
            $nuevo['precio'] = round(max(0, $precio), 2);
            $nuevo['utilidad_pct'] = $costo ? Precios::utilidadDesdePrecio($costo, $nuevo['precio']) : $utilidad;
        } elseif ((array_key_exists('costo', $c) || array_key_exists('utilidad_pct', $c)) && $costo !== null && $utilidad !== null) {
            $nuevo['utilidad_pct'] = $utilidad;
            $nuevo['precio'] = Precios::precioDesdeUtilidad($costo, $utilidad);
        } elseif (array_key_exists('utilidad_pct', $c)) {
            $nuevo['utilidad_pct'] = $utilidad;
        }
        if ($error = Limites::partida((float) ($nuevo['cantidad'] ?? $p->cantidad), (float) ($nuevo['precio'] ?? $p->precio), array_key_exists('costo', $nuevo) ? $nuevo['costo'] : ($p->costo !== null ? (float) $p->costo : null))) {
            throw new InvalidArgumentException("Partida {$p->orden}: {$error}");
        }
        $nuevo['importe'] = Precios::importe((float) ($nuevo['precio'] ?? $p->precio), (float) ($nuevo['cantidad'] ?? $p->cantidad));
        $p->update($nuevo);
    }

    private static function eliminarCotizacion(array $a): array
    {
        $cot = self::folioCot($a['folio'] ?? null);
        if ($cot->estado !== EstadoCotizacion::Borrador) {
            throw new InvalidArgumentException("La cotización {$cot->folio} ya no está en borrador; elimínala desde el sistema si de verdad hace falta.");
        }
        try {
            EliminarCotizacion::eliminar($cot, auth()->user());
        } catch (DomainException $e) {
            throw new InvalidArgumentException($e->getMessage());
        }

        return ['eliminada' => true, 'folio' => $cot->folio, 'mensaje' => "Se eliminó la cotización {$cot->folio}."];
    }

    /** Proveedor por nombre (o error de dedo); si no existe se da de alta, salvo que se haya borrado a propósito. */
    private static function proveedorPorNombre(string $nombre): Proveedor
    {
        return Proveedor::porNombre($nombre)
            ?? Proveedor::get(['id', 'nombre'])->first(fn ($x) => UnificarDuplicados::errorDeDedo(UnificarDuplicados::base($nombre), UnificarDuplicados::base($x->nombre)))
            ?? (Proveedor::borradoConNombre($nombre)
                ? throw new InvalidArgumentException('El proveedor "'.$nombre.'" fue borrado en el sistema; usa otro.')
                : Proveedor::create(['nombre' => Nombres::limpiar($nombre)]));
    }

    private static function guardarPartidas(Cotizacion $cot, array $partidas): void
    {
        if ($partidas === [] || count($partidas) > 200) {
            throw new InvalidArgumentException('Manda de 1 a 200 partidas.');
        }
        $orden = (int) $cot->partidas()->max('orden');
        foreach ($partidas as $i => $p) {
            $desc = trim((string) ($p['descripcion'] ?? ''));
            $cantidad = self::num($p['cantidad'] ?? null);
            if ($desc === '' || $cantidad === null || $cantidad <= 0) {
                throw new InvalidArgumentException('La partida '.($i + 1).' necesita descripción y cantidad mayor a 0.');
            }
            $costo = self::num($p['costo'] ?? null);
            $utilidad = self::num($p['utilidad_pct'] ?? null);
            $precio = self::num($p['precio'] ?? null);
            if ($precio === null && $costo !== null && $utilidad !== null) {
                $precio = Precios::precioDesdeUtilidad($costo, $utilidad);
            }
            if ($utilidad === null && $costo && $precio) {
                $utilidad = Precios::utilidadDesdePrecio($costo, $precio);
            }
            $precio = round(max(0, (float) $precio), 2);
            if ($error = Limites::partida($cantidad, $precio, $costo)) {
                throw new InvalidArgumentException('Partida '.($i + 1).': '.$error);
            }
            $proveedor = null;
            if (Nombres::llave((string) ($p['proveedor'] ?? '')) !== '') {
                $proveedor = self::proveedorPorNombre((string) $p['proveedor']);
            }
            CotizacionPartida::create([
                'cotizacion_id' => $cot->id,
                'orden' => ++$orden,
                'incluir' => true,
                'descripcion' => mb_strtoupper(mb_substr($desc, 0, 2000)),
                'marca' => ! empty($p['marca']) ? mb_substr((string) $p['marca'], 0, 250) : null,
                'modelo' => ! empty($p['modelo']) ? mb_substr((string) $p['modelo'], 0, 250) : null,
                'unidad' => mb_substr(mb_strtoupper(trim((string) ($p['unidad'] ?? ''))) ?: 'PZA', 0, 10),
                'cantidad' => $cantidad,
                'proveedor_id' => $proveedor?->id,
                'costo' => $costo,
                'utilidad_pct' => $utilidad,
                'precio' => $precio,
                'importe' => Precios::importe($precio, $cantidad),
                'tiempo_entrega' => ! empty($p['tiempo_entrega']) ? mb_substr((string) $p['tiempo_entrega'], 0, 250) : null,
            ]);
        }
        $cot->recalcularTotales();
    }
}
