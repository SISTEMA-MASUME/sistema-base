<?php

namespace App\Livewire\Articulos;

use App\Models\Articulo;
use App\Models\PedidoPartida;
use App\Support\Articulos;
use App\Support\CatalogoImagenes;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Catálogo de artículos: cada artículo una vez, con quién lo vende y lo que le falta para facturar. */
#[Layout('layouts.app')]
#[Title('Artículos')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $buscar = '';

    /** '' todos | fiscal (le falta dato para facturar) | pedido (nacieron en pedidos) | sicar | semana (nuevos) */
    #[Url]
    public string $filtro = '';

    public const FILTROS = ['' => 'Todos', 'fiscal' => 'Falta dato fiscal', 'pedido' => 'Nacieron en pedidos', 'sicar' => 'De SICAR', 'semana' => 'Nuevos esta semana'];

    public function updated(string $propiedad): void
    {
        $this->resetPage();
    }

    public function filtrar(string $filtro): void
    {
        $this->filtro = array_key_exists($filtro, self::FILTROS) ? $filtro : '';
        $this->resetPage();
    }

    /** Cada palabra debe estar en la descripción, la marca, otro nombre o una clave; la clave del proveedor también exacta. */
    public static function aplicarBusqueda(Builder $q, string $texto): Builder
    {
        $texto = trim($texto);
        if ($texto === '') {
            return $q;
        }
        $clave = CatalogoImagenes::llaveModelo($texto);
        $palabras = Articulos::palabras($texto);

        return $q->where(function (Builder $w) use ($palabras, $clave, $texto) {
            $w->where(function (Builder $todas) use ($palabras) {
                foreach ($palabras as $p) {
                    $like = '%'.addcslashes($p, '%_\\').'%';
                    $todas->where(fn (Builder $una) => $una->where('busqueda', 'like', $like)
                        ->orWhereHas('nombres', fn ($n) => $n->where('nombre', 'like', $like)));
                }
            })
                ->orWhere('codigo_barras', trim($texto))
                ->orWhere('sicar_clave', trim($texto))
                ->when($clave, fn (Builder $c) => $c->orWhereHas('proveedores', fn ($p) => $p->where('clave_llave', $clave)));
        });
    }

    private function consulta(): Builder
    {
        $q = Articulo::query();
        match ($this->filtro) {
            'fiscal' => $q->where(fn ($w) => $w->whereNull('clave_sat')->orWhere('clave_sat', '')->orWhereNull('unidad_sat')->orWhere('unidad_sat', '')),
            'pedido' => $q->where('origen', 'pedido'),
            'sicar' => $q->where('origen', 'sicar'),
            'semana' => $q->where('created_at', '>=', now()->subDays(7)),
            default => null,
        };

        return self::aplicarBusqueda($q, $this->buscar);
    }

    public function render()
    {
        $articulos = $this->consulta()
            ->with(['proveedores' => fn ($p) => $p->select(['id', 'articulo_id', 'proveedor_id', 'ultimo_costo', 'costo_fecha']), 'proveedores.proveedor:id,nombre'])
            ->orderByRaw('ultimo_pedido_at IS NULL')->orderByDesc('ultimo_pedido_at')->orderBy('descripcion')
            ->paginate(30);
        // Veces vendido solo de los 30 de la página (contar sobre todo el catálogo lo hacía lento).
        $vendidos = PedidoPartida::whereIn('articulo_id', $articulos->pluck('id'))->groupBy('articulo_id')
            ->selectRaw('articulo_id, count(distinct pedido_id) as n')->pluck('n', 'articulo_id');

        return view('livewire.articulos.index', [
            'articulos' => $articulos,
            'vendidos' => $vendidos,
            'total' => Articulo::count(),
            'faltaFiscal' => Articulo::where(fn ($w) => $w->whereNull('clave_sat')->orWhere('clave_sat', '')->orWhereNull('unidad_sat')->orWhere('unidad_sat', ''))->count(),
            'nuevos' => Articulo::where('created_at', '>=', now()->subDays(7))->where('origen', '!=', 'sicar')->count(),
            'deSicar' => Articulo::whereNotNull('sicar_art_id')->count(),
        ]);
    }
}
