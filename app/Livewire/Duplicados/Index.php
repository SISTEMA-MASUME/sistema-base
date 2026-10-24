<?php

namespace App\Livewire\Duplicados;

use App\Support\UnificarDuplicados;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Unificar clientes y proveedores duplicados (solo admin). El sistema propone grupos parecidos; Daniel
 * palomea cuáles sí son el mismo, elige el principal, ve la vista previa y confirma.
 */
#[Layout('layouts.app')]
#[Title('Unificar duplicados')]
class Index extends Component
{
    #[Url(as: 'de')]
    public string $tipo = 'clientes';

    /** Grupo abierto para revisar: ids propuestos. @var list<int> */
    public array $grupo = [];

    /** @var list<int|string> ids palomeados del grupo (incluye al principal) */
    public array $marcados = [];

    public ?int $principalId = null;

    public string $buscar = '';

    public bool $vistaPrevia = false;

    /** Unir a mano: búsqueda y palomeados de la lista completa. */
    public string $buscarManual = '';

    /** @var list<int|string> */
    public array $manual = [];

    public string $mensaje = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        if (! isset(UnificarDuplicados::TIPOS[$this->tipo])) {
            $this->tipo = 'clientes';
        }
    }

    public function elegirTipo(string $tipo): void
    {
        if (isset(UnificarDuplicados::TIPOS[$tipo])) {
            $this->tipo = $tipo;
            $this->cerrarGrupo();
            $this->reset(['manual', 'buscarManual']);
            $this->mensaje = '';
        }
    }

    /** @param  list<int>  $ids */
    public function revisar(array $ids): void
    {
        $ids = array_values(array_map('intval', $ids));
        $this->grupo = $ids;
        $this->marcados = array_map('strval', $ids);
        $this->principalId = $ids[0] ?? null;
        $this->vistaPrevia = false;
        $this->buscar = '';
        $this->mensaje = '';
    }

    /** Unir a mano los que Daniel palomeó en la lista (el más usado queda como principal propuesto). */
    public function unirManual(): void
    {
        $ids = array_values(array_unique(array_map('intval', $this->manual)));
        if (count($ids) < 2) {
            $this->addError('manual', 'Palomea al menos dos para unirlos.');

            return;
        }
        $this->revisar(UnificarDuplicados::ordenarPorUso($this->tipo, $ids));
        $this->reset(['manual', 'buscarManual']);
    }

    /** Agregar a mano uno que el sistema no detectó. */
    public function agregar(int $id): void
    {
        if (! in_array($id, $this->grupo, true)) {
            $this->grupo[] = $id;
            $this->marcados[] = (string) $id;
            $this->principalId ??= $id;
        }
        $this->buscar = '';
        $this->vistaPrevia = false;
    }

    public function cerrarGrupo(): void
    {
        $this->reset(['grupo', 'marcados', 'principalId', 'vistaPrevia', 'buscar']);
    }

    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['marcados', 'principalId'], true) || str_starts_with($propiedad, 'marcados.')) {
            $this->vistaPrevia = false;
        }
    }

    /** @return list<int> */
    private function duplicados(): array
    {
        return array_values(array_filter(array_map('intval', $this->marcados), fn ($id) => $id !== $this->principalId));
    }

    public function verVistaPrevia(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        if (! $this->principalId || ! in_array((string) $this->principalId, array_map('strval', $this->marcados), true)) {
            $this->addError('principalId', 'Elige cuál se queda (el principal) entre los palomeados.');

            return;
        }
        if ($this->duplicados() === []) {
            $this->addError('marcados', 'Palomea al menos otro además del principal.');

            return;
        }
        $this->vistaPrevia = true;
    }

    public function unir(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        $modelos = UnificarDuplicados::modelos($this->tipo, [$this->principalId]);
        $n = UnificarDuplicados::unir($this->tipo, (int) $this->principalId, $this->duplicados());
        $this->mensaje = "Listo: {$n} ".($n === 1 ? 'duplicado quedó unido' : 'duplicados quedaron unidos').' en '.$modelos->first()->nombre.'.';
        $this->cerrarGrupo();
    }

    public function render()
    {
        $clase = UnificarDuplicados::TIPOS[$this->tipo];
        $grupos = $this->grupo === [] ? UnificarDuplicados::grupos($this->tipo) : [];
        $ids = array_unique(array_merge(array_merge(...($grupos ?: [[]])), $this->grupo));
        $nombres = $ids ? $clase::whereIn('id', $ids)->pluck('nombre', 'id') : collect();

        $resultados = collect();
        $b = trim($this->buscar);
        if ($this->grupo !== [] && mb_strlen($b) >= 2) {
            $resultados = $clase::where('nombre', 'like', '%'.addcslashes($b, '%_\\').'%')
                ->whereNotIn('id', $this->grupo)->orderBy('nombre')->limit(15)->get(['id', 'nombre']);
        }

        // Lista para unir a mano (con búsqueda); los palomeados siempre se muestran.
        $bm = trim($this->buscarManual);
        $lista = $this->grupo === []
            ? $clase::query()
                ->when($bm !== '', fn ($q) => $q->where('nombre', 'like', '%'.addcslashes($bm, '%_\\').'%'))
                ->orderBy('nombre')->limit(60)->get(['id', 'nombre'])
                ->concat($clase::whereIn('id', array_map('intval', $this->manual))->get(['id', 'nombre']))
                ->unique('id')->sortBy('nombre')->values()
            : collect();

        return view('livewire.duplicados.index', [
            'lista' => $lista,
            'grupos' => $grupos,
            'nombres' => $nombres,
            'resultados' => $resultados,
            'previa' => $this->vistaPrevia ? UnificarDuplicados::vistaPrevia($this->tipo, (int) $this->principalId, $this->duplicados()) : [],
            'rutaFicha' => $this->tipo === 'clientes' ? 'clientes.ver' : 'proveedores.ver',
        ]);
    }
}
