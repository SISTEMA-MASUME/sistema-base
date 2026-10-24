<?php

namespace App\Livewire\Finanzas;

use App\Models\Cuenta;
use App\Models\Gasto;
use App\Support\Semanas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Gastos (Finanzas): lo que eran las hojas GASTOS del Sheets. Captura rápida arriba (pensada para el celular), lista con
 * filtros, totales del filtro (solo los de tipo "gasto": los movimientos entre cuentas van aparte) y resumen por categoría.
 *
 * Permisos: entra quien tenga el permiso "gastos" (o el admin). Editar y borrar: el admin, o quien lo capturó ese mismo
 * día; los gastos que crea la nómina (origen "nomina") no se tocan aquí: se mueven reabriendo la nómina. Todo se
 * valida también en el servidor (los botones ocultos no bastan).
 */
#[Layout('layouts.app')]
#[Title('Gastos')]
class Gastos extends Component
{
    use WithPagination;

    public const POR_PAGINA = 50;

    /** Valor del filtro de año para ver todo el historial. */
    public const TODOS_LOS_ANIOS = 'todos';

    /** Valores especiales de los selects de categoría y cuenta. */
    public const OTRA = '__otra';

    public const SIN_CATEGORIA = '__sin';

    /** Las fechas capturadas van del 2000 a un mes adelante: así un año mal escrito (2062, 0026) no se cuela. */
    private const FECHA_MINIMA = '2000-01-01';

    public const MESES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    // ===== Filtros (viven en la dirección para poder compartir o regresar a la misma vista) =====

    #[Url]
    public string $anio = '';

    #[Url]
    public string $mes = '';

    #[Url]
    public string $semana = '';

    /** '' (todos) | masume | personal */
    #[Url]
    public string $de = '';

    #[Url]
    public string $categoria = '';

    #[Url]
    public string $cuenta = '';

    /** '' (todos) | gasto | movimiento */
    #[Url]
    public string $tipo = '';

    #[Url]
    public string $buscar = '';

    // ===== Captura / edición =====

    public ?int $editandoId = null;

    /** @var array<string, string> */
    public array $form = [];

    public string $mensaje = '';

    public string $error = '';

    // ------------------------------------------------------------------------------------------------

    public function mount(): void
    {
        $this->autorizar();
        $this->anio = $this->anio === self::TODOS_LOS_ANIOS ? self::TODOS_LOS_ANIOS : (string) ($this->anioActivo() ?? now()->year);
        $this->form = $this->formVacio();
    }

    /** Cada petición de Livewire vuelve a comprobar el permiso (si se lo quitan, ya no puede ni mover filtros). */
    public function hydrate(): void
    {
        $this->autorizar();
    }

    public function updated(string $propiedad): void
    {
        if (! in_array($propiedad, ['anio', 'mes', 'semana', 'de', 'categoria', 'cuenta', 'tipo', 'buscar'], true)) {
            return;
        }
        if ($propiedad === 'anio') {
            $this->anio = $this->anio === '' ? self::TODOS_LOS_ANIOS : $this->anio;
            // Mes y semana son de un año: con "todos los años" no aplican; y una semana que el año nuevo no tiene (la 53) se quita.
            $anio = $this->anioActivo();
            if ($anio === null) {
                $this->reset(['mes', 'semana']);
            } elseif ($this->semana !== '' && (int) $this->semana > Semanas::delAnio($anio)) {
                $this->semana = '';
            }
        }
        $this->resetPage();
    }

    public function limpiar(): void
    {
        $this->autorizar();
        $this->reset(['mes', 'semana', 'de', 'categoria', 'cuenta', 'tipo', 'buscar']);
        $this->anio = (string) now()->year;
        $this->resetPage();
    }

    private function autorizar(): void
    {
        $usuario = auth()->user();
        abort_unless($usuario && $usuario->puede('gastos'), 403);
    }

    /** Admin: todo menos lo de nómina. Los demás: solo lo que ellos capturaron hoy (y nunca lo de nómina ni lo importado). */
    public function puedeEditar(Gasto $gasto): bool
    {
        $usuario = auth()->user();
        if (! $usuario || $gasto->origen === 'nomina') {
            return false;
        }
        if ($usuario->esAdmin()) {
            return true;
        }

        return $gasto->origen === 'manual' && $gasto->user_id === $usuario->id && $gasto->created_at?->isToday() === true;
    }

    // ===== Filtros: valores ya validados =====

