<?php

namespace App\Livewire\Articulos;

use App\Models\ListaPrecio;
use App\Models\ListaPrecioRenglon;
use App\Models\Proveedor;
use App\Support\ListasPrecios;
use App\Support\Nombres;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Listas de precios de las marcas (Truper, Urrea…): se sube el Excel tal como lo manda la marca, se revisa una muestra
 * con el costo ya calculado y se importa (reemplaza la del mes anterior). También se busca en ellas.
 */
#[Layout('layouts.app')]
#[Title('Listas de precios')]
class Listas extends Component
{
    use WithFileUploads;

    public $archivo = null;

    /** Lo que se leyó del Excel (solo para mostrar la muestra; al importar se vuelve a leer). */
    public ?array $analisis = null;

    public string $proveedorTexto = '';

    public ?int $proveedorId = null;

    public string $nombre = '';

    public ?int $precio = null;

    public bool $conIva = false;

    public string $descuento = '0';

    public string $buscar = '';

    // "Cambiar descuentos" de una lista ya cargada (nivel del trimestre).
    #[Locked]
    public ?int $editandoLista = null;

    public string $editDescuentos = '';

    public bool $editConIva = false;

    private const AVISO_DESCUENTO = 'Escribe el descuento en números; si son varios, sepáralos con + (por ejemplo 20 + 5). Cada uno de 0 a 90.';

    public string $mensaje = '';

    private function soloAdmin(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
    }

    public function updatedArchivo(): void
    {
        $this->soloAdmin();
        $this->mensaje = '';
        $this->analisis = null;
        $this->validate(['archivo' => ['required', 'file', 'max:20480']], ['archivo.max' => 'El archivo pesa más de 20 MB.']);
        if (strtolower($this->archivo->getClientOriginalExtension()) !== 'xlsx') {
            $this->addError('archivo', 'Sube la lista en Excel (.xlsx). Si viene en .xls, ábrela y guárdala como .xlsx.');

            return;
        }
        try {
            $a = ListasPrecios::analizar($this->archivo->getRealPath());
        } catch (InvalidArgumentException $e) {
            $this->addError('archivo', $e->getMessage());

            return;
        }
        // La muestra se guarda sin sus celdas completas (solo el precio de cada columna de precio).
        $a['muestra'] = array_map(fn ($m) => array_diff_key($m, ['_celdas' => 1])
            + ['_precios' => array_map(fn ($i) => ListasPrecios::numero($m['_celdas'][$i] ?? null), array_combine(array_keys($a['precios']), array_keys($a['precios'])))], $a['muestra']);
        $this->analisis = $a;
        $this->precio = $a['sugerido']['precio'];
        $this->conIva = $a['sugerido']['con_iva'];
        $marca = $a['sugerido']['marca'] ?? '';
        $proveedor = $marca !== '' ? Proveedor::porNombre($marca) : null;
        $this->proveedorId = $proveedor?->id;
        $this->proveedorTexto = $proveedor?->nombre ?? $marca;
        $anterior = $proveedor ? ListaPrecio::where('proveedor_id', $proveedor->id)->first() : null;
        $this->descuento = $anterior?->descuentos ?? '0';
        $this->nombre = trim(($proveedor?->nombre ?? $marca).' '.ListasPrecios::mes(now()));
    }

    public function updatedProveedorTexto(): void
    {
        $this->proveedorId = null;
    }

    public function elegirProveedor(int $id): void
    {
        $p = Proveedor::findOrFail($id);
        $this->proveedorId = $p->id;
        $this->proveedorTexto = $p->nombre;
    }

    /** La marca todavía no es proveedor (p. ej. nunca se le ha comprado directo a Truper): se da de alta. */
    public function crearProveedor(): void
    {
        $this->soloAdmin();
        $nombre = Nombres::limpiar($this->proveedorTexto);
        if ($nombre === '') {
            $this->addError('proveedorId', 'Escribe el nombre del proveedor.');

            return;
        }
        $p = Proveedor::porNombre($nombre) ?? Proveedor::create(['nombre' => $nombre]);
        $this->elegirProveedor($p->id);
    }

    public function cancelar(): void
    {
        $this->reset(['archivo', 'analisis', 'proveedorTexto', 'proveedorId', 'nombre', 'precio', 'conIva', 'descuento']);
        $this->resetErrorBag();
    }

