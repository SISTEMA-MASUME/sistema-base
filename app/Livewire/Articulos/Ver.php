<?php

namespace App\Livewire\Articulos;

use App\Models\Articulo;
use App\Models\ArticuloNombre;
use App\Models\ArticuloProveedor;
use App\Models\PedidoPartida;
use App\Models\Proveedor;
use App\Models\SatClave;
use App\Support\Articulos;
use App\Support\CatalogoImagenes;
use App\Support\Nombres;
use App\Support\UnidadesSat;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Ficha de un artículo: datos para facturar, quién lo vende (clave y último costo), otros nombres y ventas. */
#[Layout('layouts.app')]
#[Title('Artículo')]
class Ver extends Component
{
    #[Locked]
    public int $articuloId;

    public string $descripcion = '';

    public string $marca = '';

    public string $unidad = '';

    public string $unidadSat = '';

    public string $claveSat = '';

    public string $codigoBarras = '';

    // Proveedor nuevo o el que se está cambiando.
    public bool $editandoProveedor = false;

    #[Locked]
    public ?int $filaProveedorId = null;

    public string $proveedorTexto = '';

    public ?int $proveedorId = null;

    public string $clave = '';

    public string $costo = '';

    public string $link = '';

    public string $nombreNuevo = '';

    public string $unirTexto = '';

    public string $mensaje = '';

    public function mount(int $articulo): void
    {
        // Si se unió a otro, se abre el que se quedó.
        $a = Articulos::vigente(Articulo::withTrashed()->findOrFail($articulo));
        abort_unless($a, 404);
        $this->articuloId = $a->id;
        $this->cargar($a);
    }

    private function articulo(): Articulo
    {
        return Articulo::findOrFail($this->articuloId);
    }

    private function cargar(Articulo $a): void
    {
        $this->descripcion = (string) $a->descripcion;
        $this->marca = (string) $a->marca;
        $this->unidad = (string) $a->unidad;
        $this->unidadSat = (string) $a->unidad_sat;
        $this->claveSat = (string) $a->clave_sat;
        $this->codigoBarras = (string) $a->codigo_barras;
    }

    public function guardar(): void
    {
        $this->mensaje = '';
        $this->claveSat = preg_replace('/\D/', '', $this->claveSat);
        $this->codigoBarras = preg_replace('/\s+/', '', $this->codigoBarras);
        $hayCatalogoSat = SatClave::query()->exists();
        $this->validate([
            'descripcion' => ['required', 'string', 'max:2000'],
            'marca' => ['nullable', 'string', 'max:120'],
            'unidad' => ['required', 'string', 'max:20'],
            'unidadSat' => ['nullable', Rule::in(array_keys(UnidadesSat::LISTA))],
            'claveSat' => ['nullable', 'digits:8', $hayCatalogoSat ? Rule::exists('sat_claves', 'clave') : 'string'],
            'codigoBarras' => ['nullable', 'string', 'max:40'],
        ], [
            'descripcion.required' => 'Escribe la descripción.',
            'unidad.required' => 'Escribe la unidad (PZA, CAJA, METRO…).',
            'unidadSat.in' => 'Elige una unidad del SAT de la lista.',
            'claveSat.digits' => 'La clave del SAT son 8 números.',
            'claveSat.exists' => 'Esa clave no está en el catálogo del SAT.',
        ]);
        $a = $this->articulo();
        $a->update([
            'descripcion' => Nombres::limpiar($this->descripcion),
            'marca' => trim($this->marca) !== '' ? Nombres::limpiar($this->marca) : null,
            'unidad' => Nombres::limpiar($this->unidad),
            'unidad_sat' => $this->unidadSat ?: null,
            'clave_sat' => $this->claveSat ?: null,
            'codigo_barras' => $this->codigoBarras ?: null,
        ]);
        $this->cargar($a->fresh());
        $this->mensaje = 'Se guardó el artículo.';
    }

    /** Si la unidad cambia y todavía no hay unidad del SAT, se propone la que le toca. */
    public function updatedUnidad(): void
    {
        if ($this->unidadSat === '' && ($sugerida = UnidadesSat::sugerir($this->unidad))) {
            $this->unidadSat = $sugerida;
        }
    }

    // ------------------------------------------------------------------ proveedores

    public function nuevoProveedor(): void
    {
        $this->reset(['filaProveedorId', 'proveedorTexto', 'proveedorId', 'clave', 'costo', 'link']);
        $this->resetErrorBag();
        $this->editandoProveedor = true;
    }

    public function editarProveedor(int $filaId): void
    {
        $fila = $this->articulo()->proveedores()->with('proveedor:id,nombre')->findOrFail($filaId);
        $this->resetErrorBag();
        $this->filaProveedorId = $fila->id;
        $this->proveedorId = $fila->proveedor_id;
        $this->proveedorTexto = (string) $fila->proveedor?->nombre;
        $this->clave = (string) $fila->clave;
        $this->costo = $fila->ultimo_costo !== null ? number_format((float) $fila->ultimo_costo, 2, '.', '') : '';
        $this->link = (string) $fila->link_compra;
        $this->editandoProveedor = true;
    }

    public function elegirProveedor(int $id): void
    {
        $p = Proveedor::findOrFail($id);
        $this->proveedorId = $p->id;
        $this->proveedorTexto = $p->nombre;
    }

    public function updatedProveedorTexto(): void
    {
        $this->proveedorId = null;
    }

    public function cerrarProveedor(): void
    {
        $this->editandoProveedor = false;
    }

