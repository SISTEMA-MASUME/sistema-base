<?php

namespace App\Support\Mcp;

use App\Enums\ColumnaPedido;
use App\Enums\EstadoCotizacion;
use App\Enums\TipoPedido;
use App\Models\Cotizacion;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\PedidoPartida;
use App\Support\Folios;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/** Operaciones sobre el tablero interno. No envían documentos ni marcan material entregado. */
final class HerramientasPedidos
{
    public static function definiciones(): array
    {
        $columna = ['type' => 'string', 'enum' => array_column(ColumnaPedido::cases(), 'value'),
            'description' => 'Clave de la columna del tablero interno. Consulta columnas_pedidos.'];
        $escritura = ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];

        return [
            ['name' => 'columnas_pedidos', 'title' => 'Columnas del tablero de pedidos',
                'description' => 'Lista las claves y nombres de las columnas del tablero interno de MASUME.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
                'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false]],
            ['name' => 'convertir_cotizacion_a_pedido', 'title' => 'Convertir cotización a pedido sin OC',
                'description' => 'Crea un pedido SIN OC del cliente con todas las partidas incluidas de una cotización. '
                    .'Requiere el motivo explícito para surtir sin OC. Conserva cantidades, precios, costos y proveedores; '
                    .'marca la cotización aceptada y deja Esperando OC del cliente según sus reglas. '
                    .'Elige la columna con columnas_pedidos. No marca material entregado, no factura ni envía nada. '
                    .'Si todas las partidas ya están en un mismo pedido sin cambios, devuelve ese pedido sin duplicarlo ni moverlo; '
                    .'para cambiar su columna usa mover_pedido. No admite conversiones parciales.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'folio' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Folio de la cotización.'],
                    'motivo_sin_oc' => ['type' => 'string', 'minLength' => 10, 'maxLength' => 1000,
                        'description' => 'Motivo indicado por el usuario para entregar sin OC; por ejemplo, Entrega con cotización, OC pendiente del cliente.'],
                    'columna' => $columna,
                    'responsable_id' => ['type' => 'integer', 'description' => 'Usuario activo responsable; si se omite, quien autorizó el conector.'],
                    'notas' => ['type' => 'string', 'maxLength' => 3000, 'description' => 'Notas internas del pedido.'],
                    'fecha_compromiso' => ['type' => 'string', 'description' => 'Fecha AAAA-MM-DD, solo si el usuario la indicó.'],
                ], 'required' => ['folio', 'motivo_sin_oc']],
                'annotations' => $escritura],
            ['name' => 'mover_pedido', 'title' => 'Mover pedido en el tablero interno',
                'description' => 'Mueve un pedido a la columna indicada del tablero de MASUME y registra la actividad. '
                    .'No cambia estados de las partidas, facturación, OC ni notas. Repetir el mismo destino no duplica actividad.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'folio' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Folio del pedido sin el prefijo P-.'],
                    'columna' => $columna,
                ], 'required' => ['folio', 'columna']],
                'annotations' => $escritura],
        ];
    }

    public static function maneja(string $nombre): bool
    {
        return in_array($nombre, ['columnas_pedidos', 'convertir_cotizacion_a_pedido', 'mover_pedido'], true);
    }

    public static function ejecutar(string $nombre, array $a): array
    {
        if (! auth()->user()?->esAdmin() || ! auth()->user()->activo) {
            throw new InvalidArgumentException('Solo el administrador activo puede usar las herramientas de pedidos del conector.');
        }

        return match ($nombre) {
            'columnas_pedidos' => ['columnas' => array_map(fn ($c) => ['clave' => $c->value, 'nombre' => $c->etiqueta()], ColumnaPedido::cases())],
            'convertir_cotizacion_a_pedido' => self::convertir($a),
            'mover_pedido' => self::mover($a),
            default => throw new InvalidArgumentException("Herramienta desconocida: {$nombre}"),
        };
    }

    private static function convertir(array $a): array
    {
        self::soloCampos($a, ['folio', 'motivo_sin_oc', 'columna', 'responsable_id', 'notas', 'fecha_compromiso']);
        foreach (['motivo_sin_oc', 'notas'] as $campo) {
            if (isset($a[$campo]) && is_string($a[$campo])) {
                $a[$campo] = trim($a[$campo]);
            }
        }
        $a = Validator::make($a, [
            'folio' => ['required', 'integer', 'min:1'],
            'motivo_sin_oc' => ['required', 'string', 'min:10', 'max:1000'],
            'columna' => ['sometimes', 'required', Rule::enum(ColumnaPedido::class)],
            'responsable_id' => ['sometimes', 'required', 'integer', Rule::exists('users', 'id')->where('activo', true)->whereNull('eliminado_at')],
            'notas' => ['sometimes', 'string', 'max:3000'],
            'fecha_compromiso' => ['sometimes', 'required', 'date_format:Y-m-d'],
        ])->validate();

        [$pedido, $creado] = DB::transaction(function () use ($a) {
            // Serializa reintentos del conector antes de consultar la trazabilidad.
            $cot = Cotizacion::where('folio', $a['folio'])->lockForUpdate()->first()
                ?? throw new InvalidArgumentException('No existe la cotización '.$a['folio'].'.');
            $partidas = $cot->partidasIncluidas()->lockForUpdate()->get();
            if ($partidas->isEmpty()) {
                throw new InvalidArgumentException('La cotización no tiene partidas incluidas.');
            }
            if (! $cot->cliente) {
                throw new InvalidArgumentException('El cliente de la cotización ya no está disponible.');
            }
            $colocadas = PedidoPartida::whereHas('cotizacionPartida', fn ($q) => $q->where('cotizacion_id', $cot->id))
                ->lockForUpdate()->get();
            if ($colocadas->isNotEmpty()) {
                $ids = $colocadas->pluck('pedido_id')->unique();
                $originales = $partidas->keyBy('id');
                $mismas = $ids->count() === 1 && $colocadas->count() === $partidas->count()
                    && $colocadas->pluck('cotizacion_partida_id')->unique()->count() === $partidas->count()
                    && $colocadas->every(function ($p) use ($originales) {
                        $origen = $originales->get($p->cotizacion_partida_id);

                        return $origen && (string) $p->cantidad === (string) $origen->cantidad
                            && (string) $p->precio === (string) $origen->precio;
                    });
                if (! $mismas) {
                    throw new InvalidArgumentException('Esta cotización ya tiene partidas colocadas parcialmente, modificadas o en varios pedidos. Revísalos en el sistema; no se creó otro pedido.');
                }
                $pedido = Pedido::whereKey($ids->first())->lockForUpdate()->first()
                    ?? throw new InvalidArgumentException('El pedido asociado fue eliminado; revísalo en el sistema.');
                self::exigirActivo($pedido);
                if ($pedido->cliente_id !== $cot->cliente_id) {
                    throw new InvalidArgumentException('El pedido asociado pertenece a otro cliente; revísalo en el sistema.');
                }

                return [$pedido, false];
            }
            if (! in_array($cot->estado, [EstadoCotizacion::Borrador, EstadoCotizacion::Enviada], true)) {
                throw new InvalidArgumentException('Solo se convierten cotizaciones en borrador o enviadas; esta está '.$cot->estado->etiqueta().'.');
            }

            $columna = ColumnaPedido::from($a['columna'] ?? ColumnaPedido::OcRecibida->value);
            Pedido::where('columna', $columna->value)->increment('posicion');
            $pedido = Pedido::create([
                'folio' => Folios::siguiente('pedido'),
                'tipo' => TipoPedido::NotaVenta,
                'cliente_id' => $cot->cliente_id,
                'numero_oc_cliente' => null,
                'responsable_id' => $a['responsable_id'] ?? auth()->id(),
                'fecha_oc' => now()->toDateString(),
                'fecha_compromiso' => $a['fecha_compromiso'] ?? null,
                'columna' => $columna,
                'posicion' => 0,
                'notas' => trim('Sin OC del cliente: '.$a['motivo_sin_oc']."\n"
                    .'Entrega con cotización '.$cot->folioConRevision().".\n".($a['notas'] ?? '')),
            ]);
            foreach ($partidas as $i => $partida) {
                if ((float) $partida->cantidad <= 0) {
                    throw new InvalidArgumentException('Todas las cantidades deben ser mayores a 0.');
                }
                PedidoPartida::desdeCotizacion($partida, $pedido, $i + 1);
            }
            $pedido->recalcularTotales();
            $cot->update(['estado' => EstadoCotizacion::Aceptada]);
            PedidoActividad::registrar($pedido, 'crear', 'creó la tarjeta desde la cotización '.$cot->folio.' por el conector en "'.$columna->etiqueta().'"');
            PedidoActividad::registrar($pedido, 'sin_oc', 'creó el pedido sin OC del cliente: '.$a['motivo_sin_oc']);

            return [$pedido, true];
        });

        return ['creado' => $creado, 'cotizacion_folio' => (int) $a['folio'],
            'mensaje' => $creado ? 'Pedido creado en el tablero interno, sin OC. No se envió nada ni se marcó material entregado.'
                : 'Las partidas ya están en este pedido. No se duplicó ni se cambió su columna; usa mover_pedido para moverlo.',
        ] + Herramientas::ejecutar('ver_pedido', ['folio' => $pedido->folio]);
    }

    private static function mover(array $a): array
    {
        self::soloCampos($a, ['folio', 'columna']);
        $a = Validator::make($a, [
            'folio' => ['required', 'integer', 'min:1'],
            'columna' => ['required', Rule::enum(ColumnaPedido::class)],
        ])->validate();

        [$pedido, $movido] = DB::transaction(function () use ($a) {
            $pedido = Pedido::where('folio', $a['folio'])->lockForUpdate()->first()
                ?? throw new InvalidArgumentException('No existe el pedido P-'.$a['folio'].'.');
            self::exigirActivo($pedido);
            $columna = ColumnaPedido::from($a['columna']);
            if ($pedido->columna === $columna) {
                return [$pedido, false];
            }
            Pedido::where('columna', $columna->value)->increment('posicion');
            $pedido->update(['columna' => $columna, 'posicion' => 0]);
            PedidoActividad::registrar($pedido, 'mover', 'movió la tarjeta por el conector a "'.$columna->etiqueta().'"');

            return [$pedido, true];
        });

        return ['movido' => $movido] + Herramientas::ejecutar('ver_pedido', ['folio' => $pedido->folio]);
    }

    private static function exigirActivo(Pedido $pedido): void
    {
        if ($pedido->archivado_at || $pedido->cancelado_at) {
            throw new InvalidArgumentException('El pedido está archivado o cancelado; revísalo en el sistema.');
        }
    }

    private static function soloCampos(array $a, array $campos): void
    {
        if (array_diff(array_keys($a), $campos) !== []) {
            throw new InvalidArgumentException('Hay campos no admitidos. Este flujo no recibe OC, precios ni estados de entrega o facturación.');
        }
    }
}