    public function importar(): void
    {
        $this->soloAdmin();
        $this->mensaje = '';
        if (! $this->analisis || ! $this->archivo instanceof TemporaryUploadedFile) {
            $this->addError('archivo', 'Vuelve a subir el archivo.');

            return;
        }
        $this->validate([
            'proveedorId' => ['required', Rule::exists('proveedores', 'id')->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:120'],
            'precio' => ['required', 'integer', Rule::in(array_keys($this->analisis['precios']))],
            'descuento' => ['required', fn ($a, $v, $falla) => ListasPrecios::descuentos((string) $v) === null ? $falla(self::AVISO_DESCUENTO) : null],
        ], [
            'proveedorId.required' => 'Elige de qué proveedor es la lista (o créalo).',
            'precio.in' => 'Elige la columna del precio que es tu costo.',
        ]);
        @set_time_limit(300);
        try {
            $r = ListasPrecios::importar($this->archivo->getRealPath(), [
                'proveedor_id' => $this->proveedorId, 'nombre' => $this->nombre, 'precio' => $this->precio,
                'con_iva' => $this->conIva, 'descuento' => $this->descuento, 'archivo' => $this->archivo->getClientOriginalName(),
            ]);
        } catch (InvalidArgumentException $e) {
            $this->addError('archivo', $e->getMessage());

            return;
        }
        $this->cancelar();
        $this->mensaje = 'Lista importada: '.number_format($r['renglones']).' productos. '.number_format($r['ligados']).' ya estaban en el catálogo y se ligaron'
            .($r['completados'] ? ' ('.number_format($r['completados']).' recibieron su código de barras o clave del SAT)' : '').'.';
    }

    public function editarLista(int $id): void
    {
        $this->soloAdmin();
        $l = ListaPrecio::findOrFail($id);
        $this->editandoLista = $l->id;
        $this->editDescuentos = $l->descuentos;
        $this->editConIva = $l->precio_con_iva;
        $this->resetErrorBag();
    }

    public function cerrarLista(): void
    {
        $this->editandoLista = null;
    }

    public function guardarDescuentos(): void
    {
        $this->soloAdmin();
        $this->validate(['editDescuentos' => ['required', fn ($a, $v, $falla) => ListasPrecios::descuentos((string) $v) === null ? $falla(self::AVISO_DESCUENTO) : null]],
            ['editDescuentos.required' => 'Escribe el descuento (0 si no hay).']);
        $l = ListasPrecios::recalcular(ListaPrecio::findOrFail($this->editandoLista), $this->editDescuentos, $this->editConIva);
        $this->editandoLista = null;
        $this->mensaje = 'Listo: los '.number_format($l->renglones).' productos de '.$l->nombre.' ya tienen su costo con '.$l->descuentos.'% ('
            .rtrim(rtrim(number_format((float) $l->descuento_pct, 2), '0'), '.').'% en total).';
    }

    /** "Agregar al catálogo": nace el artículo desde la lista (o se abre el que ya tiene). */
    public function agregarAlCatalogo(int $renglonId)
    {
        $a = ListasPrecios::articuloDe(ListaPrecioRenglon::findOrFail($renglonId));

        return $this->redirectRoute('articulos.ver', $a);
    }

    public function render()
    {
        $muestra = [];
        foreach ($this->analisis['muestra'] ?? [] as $m) {
            $precio = $m['_precios'][$this->precio] ?? null;
            $muestra[] = $m + ['precio' => $precio, 'costo' => ListasPrecios::costo($precio, $this->conIva, ListasPrecios::descuentos($this->descuento)['total'] ?? 0)];
        }

        return view('livewire.articulos.listas', [
            'listas' => ListaPrecio::with('proveedor:id,nombre')->orderBy('nombre')->get(),
            'muestra' => $muestra,
            'esAdmin' => auth()->user()->esAdmin(),
            'proveedoresSugeridos' => $this->analisis && ! $this->proveedorId && mb_strlen(trim($this->proveedorTexto)) >= 2
                ? Proveedor::where('nombre', 'like', '%'.addcslashes(mb_strtoupper(trim($this->proveedorTexto)), '%_\\').'%')->orderBy('nombre')->limit(8)->get(['id', 'nombre'])
                : collect(),
            'resultados' => mb_strlen(trim($this->buscar)) >= 3
                ? ListasPrecios::buscar($this->buscar)->with(['lista.proveedor:id,nombre', 'articulo:id,descripcion'])->orderBy('descripcion')->limit(30)->get()
                : collect(),
        ]);
    }
}
