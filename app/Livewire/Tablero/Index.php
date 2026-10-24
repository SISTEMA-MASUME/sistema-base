<?php

namespace App\Livewire\Tablero;

use App\Enums\ColumnaPedido;
use App\Models\Pedido;
use App\Models\PedidoActividad;
use App\Models\PedidoArchivo;
use App\Models\User;
use App\Support\PorRecoger;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Pedidos')]
class Index extends Component
{
    public string $busqueda = '';

    public string $responsableId = '';

    public bool $soloMios = false;

    public bool $mostrarCancelados = true;

    public bool $verTodasEntregadas = false;

    /** Lista de pedidos archivados (el tablero los oculta por defecto). */
    public bool $verArchivados = false;

    /** Filtro por texto de etiqueta (automática o bandera), como el filtro de Trello. */
    public string $etiqueta = '';

    /**
     * Tarjeta abierta en la ventana flotante. El enlace /pedidos?pedido=ID la abre (para compartirla); el historial del
     * navegador lo maneja la vista (abrir = una entrada nueva; "atrás" o la ✕ la cierran y regresan al tablero). Antes
     * Livewire agregaba una entrada también al cerrar y el botón "atrás" del celular volvía a abrir la tarjeta.
     */
    public ?int $abierto = null;

    public function mount(): void
    {
        $id = request()->query('pedido');
        $this->abierto = is_scalar($id) && ctype_digit((string) $id) ? (int) $id : null;
    }

    public function abrir(int $pedidoId): void
    {
        $this->abierto = $pedidoId;
    }

    #[On('cerrar-tarjeta')]
    public function cerrar(): void
    {
        $this->abierto = null;
    }

    /** La tarjeta abierta cambió algo (estado, comentario, archivo…): se vuelve a pintar el tablero. */
    #[On('pedido-actualizado')]
    public function refrescar(): void
    {
        // Solo vuelve a renderizar.
    }

    public function desarchivar(int $pedidoId): void
    {
        abort_if(auth()->user()->esCampo(), 403);
        Pedido::whereNotNull('archivado_at')->whereKey($pedidoId)->update(['archivado_at' => null, 'archivado_por' => null]);
    }

    /**
     * Mueve una tarjeta a una columna y guarda el orden de esa columna.
     * Con $nuevoOrdenIds vacío (celular) la tarjeta queda al tope de la columna destino.
     *
     * @param  array<int, int|string>  $nuevoOrdenIds
     */
    public function mover(int $pedidoId, string $columna, array $nuevoOrdenIds = []): void
    {
        $destino = ColumnaPedido::tryFrom($columna);
        $pedido = Pedido::find($pedidoId);
        if (! $destino || ! $pedido) {
            return;
        }

        DB::transaction(function () use ($pedido, $destino, $nuevoOrdenIds) {
            $ids = array_values(array_unique(array_map('intval', $nuevoOrdenIds)));

            if ($ids === []) {
                $ids = Pedido::where('columna', $destino->value)->where('id', '!=', $pedido->id)
                    ->orderBy('posicion')->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
                array_unshift($ids, $pedido->id);
            } elseif (! in_array($pedido->id, $ids, true)) {
                array_unshift($ids, $pedido->id);
            }

            if ($pedido->columna !== $destino) {
                $pedido->columna = $destino;
                $pedido->save();
                PedidoActividad::registrar($pedido, 'mover', 'movió la tarjeta a "'.$destino->etiqueta().'"');
            }

            // Solo cuentan los pedidos que realmente están en la columna destino.
            $validos = Pedido::where('columna', $destino->value)->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all();
            $ids = array_values(array_filter($ids, fn ($i) => in_array($i, $validos, true)));
            if ($ids === []) {
                return;
            }

            $casos = '';
            $bind = [];
            foreach ($ids as $pos => $id) {
                $casos .= ' WHEN ? THEN ?';
                array_push($bind, $id, $pos);
            }
            DB::update(
                'UPDATE pedidos SET posicion = CASE id'.$casos.' END WHERE id IN ('.implode(',', array_fill(0, count($ids), '?')).')',
                [...$bind, ...$ids]
            );
        });
    }