    /** El año elegido; null = todos los años. Un valor raro cuenta como el año actual. */
    private function anioActivo(): ?int
    {
        if ($this->anio === '' || $this->anio === self::TODOS_LOS_ANIOS) {
            return null;
        }
        $anio = (int) $this->anio;

        return $anio >= 2000 && $anio <= 2100 ? $anio : (int) now()->year;
    }

    private function mesActivo(): ?int
    {
        $mes = (int) $this->mes;

        return $mes >= 1 && $mes <= 12 ? $mes : null;
    }

    /** La semana elegida (necesita un año). */
    private function semanaActiva(): ?int
    {
        $anio = $this->anioActivo();
        if ($this->semana === '' || $anio === null) {
            return null;
        }
        $semana = (int) $this->semana;

        return $semana >= 1 && $semana <= Semanas::delAnio($anio) ? $semana : null;
    }

    /** Gastos que cumplen los filtros (sin orden ni relaciones). */
    private function consulta(): Builder
    {
        $q = Gasto::query();
        $anio = $this->anioActivo();

        if (($semana = $this->semanaActiva()) !== null) {
            // Del domingo previo al sábado (como la nómina y las comisiones: lo del domingo cuenta para la semana siguiente).
            [$desde, $hasta] = Semanas::rangoCobro($anio, $semana);
            $q->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString().' 23:59:59']);
        } elseif ($anio !== null) {
            $mes = $this->mesActivo();
            $desde = Carbon::create($anio, $mes ?? 1, 1)->startOfDay();
            $hasta = $mes ? $desde->copy()->endOfMonth() : Carbon::create($anio, 12, 31);
            // ' 23:59:59': en SQLite las fechas se guardan con hora y '2026-09-30 00:00:00' > '2026-09-30'.
            $q->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString().' 23:59:59']);
        }

        $q->when(isset(Gasto::DE[$this->de]), fn ($q) => $q->where('de', $this->de));
        $q->when(isset(Gasto::TIPOS[$this->tipo]), fn ($q) => $q->where('tipo', $this->tipo));
        if ($this->categoria === self::SIN_CATEGORIA) {
            $q->where(fn ($w) => $w->whereNull('categoria')->orWhere('categoria', ''));
        } elseif ($this->categoria !== '') {
            $q->where('categoria', Str::upper(Str::squish($this->categoria)));
        }
        $q->when(ctype_digit($this->cuenta) && $this->cuenta !== '', fn ($q) => $q->where('cuenta_id', (int) $this->cuenta));

        // Cada palabra debe aparecer en la descripción.
        $palabras = array_filter(preg_split('/\s+/u', trim(str_replace(['%', '_'], '', $this->buscar))) ?: []);
        foreach (array_slice($palabras, 0, 6) as $palabra) {
            $q->where('descripcion', 'like', "%{$palabra}%");
        }

        return $q;
    }

    /**
     * Totales de TODO el filtro (no solo de la página): solo los de tipo gasto; los movimientos entre cuentas aparte.
     *
     * @return array{n: int, total: float, masume: float, personal: float, mov_n: int, mov_total: float}
     */
    private function totales(Builder $base): array
    {
        $filas = (clone $base)->toBase()->selectRaw('tipo, de, COUNT(*) as n, SUM(monto) as total')->groupBy('tipo', 'de')->get();
        $t = ['n' => 0, 'total' => 0.0, 'masume' => 0.0, 'personal' => 0.0, 'mov_n' => 0, 'mov_total' => 0.0];
        foreach ($filas as $f) {
            if ($f->tipo === 'movimiento') {
                $t['mov_n'] += (int) $f->n;
                $t['mov_total'] += (float) $f->total;

                continue;
            }
            $t['n'] += (int) $f->n;
            $t['total'] += (float) $f->total;
            $t[$f->de === 'personal' ? 'personal' : 'masume'] += (float) $f->total;
        }

        return array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $t);
    }

    /**
     * Resumen por categoría de los gastos del filtro, de mayor a menor, con el porcentaje del total.
     *
     * @return list<array{categoria: string, total: float, n: int, pct: float}>
     */
    private function resumenPorCategoria(Builder $base, float $total): array
    {
        $filas = (clone $base)->reales()->toBase()->selectRaw('categoria, COUNT(*) as n, SUM(monto) as total')->groupBy('categoria')->get();

        return $filas->map(fn ($f) => [
            'categoria' => filled($f->categoria) ? $f->categoria : 'SIN CATEGORÍA',
            'total' => round((float) $f->total, 2),
            'n' => (int) $f->n,
            'pct' => $total != 0.0 ? round((float) $f->total / $total * 100, 1) : 0.0,
        ])->sortByDesc('total')->values()->all();
    }

    /** @return list<int> */
    private function aniosDisponibles(): array
    {
        return Gasto::query()->selectRaw('SUBSTR(fecha, 1, 4) as anio')->distinct()->pluck('anio')
            ->map(fn ($a) => (int) $a)->filter(fn ($a) => $a >= 2000 && $a <= 2100)
            ->push((int) now()->year, $this->anioActivo() ?? (int) now()->year)
            ->unique()->sortDesc()->values()->all();
    }

    /** Para el filtro: la lista general más las viejas que aún se usan (hasta que se ordenen). @return list<string> */
    private function categorias(): array
    {
        $usadas = Gasto::query()->whereNotNull('categoria')->where('categoria', '!=', '')->distinct()->pluck('categoria')->all();
        $todas = array_unique(array_merge(Gasto::CATEGORIAS, $usadas));
        sort($todas, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($todas);
    }

    /** Para el formulario: solo la lista general (más la categoría vieja del gasto que se edita, para no perderla). @return list<string> */
    private function categoriasFormulario(): array
    {
        $lista = Gasto::CATEGORIAS;
        $actual = (string) ($this->form['categoria'] ?? '');
        if ($actual !== '' && ! in_array($actual, $lista, true)) {
            $lista[] = $actual;
        }
        sort($lista, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($lista);
    }

    // ===== Captura =====

    /** @return array<string, string> */
    private function formVacio(?string $fecha = null, string $cuenta = ''): array
    {
        return [
            'fecha' => $fecha ?? now()->toDateString(), 'monto' => '', 'de' => 'masume', 'categoria' => '',
            'descripcion' => '', 'cuenta_id' => $cuenta, 'cuenta_nueva' => '', 'tipo' => 'gasto', 'destino_id' => '', 'destino_nueva' => '',
        ];
    }

    /** El formulario con todas sus claves (un navegador alterado podría mandarlo incompleto). */
    private function formCompleto(): array
    {
        return array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', array_merge($this->formVacio(), $this->form));
    }

    /** Acepta "1,234.50" y "$1234.5". */
    private static function numero(string $texto): ?float
    {
        $limpio = str_replace(['$', ',', ' '], '', $texto);

        return is_numeric($limpio) ? round((float) $limpio, 2) : null;
    }

    protected function rules(): array
    {
        $f = $this->formCompleto();

        return [
            'form.fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.self::FECHA_MINIMA, 'before_or_equal:'.now()->addMonth()->toDateString()],
            'form.monto' => ['required', function ($atributo, $valor, $fallo) {
                $n = self::numero((string) $valor);
                if ($n === null) {
                    $fallo('El monto debe ser un número, por ejemplo 1250.50.');
                } elseif ($n <= 0) {
                    $fallo('El monto debe ser mayor a cero.');
                } elseif ($n > 999999999999.99) {
                    $fallo('El monto es demasiado grande.');
                }
            }],
            'form.de' => ['required', Rule::in(array_keys(Gasto::DE))],
            'form.tipo' => ['required', Rule::in(array_keys(Gasto::TIPOS))],
            'form.categoria' => [$f['tipo'] === 'gasto' ? 'required' : 'nullable', 'string', 'max:80', function ($atributo, $valor, $fallo) {
                // Solo la lista general; la categoría vieja del gasto que se está editando también vale (para no perderla).
                if (filled($valor) && ! in_array($valor, $this->categoriasPermitidas(), true)) {
                    $fallo('Elige una categoría de la lista.');
                }
            }],
            'form.descripcion' => ['nullable', 'string', 'max:500'],
            'form.cuenta_id' => [$f['tipo'] === 'movimiento' ? 'required' : 'nullable', 'string', 'max:20'],
            'form.cuenta_nueva' => [$f['cuenta_id'] === self::OTRA ? 'required' : 'nullable', 'string', 'max:80'],
            'form.destino_id' => [$f['tipo'] === 'movimiento' ? 'required' : 'nullable', 'string', 'max:20'],
            'form.destino_nueva' => [$f['tipo'] === 'movimiento' && $f['destino_id'] === self::OTRA ? 'required' : 'nullable', 'string', 'max:80'],
        ];
    }

    protected function messages(): array
    {
        return [
            'form.fecha.required' => 'Elige la fecha.',
            'form.fecha.date_format' => 'La fecha no es válida.',
            'form.fecha.after_or_equal' => 'La fecha parece mal escrita: revisa el año.',
            'form.fecha.before_or_equal' => 'La fecha parece mal escrita: revisa el año.',
            'form.monto.required' => 'Escribe el monto.',
            'form.de.required' => 'Elige si es de MASUME o personal.',
            'form.de.in' => 'Elige si es de MASUME o personal.',
            'form.tipo.required' => 'Elige si es un gasto o un movimiento entre cuentas.',
            'form.tipo.in' => 'Elige si es un gasto o un movimiento entre cuentas.',
            'form.categoria.required' => 'Elige la categoría.',
            'form.categoria.max' => 'La categoría es muy larga.',
            'form.descripcion.max' => 'La descripción es muy larga (máximo 500 letras).',
            'form.cuenta_id.required' => 'Elige de qué cuenta sale el dinero.',
            'form.cuenta_nueva.required' => 'Escribe el nombre de la cuenta.',
            'form.cuenta_nueva.max' => 'El nombre de la cuenta es muy largo.',
            'form.destino_id.required' => 'Elige a qué cuenta llega el dinero.',
            'form.destino_nueva.required' => 'Escribe el nombre de la cuenta destino.',
            'form.destino_nueva.max' => 'El nombre de la cuenta es muy largo.',
        ];
    }

    /** @return list<string> */
    private function categoriasPermitidas(): array
    {
        $permitidas = Gasto::CATEGORIAS;
        if ($this->editandoId && ($vieja = Gasto::whereKey($this->editandoId)->value('categoria'))) {
            $permitidas[] = $vieja;
        }

        return $permitidas;
    }

    /** La cuenta del select (o la que se escribió; se crea si no existe). */
    private function cuentaDe(string $id, string $nueva): ?int
    {
        if ($id === self::OTRA) {
            return Cuenta::obtener($nueva)?->id;
        }

        return ctype_digit($id) && $id !== '' ? Cuenta::whereKey((int) $id)->value('id') : null;
    }

    public function guardar(): void
    {
        $this->autorizar();
        $this->error = '';
        $this->mensaje = '';
        $gasto = null;
        if ($this->editandoId) {
            $gasto = Gasto::find($this->editandoId);
            if (! $gasto) {
                $this->cancelarEdicion();
                $this->error = 'Ese gasto ya no existe.';

                return;
            }
            abort_unless($this->puedeEditar($gasto), 403);
        }
        $this->validate();

        $f = $this->formCompleto();
        $monto = self::numero($f['monto']);
        $movimiento = $f['tipo'] === 'movimiento';
        $cuentaId = $this->cuentaDe($f['cuenta_id'], $f['cuenta_nueva']);
        $destinoId = $movimiento ? $this->cuentaDe($f['destino_id'], $f['destino_nueva']) : null;
        if ($movimiento && $cuentaId !== null && $cuentaId === $destinoId) {
            $this->addError('form.destino_id', 'La cuenta destino debe ser distinta a la cuenta de donde sale.');

            return;
        }
        $categoria = $f['categoria'];

        $datos = [
            'fecha' => $f['fecha'], 'monto' => $monto, 'de' => $f['de'], 'tipo' => $f['tipo'],
            'categoria' => filled($categoria) ? $categoria : null,
            'descripcion' => filled($f['descripcion']) ? Str::squish($f['descripcion']) : null,
            'cuenta_id' => $cuentaId, 'cuenta_destino_id' => $destinoId,
        ];

        $guardado = DB::transaction(function () use ($gasto, $datos) {
            if ($gasto) {
                $gasto->fill($datos)->save();

                return $gasto;
            }

            return Gasto::create($datos + ['origen' => 'manual', 'user_id' => auth()->id()]);
        });

        $dinero = '$'.number_format((float) $guardado->monto, 2);
        $que = $guardado->tipo === 'movimiento' ? 'el movimiento entre cuentas' : 'el gasto';
        $this->mensaje = ($gasto ? "Se guardaron los cambios de {$que} de {$dinero}." : "Listo: se guardó {$que} de {$dinero}.");
        if (! $this->consulta()->whereKey($guardado->id)->exists()) {
            $this->mensaje .= ' Con los filtros de abajo no aparece en la lista: presiona "Limpiar filtros" para verlo.';
        }
        // Se deja la fecha y la cuenta para capturar el siguiente más rápido.
        $this->form = $this->formVacio($f['fecha'], $f['cuenta_id'] === self::OTRA ? (string) $cuentaId : $f['cuenta_id']);
        $this->editandoId = null;
        $this->resetErrorBag();
        if (! $gasto) {
            $this->resetPage();
        }
    }

    public function editar(int $id): void
    {
        $this->autorizar();
        $this->mensaje = '';
        $this->error = '';
        $gasto = Gasto::find($id);
        if (! $gasto) {
            $this->error = 'Ese gasto ya no existe.';

            return;
        }
        if ($gasto->origen === 'nomina') {
            $this->error = 'Este gasto lo creó la nómina: para cambiarlo reabre la nómina.';

            return;
        }
        abort_unless($this->puedeEditar($gasto), 403);

        $this->resetErrorBag();
        $this->editandoId = $gasto->id;
        $this->form = [
            'fecha' => $gasto->fecha->toDateString(), 'monto' => (string) (float) $gasto->monto, 'de' => $gasto->de, 'tipo' => $gasto->tipo,
            'categoria' => (string) $gasto->categoria, 'descripcion' => (string) $gasto->descripcion,
            'cuenta_id' => (string) ($gasto->cuenta_id ?? ''), 'cuenta_nueva' => '',
            'destino_id' => (string) ($gasto->cuenta_destino_id ?? ''), 'destino_nueva' => '',
        ];
        $this->dispatch('gasto-editar');
    }

    public function cancelarEdicion(): void
    {
        $this->autorizar();
        $this->editandoId = null;
        $this->form = $this->formVacio($this->form['fecha'] ?? null, (string) ($this->form['cuenta_id'] ?? ''));
        $this->resetErrorBag();
    }

    public function borrar(int $id): void
    {
        $this->autorizar();
        $this->mensaje = '';
        $this->error = '';
        $gasto = Gasto::find($id);
        if (! $gasto) {
            return;
        }
        if ($gasto->origen === 'nomina') {
            $this->error = 'Este gasto lo creó la nómina: para quitarlo reabre la nómina.';

            return;
        }
        abort_unless($this->puedeEditar($gasto), 403);

        $gasto->delete();
        if ($this->editandoId === $gasto->id) {
            $this->cancelarEdicion();
        }
        $this->mensaje = 'Se borró el gasto de $'.number_format((float) $gasto->monto, 2).'.';
    }

    public function render()
    {
        $anio = $this->anioActivo();
        $base = $this->consulta();
        $gastos = (clone $base)->with(['cuenta:id,nombre', 'cuentaDestino:id,nombre'])
            ->orderByDesc('fecha')->orderByDesc('id')
            ->paginate(self::POR_PAGINA);
        $totales = $this->totales($base);
        $semana = $this->semanaActiva();

        $cuentasCaptura = Cuenta::where('activa', true)->where('tipo', '!=', 'por_cobrar')->orderBy('nombre')->get(['id', 'nombre']);
        // Al editar un gasto pagado con una cuenta que ya no está activa, esa cuenta sigue en la lista.
        $faltantes = array_filter([$this->form['cuenta_id'] ?? '', $this->form['destino_id'] ?? ''], fn ($i) => ctype_digit((string) $i) && $i !== '');
        if ($faltantes) {
            $cuentasCaptura = $cuentasCaptura->concat(Cuenta::whereIn('id', $faltantes)->whereNotIn('id', $cuentasCaptura->pluck('id'))->get(['id', 'nombre']))->sortBy('nombre')->values();
        }

        $puedeEditar = [];
        foreach ($gastos as $g) {
            $puedeEditar[$g->id] = $this->puedeEditar($g);
        }

        return view('livewire.finanzas.gastos', [
            'gastos' => $gastos,
            'totales' => $totales,
            'resumen' => $this->resumenPorCategoria($base, $totales['total']),
            'anios' => $this->aniosDisponibles(),
            'meses' => self::MESES,
            'todosLosAnios' => $anio === null,
            'semanas' => $anio === null ? [] : collect(range(1, Semanas::delAnio($anio)))->mapWithKeys(fn ($n) => [$n => Semanas::etiqueta($anio, $n)])->all(),
            'semanaCobra' => $semana !== null ? Semanas::rangoCobro($anio, $semana) : null,
            'categorias' => $this->categorias(),
            'categoriasForm' => $this->categoriasFormulario(),
            'cuentasCaptura' => $cuentasCaptura,
            'cuentasFiltro' => Cuenta::orderBy('nombre')->get(['id', 'nombre']),
            'puedeEditar' => $puedeEditar,
            'editando' => $this->editandoId !== null,
            'enlaceNomina' => Route::has('finanzas.nomina') ? route('finanzas.nomina', ['ver' => 'historial']) : null,
        ]);
    }
}
