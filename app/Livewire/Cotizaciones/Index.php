<?php

namespace App\Livewire\Cotizaciones;

use App\Enums\EstadoCotizacion;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\User;
use App\Support\EliminarCotizacion;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Cotizaciones')]
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $buscar = '';

    #[Url]
    public string $vendedor = '';

    #[Url]
    public string $cliente = '';

    /** Lo que se escribe para buscar el cliente del filtro (antes era una lista con todos los clientes en cada búsqueda). */
    public string $clienteTexto = '';

    #[Url]
    public string $estado = '';

    #[Url]
    public string $desde = '';

    #[Url]
    public string $hasta = '';

    /** Cotizaciones palomeadas para eliminar (solo el admin). */
    public array $seleccion = [];

    public string $mensaje = '';

    public string $error = '';

    /** Filtros que se recuerdan al ir a una cotización y regresar a la lista (por sesión del usuario). */
    private const FILTROS = ['buscar', 'vendedor', 'cliente', 'estado', 'desde', 'hasta'];

    private const LLAVE_SESION = 'cotizaciones.filtros';

    /** Si se llega a la lista sin filtros en la dirección, se ponen los últimos que se usaron. */
    public function mount(): void
    {
        $enUrl = collect(['q', 'vendedor', 'cliente', 'estado', 'desde', 'hasta', 'page'])->contains(fn ($k) => request()->query->has($k));
        $guardados = session(self::LLAVE_SESION);
        if ($enUrl || ! is_array($guardados)) {
            return;
        }
        foreach (self::FILTROS as $f) {
            if (is_string($guardados[$f] ?? null)) {
                $this->{$f} = $guardados[$f];
            }
        }
        if ((int) ($guardados['page'] ?? 1) > 1) {
            $this->setPage((int) $guardados['page']);
        }
    }

    public function dehydrate(): void
    {
        $filtros = [];
        foreach (self::FILTROS as $f) {
            $filtros[$f] = $this->{$f};
        }
        session([self::LLAVE_SESION => $filtros + ['page' => $this->getPage()]]);
    }

    public function updated(string $propiedad): void
    {
        if ($propiedad !== 'seleccion' && ! str_starts_with($propiedad, 'seleccion.')) {
            $this->resetPage();
        }
    }

    /** Solo el admin: elimina las palomeadas (borrado suave). Las que ya son pedido no se tocan y se dice por qué. */
    public function eliminarSeleccionadas(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        $this->mensaje = $this->error = '';
        $eliminadas = [];
        $no = [];
        foreach (Cotizacion::whereIn('id', array_map('intval', $this->seleccion))->orderBy('folio')->get() as $cot) {
            try {
                EliminarCotizacion::eliminar($cot, auth()->user());
                $eliminadas[] = $cot->folio;
            } catch (DomainException $e) {
                $no[] = $e->getMessage();
            }
        }
        $this->seleccion = [];
        if ($eliminadas) {
            $this->mensaje = (count($eliminadas) === 1 ? 'Se eliminó la cotización ' : 'Se eliminaron las cotizaciones ').implode(', ', $eliminadas).'.';
        }
        if ($no) {
            $this->error = implode(' ', $no);
        }
    }

    public function elegirCliente(int $id): void
    {
        $this->cliente = (string) $id;
        $this->clienteTexto = '';
        $this->resetPage();
    }

    public function quitarCliente(): void
    {
        $this->cliente = '';
        $this->resetPage();
    }

    public function limpiar(): void
    {
        $this->reset(['buscar', 'vendedor', 'cliente', 'clienteTexto', 'estado', 'desde', 'hasta']);
        $this->resetPage();
    }

    /**
     * Partidas cuyo artículo coincide: cada palabra debe estar en la descripción o en el modelo; además se
     * compara sin guiones, espacios ni puntos ("SPA-2800" = "SPA 2800" = "SPA2800").
     */
    private static function filtrarPartidas($q, string $texto)
    {
        $sinSignos = fn (string $t) => str_replace(['-', ' ', '.', '/', '%', '_'], '', $t);
        $compacto = fn (string $col) => "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$col}, ''), '-', ''), ' ', ''), '.', ''), '/', '') LIKE ?";
        $modeloCompacto = $compacto('modelo');
        $descripcionCompacta = $compacto('descripcion');
        $palabras = array_filter(array_map(fn ($p) => str_replace(['%', '_'], '', $p), array_slice(preg_split('/\s+/u', $texto) ?: [], 0, 6)));

        // Todo va en un solo grupo: la misma condición sirve para whereHas y para cargar las partidas.
        return $q->where(function ($g) use ($palabras, $texto, $sinSignos, $modeloCompacto, $descripcionCompacta) {
            $g->where(function ($todas) use ($palabras, $sinSignos, $modeloCompacto) {
                foreach ($palabras as $palabra) {
                    $todas->where(fn ($w) => $w->where('descripcion', 'like', "%{$palabra}%")
                        ->orWhere('modelo', 'like', "%{$palabra}%")
                        ->when($sinSignos($palabra) !== '', fn ($w) => $w->orWhereRaw($modeloCompacto, ['%'.$sinSignos($palabra).'%'])));
                }
            });
            // Todo el texto junto, sin guiones ni espacios ("SPA 2800" o "spa-2800" encuentran "SPA2800").
            if (mb_strlen($sinSignos($texto)) >= 3) {
                $g->orWhereRaw($modeloCompacto, ['%'.$sinSignos($texto).'%'])
                    ->orWhereRaw($descripcionCompacta, ['%'.$sinSignos($texto).'%']);
            }
        });
    }

    public function render()
    {
        $texto = trim($this->buscar);
        // También busca en los artículos cotizados (descripción o modelo), p. ej. "SPA2800".
        // Si es el número de un folio que existe, se busca solo ese folio (como siempre).
        $esFolio = ctype_digit($texto) && Cotizacion::where('folio', (int) $texto)->exists();
        $enPartidas = mb_strlen($texto) >= 3 && ! $esFolio ? fn ($p) => self::filtrarPartidas($p, $texto) : null;

        $cotizaciones = Cotizacion::query()
            ->with(['cliente:id,nombre', 'vendedor:id,name,iniciales'])
            ->when($enPartidas !== null, fn ($q) => $q->with(['partidas' => fn ($p) => $enPartidas($p->select(['id', 'cotizacion_id', 'descripcion', 'modelo', 'cantidad', 'unidad']))]))
            ->withCount('partidas')
            ->when($texto !== '', function ($q) use ($texto, $enPartidas) {
                $q->where(function ($q) use ($texto, $enPartidas) {
                    $q->whereHas('cliente', fn ($c) => $c->where('nombre', 'like', '%'.mb_strtoupper($texto).'%'));
                    if (ctype_digit($texto)) {
                        $q->orWhere('folio', (int) $texto);
                    }
                    if ($enPartidas) {
                        $q->orWhereHas('partidas', $enPartidas);
                    }
                });
            })
            ->when($this->vendedor !== '', fn ($q) => $q->where('vendedor_id', (int) $this->vendedor))
            ->when($this->cliente !== '', fn ($q) => $q->where('cliente_id', (int) $this->cliente))
            ->when($this->estado !== '', fn ($q) => $q->where('estado', $this->estado))
            ->when($this->desde !== '', fn ($q) => $q->whereDate('fecha', '>=', $this->desde))
            ->when($this->hasta !== '', fn ($q) => $q->whereDate('fecha', '<=', $this->hasta))
            ->orderByDesc('fecha')->orderByDesc('folio')
            ->paginate(25);

        return view('livewire.cotizaciones.index', [
            'cotizaciones' => $cotizaciones,
            'vendedores' => User::orderBy('name')->get(['id', 'name', 'iniciales']),
            'clienteElegido' => $this->cliente !== '' ? Cliente::withTrashed()->find((int) $this->cliente, ['id', 'nombre']) : null,
            'clientesSugeridos' => mb_strlen(trim($this->clienteTexto)) >= 2
                ? Cliente::where('nombre', 'like', '%'.addcslashes(mb_strtoupper(trim($this->clienteTexto)), '%_\\').'%')->orderBy('nombre')->limit(8)->get(['id', 'nombre'])
                : collect(),
            'estados' => EstadoCotizacion::cases(),
            'esAdmin' => auth()->user()->esAdmin(),
        ]);
    }
}
