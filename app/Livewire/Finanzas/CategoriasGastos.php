<?php

namespace App\Livewire\Finanzas;

use App\Models\Gasto;
use App\Support\CategoriasGasto;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ordenar categorías: junta las categorías viejas del Excel (CARLS, JJ, GSOLINA…) en la lista general. Muestra cada
 * categoría que no está en la lista, con la general sugerida por `CategoriasGasto::sugerir`; el usuario puede cambiarla
 * y unificar todas de un golpe o una por una. Los gastos sin categoría se asignan aparte, uno por uno.
 */
#[Layout('layouts.app')]
#[Title('Ordenar categorías de gastos')]
class CategoriasGastos extends Component
{
    public const MAX_SIN_CATEGORIA = 100;

    /** md5(categoría vieja) => general elegida (md5 porque el nombre puede traer puntos o comas). @var array<string, string> */
    public array $destino = [];

    /** Gasto sin categoría (id) => general elegida. @var array<int|string, string> */
    public array $sinCategoria = [];

    public string $mensaje = '';

    public string $error = '';

    public function mount(): void
    {
        $this->autorizar();
    }

    public function hydrate(): void
    {
        $this->autorizar();
    }

    private function autorizar(): void
    {
        $usuario = auth()->user();
        abort_unless($usuario && $usuario->puede('gastos'), 403);
    }

    /**
     * Categorías en uso que no están en la lista general (incluye borrados), con total y ejemplos.
     *
     * @return list<array{nombre: string, n: int, total: float, ejemplos: list<string>, sugerida: ?string, segura: bool}>
     */
    private function filas(): array
    {
        $filas = Gasto::withTrashed()->toBase()->whereNotNull('categoria')->where('categoria', '!=', '')
            ->whereNotIn('categoria', Gasto::CATEGORIAS)
            ->selectRaw('categoria, COUNT(*) as n, SUM(monto) as total')->groupBy('categoria')->get();

        $salida = [];
        foreach ($filas as $f) {
            $ejemplos = Gasto::withTrashed()->where('categoria', $f->categoria)->whereNotNull('descripcion')->where('descripcion', '!=', '')
                ->orderByDesc('fecha')->limit(2)->pluck('descripcion')->map(fn ($d) => mb_substr((string) $d, 0, 70))->all();
            $s = CategoriasGasto::sugerir($f->categoria, $ejemplos[0] ?? null);
            $salida[] = [
                'nombre' => (string) $f->categoria, 'n' => (int) $f->n, 'total' => round((float) $f->total, 2),
                'ejemplos' => $ejemplos, 'sugerida' => $s['categoria'], 'segura' => $s['segura'],
            ];
        }
        usort($salida, fn ($a, $b) => $b['n'] <=> $a['n']);

        return $salida;
    }

    /** La general elegida para una categoría: la del select o, si no hay, la sugerida. Siempre de la lista general. */
    private function elegida(string $nombre, ?string $sugerida): ?string
    {
        $v = $this->destino[md5($nombre)] ?? $sugerida;

        return in_array($v, Gasto::CATEGORIAS, true) ? $v : null;
    }

    public function unificarTodas(): void
    {
        $this->autorizar();
        $this->mensaje = $this->error = '';
        $cambiados = 0;
        $categorias = 0;
        DB::transaction(function () use (&$cambiados, &$categorias) {
            foreach ($this->filas() as $f) {
                $nuevo = $this->elegida($f['nombre'], $f['sugerida']);
                if ($nuevo === null) {
                    continue;
                }
                $cambiados += Gasto::withTrashed()->where('categoria', $f['nombre'])->update(['categoria' => $nuevo]);
                $categorias++;
            }
        });
        $this->destino = [];
        $this->mensaje = $categorias === 0
            ? 'No había categorías por unificar.'
            : "Listo: se unificaron {$categorias} categorías ({$cambiados} gastos).";
    }

    public function unificarUna(string $nombre): void
    {
        $this->autorizar();
        $this->mensaje = $this->error = '';
        $fila = collect($this->filas())->firstWhere('nombre', $nombre);
        $nuevo = $fila ? $this->elegida($nombre, $fila['sugerida']) : null;
        if ($nuevo === null) {
            $this->error = 'Elige la categoría general para '.$nombre.'.';

            return;
        }
        $cambiados = DB::transaction(fn () => Gasto::withTrashed()->where('categoria', $nombre)->update(['categoria' => $nuevo]));
        unset($this->destino[md5($nombre)]);
        $this->mensaje = "Listo: {$cambiados} gastos de {$nombre} ahora son {$nuevo}.";
    }

    public function asignarSinCategoria(int $id): void
    {
        $this->autorizar();
        $this->mensaje = $this->error = '';
        $nuevo = $this->sinCategoria[$id] ?? null;
        if (! in_array($nuevo, Gasto::CATEGORIAS, true)) {
            $this->error = 'Elige una categoría de la lista.';

            return;
        }
        $gasto = Gasto::find($id);
        if (! $gasto || filled($gasto->categoria)) {
            $this->error = 'Ese gasto ya no está sin categoría.';

            return;
        }
        $gasto->update(['categoria' => $nuevo]);
        unset($this->sinCategoria[$id]);
        $this->mensaje = "Listo: el gasto de \${$gasto->monto} quedó en {$nuevo}.";
    }

    public function render()
    {
        $sin = Gasto::query()->where(fn ($q) => $q->whereNull('categoria')->orWhere('categoria', ''))
            ->orderByDesc('fecha')->limit(self::MAX_SIN_CATEGORIA)->get(['id', 'fecha', 'monto', 'descripcion', 'de']);
        // Preelige la sugerencia por descripción (solo si aún no eligieron otra).
        $sugeridasSin = [];
        foreach ($sin as $g) {
            $sugeridasSin[$g->id] = CategoriasGasto::sugerir(null, $g->descripcion)['categoria'];
        }

        $filas = $this->filas();
        // Cada select arranca con la sugerencia (y sigue ahí hasta que el usuario elija otra).
        foreach ($filas as $f) {
            $this->destino[md5($f['nombre'])] ??= (string) $f['sugerida'];
        }
        foreach ($sugeridasSin as $id => $sugerida) {
            $this->sinCategoria[$id] ??= (string) $sugerida;
        }

        return view('livewire.finanzas.categorias-gastos', [
            'filas' => $filas,
            'general' => Gasto::CATEGORIAS,
            'sinCategoria_gastos' => $sin,
            'sugeridasSin' => $sugeridasSin,
            'totalSin' => Gasto::query()->where(fn ($q) => $q->whereNull('categoria')->orWhere('categoria', ''))->count(),
        ]);
    }
}
