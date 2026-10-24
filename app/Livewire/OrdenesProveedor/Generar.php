<?php

namespace App\Livewire\OrdenesProveedor;

use App\Enums\EstadoOrdenProveedor;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\PedidoConciliacion;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Services\ConciliadorOc;
use App\Support\CuentasPorPagar;
use App\Support\Folios;
use App\Support\Nombres;
use App\Support\Precios;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Generar órdenes de compra a proveedores')]
class Generar extends Component
{
    public Pedido $pedido;

    /** @var array<string, array{incluir: bool, metodo_pago: string, comentarios: string}> por grupo ("p{id}" o "sin") */
    public array $config = [];

    /** @var array<int, bool> partida_id => incluida */
    public array $seleccion = [];

    public string $mensaje = '';

    public string $error = '';

    /** Motivo escrito para generar sin conciliar o con diferencias sin resolver contra la OC del cliente. */
    public string $motivoSinConciliar = '';

    public function mount(Pedido $pedido): void
    {
        $this->pedido = $pedido;
        $this->sincronizar();
    }

    private function partidasPendientes(): Collection
    {
        return $this->pedido->partidas()->whereNull('orden_proveedor_id')->with('proveedor:id,nombre,vende_a_credito')->get();
    }

    private static function clave(?int $proveedorId): string
    {
        return $proveedorId ? 'p'.$proveedorId : 'sin';
    }

    /** Da valores por omisión a partidas y grupos nuevos (p. ej. tras cambiar de proveedor). */
    private function sincronizar(): void
    {
        foreach ($this->partidasPendientes() as $p) {
            $this->seleccion[$p->id] ??= true;
            // Sin método por omisión: se elige a propósito para que no se pase anotar lo que es a crédito.
            // Los proveedores que nos venden a crédito ya traen CRÉDITO marcado.
            $this->config[self::clave($p->proveedor_id)] ??= [
                'incluir' => true,
                'metodo_pago' => $p->proveedor?->vende_a_credito ? EstadoOrdenProveedor::CREDITO : '',
                'comentarios' => '',
            ];
        }
    }

    public function asignarProveedor(int $partidaId, ?int $proveedorId): void
    {
        $this->error = '';
        $partida = $this->pedido->partidas()->whereNull('orden_proveedor_id')->find($partidaId);
        if (! $partida) {
            return;
        }
        if ($proveedorId && ! Proveedor::whereKey($proveedorId)->exists()) {
            $this->error = 'Ese proveedor no existe.';

            return;
        }
        $partida->update(['proveedor_id' => $proveedorId ?: null]);
        $this->sincronizar();
    }