    /** Un solo query (con eager loading) para todo el tablero, agrupado por columna. */
    private function pedidosPorColumna()
    {
        $q = Pedido::query()
            ->select('id', 'folio', 'tipo', 'cliente_id', 'numero_oc_cliente', 'responsable_id', 'fecha_compromiso',
                'columna', 'posicion', 'alta_sicar', 'revisar', 'modificar_oc', 'cancelado_at', 'archivado_at', 'oc_cliente_path', 'factura_portal_at', 'total_venta', 'total_costo', 'updated_at', 'titulo_personalizado',
                'sucursal_id', 'evidencia_enviada_at', 'factura_sucursal_enviada_at')
            // Insignia ≡ (tiene notas) sin traer el texto completo de las notas.
            ->selectRaw("CASE WHEN notas IS NULL OR notas = '' THEN 0 ELSE 1 END AS tiene_notas")
            // Portada de la tarjeta: primera foto de evidencia.
            ->addSelect(['portada_id' => PedidoArchivo::select('id')->whereColumn('pedido_id', 'pedidos.id')->where('tipo', 'evidencia')->orderBy('id')->limit(1)])
            ->with([
                Pedido::CLIENTE_REGLAS,
                'responsable:id,name,iniciales',
                'sucursal:id,nombre,ciudad,estado',
                'partidas:id,pedido_id,orden,descripcion,modelo,proveedor_id,unidad,cantidad,estado,cantidad_recogida,facturada,fecha_entrega,sicar_alta_at,sicar_alta_firma',
                'ultimaConciliacion',
            ])
            // Conteos por tipo en la misma consulta (sin N+1) para los íconos de la tarjeta.
            ->withCount([
                'archivos as evidencias_count' => fn ($a) => $a->where('tipo', 'evidencia'),
                'archivos as facturas_count' => fn ($a) => $a->whereIn('tipo', ['factura_pdf', 'factura_xml']),
                'archivos',
                'comentarios',
            ])
            ->orderBy('posicion')->orderBy('id');

        if ($this->responsableId !== '') {
            $q->where('responsable_id', (int) $this->responsableId);
        }
        if ($this->soloMios) {
            $q->where('responsable_id', auth()->id());
        }
        if (! $this->mostrarCancelados) {
            $q->whereNull('cancelado_at');
        }
        $this->verArchivados ? $q->whereNotNull('archivado_at') : $q->whereNull('archivado_at');
        if (! $this->verTodasEntregadas && ! $this->verArchivados) {
            $limite = now()->subDays(30);
            $q->where(fn ($w) => $w->where('columna', '!=', ColumnaPedido::EntregadasCompletas->value)->orWhere('updated_at', '>=', $limite));
        }

        $b = trim($this->busqueda);
        if ($b !== '') {
            $like = '%'.addcslashes($b, '%_\\').'%';
            $folio = preg_match('/^\s*P?-?\s*(\d+)\s*$/i', $b, $m) ? (int) $m[1] : null;
            $q->where(function ($w) use ($like, $folio) {
                $w->where('numero_oc_cliente', 'like', $like)
                    ->orWhereHas('cliente', fn ($c) => $c->where('nombre', 'like', $like))
                    ->orWhereHas('partidas', fn ($p) => $p->where('descripcion', 'like', $like));
                if ($folio !== null) {
                    $w->orWhere('folio', $folio);
                }
            });
        }

        $pedidos = $q->get();
        // Las etiquetas se calculan una sola vez por tarjeta (sirven para pintarlas y para filtrar).
        $pedidos->each(fn (Pedido $p) => $p->setAttribute('lista_etiquetas', $p->etiquetas()));
        $this->etiquetasDisponibles = $pedidos->flatMap(fn ($p) => array_column($p->lista_etiquetas, 'texto'))->unique()->sort()->values()->all();
        if ($this->etiqueta !== '') {
            $pedidos = $pedidos->filter(fn ($p) => in_array($this->etiqueta, array_column($p->lista_etiquetas, 'texto'), true))->values();
        }

        return $pedidos->groupBy(fn (Pedido $p) => $p->columna->value);
    }

    /** @var list<string> */
    private array $etiquetasDisponibles = [];

    public function render()
    {
        $porColumna = $this->pedidosPorColumna();
        $archivados = $this->verArchivados
            ? $porColumna->flatten(1)->sortByDesc('archivado_at')->values()
            : collect();
        $verVenta = (bool) auth()->user()?->puedeVerVenta();
        $parcialesRecoger = PorRecoger::parciales();

        $columnas = collect(ColumnaPedido::cases())->map(function (ColumnaPedido $c) use ($porColumna, $verVenta) {
            $pedidos = $porColumna->get($c->value, collect());

            return [
                'valor' => $c->value,
                'titulo' => $c->etiqueta(),
                'pedidos' => $pedidos,
                'total' => $pedidos->sum(fn ($p) => (float) ($verVenta ? $p->total_venta : $p->total_costo)),
            ];
        });

        $pedidoAbierto = $this->abierto ? Pedido::find($this->abierto) : null;

        return view('livewire.tablero.index', [
            'pedidoAbierto' => $pedidoAbierto,
            'etiquetasDisponibles' => $this->etiquetasDisponibles,
            'columnas' => $columnas,
            'archivados' => $archivados,
            'puedeArchivar' => ! auth()->user()?->esCampo(),
            'verVenta' => $verVenta,
            'parcialesRecoger' => $parcialesRecoger,
            'responsables' => User::where('activo', true)->orderBy('name')->get(['id', 'name']),
            'filtrosActivos' => ($this->etiqueta !== '' ? 1 : 0) + ($this->responsableId !== '' ? 1 : 0) + ($this->soloMios ? 1 : 0)
                + ($this->verArchivados ? 1 : 0) + ($this->verTodasEntregadas ? 1 : 0) + ($this->mostrarCancelados ? 0 : 1),
        ]);
    }
}