    public function guardarProveedor(): void
    {
        $this->mensaje = '';
        $this->costo = str_replace([',', '$', ' '], '', $this->costo);
        $this->validate([
            'proveedorId' => ['required', Rule::exists('proveedores', 'id')->whereNull('deleted_at')],
            'clave' => ['nullable', 'string', 'max:191'],
            'costo' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'link' => ['nullable', 'url', 'max:2000'],
        ], [
            'proveedorId.required' => 'Elige al proveedor de la lista (escribe parte de su nombre).',
            'costo.numeric' => 'El costo tiene que ser un número (sin IVA).',
            'link.url' => 'El link de compra no es válido.',
        ]);
        $a = $this->articulo();
        $repetido = ArticuloProveedor::where('articulo_id', $a->id)->where('proveedor_id', $this->proveedorId)
            ->when($this->filaProveedorId, fn ($q) => $q->whereKeyNot($this->filaProveedorId))->exists();
        if ($repetido) {
            $this->addError('proveedorId', 'Ese proveedor ya está en este artículo: cámbialo en su renglón.');

            return;
        }
        $fila = $this->filaProveedorId ? $a->proveedores()->findOrFail($this->filaProveedorId) : new ArticuloProveedor(['articulo_id' => $a->id]);
        $costo = $this->costo !== '' ? round((float) $this->costo, 4) : null;
        $cambioCosto = $costo !== null && (float) $fila->ultimo_costo !== $costo;
        $fila->fill([
            'proveedor_id' => $this->proveedorId,
            'clave' => trim($this->clave) ?: null,
            'link_compra' => trim($this->link) ?: null,
            'ultimo_costo' => $costo,
        ] + ($cambioCosto ? ['costo_fecha' => today()->toDateString(), 'pedido_id' => null, 'lista_precio_id' => null] : []))->save();
        $this->editandoProveedor = false;
        $this->mensaje = 'Se guardó el proveedor '.$this->proveedorTexto.'.';
    }

    public function quitarProveedor(int $filaId): void
    {
        $fila = $this->articulo()->proveedores()->with('proveedor:id,nombre')->findOrFail($filaId);
        $fila->delete();
        $this->mensaje = 'Se quitó '.($fila->proveedor?->nombre ?? 'el proveedor').' de este artículo.';
    }

    // ------------------------------------------------------------------ otros nombres

    public function agregarNombre(): void
    {
        $this->validate(['nombreNuevo' => ['required', 'string', 'min:4', 'max:2000']], [
            'nombreNuevo.required' => 'Escribe el otro nombre.', 'nombreNuevo.min' => 'Escribe al menos 4 letras.',
        ]);
        $a = $this->articulo();
        $llave = Articulos::llave($this->nombreNuevo);
        if ($llave === $a->descripcion_llave || $a->nombres()->where('llave', $llave)->exists()) {
            $this->addError('nombreNuevo', 'Ese nombre ya lo tiene.');

            return;
        }
        ArticuloNombre::create(['articulo_id' => $a->id, 'nombre' => Nombres::limpiar($this->nombreNuevo)]);
        $this->nombreNuevo = '';
    }

    public function quitarNombre(int $id): void
    {
        $this->articulo()->nombres()->whereKey($id)->delete();
    }

    // ------------------------------------------------------------------ unir repetidos

    public function unirCon(int $otroId): void
    {
        $a = $this->articulo();
        $otro = Articulo::whereKeyNot($a->id)->findOrFail($otroId);
        Articulos::unir($a, $otro);
        $this->unirTexto = '';
        $this->cargar($a->fresh());
        $this->mensaje = '"'.$otro->descripcion.'" se unió a este artículo: sus proveedores, pedidos y su nombre quedaron aquí.';
    }

    public function render()
    {
        $a = $this->articulo()->load(['proveedores.proveedor:id,nombre,telefono,contacto,correo', 'proveedores.proveedor.contactos', 'proveedores.pedido:id,folio', 'nombres']);
        $ventas = PedidoPartida::where('articulo_id', $a->id)->whereHas('pedido', fn ($q) => $q->whereNull('cancelado_at'))
            ->with(['pedido:id,folio,cliente_id,created_at', 'pedido.cliente:id,nombre'])->orderByDesc('id')->limit(10)->get();
        $primeraClave = $a->proveedores->first(fn ($p) => filled($p->clave))?->clave;
        $foto = CatalogoImagenes::buscarFila($primeraClave, $a->descripcion);

        return view('livewire.articulos.ver', [
            'a' => $a,
            'ventas' => $ventas,
            'totalPedidos' => PedidoPartida::where('articulo_id', $a->id)->whereHas('pedido', fn ($q) => $q->whereNull('cancelado_at'))->distinct()->count('pedido_id'),
            'piezas' => (float) PedidoPartida::where('articulo_id', $a->id)->whereHas('pedido', fn ($q) => $q->whereNull('cancelado_at'))->sum('cantidad'),
            'foto' => $foto?->imagen_path,
            'satDescripcion' => $a->clave_sat ? SatClave::find($a->clave_sat)?->descripcion : null,
            'proveedoresSugeridos' => $this->editandoProveedor && ! $this->proveedorId && mb_strlen(trim($this->proveedorTexto)) >= 2
                ? Proveedor::where('nombre', 'like', '%'.addcslashes(mb_strtoupper(trim($this->proveedorTexto)), '%_\\').'%')->orderBy('nombre')->limit(8)->get(['id', 'nombre'])
                : collect(),
            'paraUnir' => mb_strlen(trim($this->unirTexto)) >= 3
                ? Index::aplicarBusqueda(Articulo::whereKeyNot($a->id), $this->unirTexto)->orderBy('descripcion')->limit(8)->get(['id', 'descripcion', 'marca', 'unidad'])
                : collect(),
        ]);
    }
}