    /** Autocompletado: el usuario escribe/elige el nombre del proveedor del catálogo. */
    public function asignarProveedorNombre(int $partidaId, string $nombre): void
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            return;
        }
        $proveedor = Proveedor::where('nombre_llave', Nombres::llave($nombre))->first();
        if (! $proveedor) {
            $this->error = 'No hay un proveedor llamado "'.$nombre.'". Elígelo de la lista o dalo de alta en Proveedores.';

            return;
        }
        $this->asignarProveedor($partidaId, $proveedor->id);
    }

    /**
     * Candado de la conciliación con la OC del cliente. Se recalcula contra las partidas de ahora
     * (pudieron cambiar después de la última revisión).
     *
     * @return array{nivel: string, bloquea: bool, texto: string}
     */
    private function candado(): array
    {
        if (! $this->pedido->oc_cliente_path) {
            return ['nivel' => 'aviso', 'bloquea' => false, 'texto' => 'Este pedido no tiene archivo de OC del cliente, así que no se pudo conciliar. Revisa a mano que cantidades y modelos coincidan con lo que pidió el cliente.'];
        }

        $c = PedidoConciliacion::where('pedido_id', $this->pedido->id)->where('estado', 'completada')->latest('id')->first();
        if (! $c) {
            return ['nivel' => 'bloqueo', 'bloquea' => true, 'texto' => 'Este pedido tiene la OC del cliente pero todavía no se ha revisado contra ella. Ve al pedido y pulsa "Revisar contra la OC", o escribe el motivo para generar sin revisar.'];
        }

        $r = app(ConciliadorOc::class)->conciliar(
            (array) $c->extraccion,
            $this->pedido->partidas()->get(['id', 'descripcion', 'modelo', 'cantidad', 'unidad', 'precio']),
            $this->pedido->numero_oc_cliente,
            (array) $c->resoluciones
        );
        if ($r['hay_diferencias']) {
            return ['nivel' => 'bloqueo', 'bloquea' => true, 'texto' => 'El pedido tiene diferencias sin resolver contra la OC del cliente ('.$r['resumen'].'). Corrígelas en el pedido o escribe el motivo para generar de todos modos.'];
        }

        return ['nivel' => 'ok', 'bloquea' => false, 'texto' => 'El pedido coincide con la OC del cliente.'];
    }

    /** @return array<string, Collection> grupo => partidas seleccionadas */
    private function gruposSeleccionados(Collection $partidas): array
    {
        $grupos = [];
        foreach ($partidas->groupBy(fn ($p) => self::clave($p->proveedor_id)) as $clave => $items) {
            $elegidas = $items->filter(fn ($p) => ! empty($this->seleccion[$p->id]));
            if ($elegidas->isNotEmpty() && ($this->config[$clave]['incluir'] ?? true)) {
                $grupos[$clave] = $elegidas;
            }
        }

        return $grupos;
    }

    public function generar()
    {
        $this->error = '';
        $this->resetErrorBag();
        $partidas = $this->partidasPendientes();
        $grupos = $this->gruposSeleccionados($partidas);

        if ($grupos === []) {
            $this->error = 'No hay partidas seleccionadas para generar una orden.';

            return null;
        }

        $malos = false;
        foreach ($grupos as $clave => $items) {
            if ($clave === 'sin') {
                $this->addError('grupo.sin', 'Elige el proveedor de estas partidas (o quítalas de la orden).');
                $malos = true;
            }
            if (! in_array($this->config[$clave]['metodo_pago'] ?? '', EstadoOrdenProveedor::METODOS_PAGO, true)) {
                $this->addError("grupo.$clave", 'Elige cómo se le paga a este proveedor (crédito, tarjeta, transferencia…).');
                $malos = true;
            }
            if (mb_strlen((string) ($this->config[$clave]['comentarios'] ?? '')) > 2000) {
                $this->addError("grupo.$clave", 'Los comentarios son muy largos.');
                $malos = true;
            }
        }
        if ($malos) {
            return null;
        }

        $candado = $this->candado();
        $motivo = trim($this->motivoSinConciliar);
        if ($candado['bloquea'] && mb_strlen($motivo) < 5) {
            $this->addError('motivoSinConciliar', 'Escribe el motivo (mínimo 5 letras) para generar sin conciliar con la OC del cliente.');

            return null;
        }
        if (mb_strlen($motivo) > 1000) {
            $this->addError('motivoSinConciliar', 'El motivo es muy largo.');

            return null;
        }

        try {
            $creadas = $this->crearOrdenes($grupos, $candado, $motivo);
        } catch (\DomainException $e) {
            $this->error = $e->getMessage();

            return null;
        }

        if ($creadas === []) {
            $this->error = 'Esas partidas ya tienen orden de compra.';

            return null;
        }

        $n = count($creadas);
        session()->flash('exito', $n === 1 ? 'Se generó la orden '.$creadas[0]->folioTexto().'.' : "Se generaron $n órdenes de compra.");

        return $n === 1
            ? $this->redirect(route('ordenes-proveedor.ver', $creadas[0]), navigate: false)
            : $this->redirect(route('ordenes-proveedor.index', ['pedido' => $this->pedido->id]), navigate: false);
    }

    /** Crea las OC dentro de una transacción con el pedido y sus partidas bloqueados. */
    private function crearOrdenes($grupos, array $candado, string $motivo): array
    {
        return DB::transaction(function () use ($grupos, $candado, $motivo) {
            // Se bloquea el pedido: dos personas generando OC del mismo pedido al mismo tiempo van una tras otra.
            Pedido::whereKey($this->pedido->id)->lockForUpdate()->first();
            $ordenes = [];
            foreach ($grupos as $clave => $items) {
                // Solo partidas que siguen libres (otra persona pudo generar la OC mientras tanto).
                $libres = PedidoPartida::whereIn('id', $items->pluck('id'))->whereNull('orden_proveedor_id')->orderBy('id')->lockForUpdate()->get();
                if ($libres->isEmpty()) {
                    continue;
                }
                $orden = OrdenProveedor::create([
                    'folio' => Folios::siguiente('oc_proveedor'),
                    'pedido_id' => $this->pedido->id,
                    'proveedor_id' => (int) substr($clave, 1),
                    'user_id' => auth()->id(),
                    'metodo_pago' => $this->config[$clave]['metodo_pago'],
                    'comentarios' => trim((string) $this->config[$clave]['comentarios']) ?: null,
                    'estado' => EstadoOrdenProveedor::Emitida,
                    'total_costo' => round($libres->sum(fn ($p) => (float) $p->costo * (float) $p->cantidad), 2),
                    'motivo_sin_conciliar' => $candado['bloquea'] ? $motivo : null,
                    'autorizo_sin_conciliar_id' => $candado['bloquea'] ? auth()->id() : null,
                ]);
                $ids = $libres->pluck('id')->all();
                // Solo se reclaman las que siguen libres; si otra OC se adelantó, esta se deshace.
                if (PedidoPartida::whereIn('id', $ids)->whereNull('orden_proveedor_id')->update(['orden_proveedor_id' => $orden->id]) !== count($ids)) {
                    throw new \DomainException('Otra persona generó la OC de estas partidas al mismo tiempo. Recarga la página.');
                }
                PedidoPartida::whereIn('id', $ids)->where('estado', 'pendiente')->update(['estado' => 'solicitada']);
                // A crédito: se anota sola en "A quién le debo".
                CuentasPorPagar::sincronizarOrden($orden);
                $ordenes[] = $orden;
            }

            return $ordenes;
        });
    }

    public function render()
    {
        $partidas = $this->partidasPendientes();
        $iva = Precios::iva();

        $grupos = $partidas->groupBy(fn ($p) => self::clave($p->proveedor_id))
            ->map(function ($items, $clave) {
                $elegidas = $items->filter(fn ($p) => ! empty($this->seleccion[$p->id]));

                return [
                    'clave' => $clave,
                    'nombre' => $clave === 'sin' ? 'Sin proveedor' : $items->first()->proveedor?->nombre,
                    'partidas' => $items,
                    'total' => $elegidas->sum(fn ($p) => (float) $p->costo * (float) $p->cantidad),
                ];
            })
            ->sortBy(fn ($g) => $g['clave'] === 'sin' ? '' : $g['nombre'])
            ->values();

        return view('livewire.ordenes-proveedor.generar', [
            'grupos' => $grupos,
            'iva' => $iva,
            'nOrdenes' => count($this->gruposSeleccionados($partidas)),
            'metodos' => EstadoOrdenProveedor::METODOS_PAGO,
            'proveedores' => Proveedor::orderBy('nombre')->get(['id', 'nombre']),
            'candado' => $this->candado(),
            'yaTienenOrden' => $this->pedido->partidas()->whereNotNull('orden_proveedor_id')->count(),
        ]);
    }
}
