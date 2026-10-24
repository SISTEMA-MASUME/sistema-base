<?php

namespace App\Livewire\Ned;

use App\Models\Ned\NedCliente;
use App\Models\Ned\NedCotizacion;
use App\Models\Ned\NedPartida;
use App\Support\ListaPartidas;
use App\Support\Precios;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Cotización de Ned Tracking. Pedro captura sus partidas y SUS precios; se guarda con el botón (o al
 * agregar/quitar/pegar). La lista que le mandan desde MASUME llega sin precios y la pega aquí.
 */
#[Layout('layouts.app')]
#[Title('Cotización Ned Tracking')]
class Editor extends Component
{
    #[Locked]
    public int $cotizacionId;

    public int|string $folio = '';

    public string $cliente_nombre = '';

    public string $cliente_direccion = '';

    public string $cliente_contacto = '';

    public string $cliente_correo = '';

    public string $cliente_telefono = '';

    public string $fecha = '';

    public int|string $vigencia_dias = 15;

    public string $notas = '';

    /** @var list<array{id: ?int, descripcion: string, cantidad: string, unidad: string, costo: string, precio: string}> */
    public array $partidas = [];

    /** Utilidad (%) para calcular el precio de todas las partidas desde su costo. Solo admin. */
    public string $utilidad = '';

    public string $lista = '';

    public bool $pegando = false;

    public string $mensaje = '';

    public function mount(NedCotizacion $cotizacion): void
    {
        abort_unless(auth()->user()?->puedeUsarNed(), 403);
        $this->cotizacionId = $cotizacion->id;
        foreach (['cliente_nombre', 'cliente_direccion', 'cliente_contacto', 'cliente_correo', 'cliente_telefono', 'notas'] as $c) {
            $this->$c = (string) $cotizacion->$c;
        }
        $this->folio = (int) $cotizacion->folio;
        $this->fecha = $cotizacion->fecha->toDateString();
        $this->vigencia_dias = (int) $cotizacion->vigencia_dias;
        $this->cargarPartidas();
    }

    /** El costo (viene de MASUME) solo lo ve y lo usa el personal de MASUME: Pedro nunca lo recibe en su pantalla. */
    private function verCostos(): bool
    {
        return (bool) auth()->user()?->nedDesdeMasume();
    }

    private function cargarPartidas(): void
    {
        $verCostos = $this->verCostos();
        $this->partidas = NedCotizacion::findOrFail($this->cotizacionId)->partidas()->get()->map(fn (NedPartida $p) => [
            'id' => $p->id,
            'descripcion' => $p->descripcion,
            'cantidad' => rtrim(rtrim(number_format((float) $p->cantidad, 3, '.', ''), '0'), '.'),
            'unidad' => $p->unidad,
            'costo' => $verCostos && (float) $p->costo > 0 ? rtrim(rtrim(number_format((float) $p->costo, 4, '.', ''), '0'), '.') : '',
            // Sin precio todavía (p. ej. partidas pasadas desde MASUME): el campo queda vacío para capturarlo.
            'precio' => (float) $p->precio > 0 ? number_format((float) $p->precio, 2, '.', '') : '',
        ])->all();
        if ($this->partidas === []) {
            $this->agregarRenglon();
        }
    }

    /** Pone el precio de todas las partidas con costo: costo × (1 + utilidad %). */
    public function aplicarUtilidad(): void
    {
        abort_unless($this->verCostos(), 403);
        $this->validate(['utilidad' => ['required', 'numeric', 'min:0', 'max:1000']], [
            'utilidad.*' => 'Escribe la utilidad en % (por ejemplo 40).',
        ]);
        $n = 0;
        foreach ($this->partidas as $i => $p) {
            $costo = self::num($p['costo'] ?? '');
            if ($costo > 0) {
                $this->partidas[$i]['precio'] = number_format(Precios::precioDesdeUtilidad($costo, (float) $this->utilidad), 2, '.', '');
                $n++;
            }
        }
        if ($n === 0) {
            $this->addError('utilidad', 'Ninguna partida tiene costo. Captura el costo o pon los precios a mano.');

            return;
        }
        $this->guardar();
        $sinCosto = count(array_filter($this->partidas, fn ($p) => trim((string) $p['descripcion']) !== '' && self::num($p['costo'] ?? '') <= 0));
        $this->mensaje = "Utilidad de {$this->utilidad}% aplicada a {$n} partidas y guardada."
            .($sinCosto ? " {$sinCosto} sin costo: ponles precio a mano." : '');
    }

    private function agregarRenglon(): void
    {
        $this->partidas[] = ['id' => null, 'descripcion' => '', 'cantidad' => '1', 'unidad' => 'PZA', 'costo' => '', 'precio' => ''];
    }

    private static function num(mixed $v): float
    {
        return (float) str_replace([',', '$', ' '], '', (string) $v);
    }

    public function guardar(): void
    {
        abort_unless(auth()->user()?->puedeUsarNed(), 403);
        $this->validate([
            'folio' => ['required', 'integer', 'min:1', 'max:999999',
                Rule::unique('ned_cotizaciones', 'folio')->ignore($this->cotizacionId)],
            'cliente_nombre' => ['required', 'string', 'max:255'],
            'cliente_direccion' => ['nullable', 'string', 'max:1000'],
            'cliente_contacto' => ['nullable', 'string', 'max:255'],
            'cliente_correo' => ['nullable', 'email', 'max:255'],
            'cliente_telefono' => ['nullable', 'string', 'max:30'],
            'fecha' => ['required', 'date'],
            'vigencia_dias' => ['required', 'integer', 'min:1', 'max:365'],
            'notas' => ['nullable', 'string', 'max:3000'],
            'partidas.*.descripcion' => ['nullable', 'string', 'max:2000'],
            'partidas.*.unidad' => ['nullable', 'string', 'max:20'],
        ], [
            'folio.unique' => 'Ya tienes otra cotización con ese número.',
            'folio.*' => 'El número de cotización debe ser un número entero de 1 a 999999.',
            'cliente_nombre.required' => 'Escribe el nombre del cliente.',
            'cliente_correo.email' => 'Escribe un correo válido.',
            'vigencia_dias.*' => 'La vigencia debe ser de 1 a 365 días.',
        ]);

        $c = NedCotizacion::findOrFail($this->cotizacionId);
        $verCostos = $this->verCostos();
        $costosAntes = $c->partidas()->pluck('costo', 'id');
        DB::transaction(function () use ($c, $verCostos, $costosAntes) {
            $c->update([
                'folio' => (int) $this->folio,
                'cliente_nombre' => mb_strtoupper(trim($this->cliente_nombre)),
                'cliente_direccion' => trim($this->cliente_direccion) ?: null,
                'cliente_contacto' => trim($this->cliente_contacto) ?: null,
                'cliente_correo' => trim($this->cliente_correo) ?: null,
                'cliente_telefono' => trim($this->cliente_telefono) ?: null,
                'fecha' => $this->fecha,
                'vigencia_dias' => (int) $this->vigencia_dias,
                'notas' => trim($this->notas) ?: null,
            ]);
            $c->partidas()->delete();
            $orden = 0;
            foreach ($this->partidas as $p) {
                $desc = trim((string) $p['descripcion']);
                if ($desc === '') {
                    continue;
                }
                $cant = max(0, self::num($p['cantidad']));
                $precio = max(0, round(self::num($p['precio']), 2));
                // Pedro no ve el costo: se conserva el que ya tenía la partida.
                $costo = $verCostos ? self::num($p['costo'] ?? '') : (float) ($costosAntes[$p['id'] ?? 0] ?? 0);
                NedPartida::create([
                    'ned_cotizacion_id' => $c->id, 'orden' => ++$orden, 'descripcion' => mb_strtoupper($desc),
                    'cantidad' => $cant, 'unidad' => mb_strtoupper(trim((string) $p['unidad']) ?: 'PZA'),
                    'costo' => $costo > 0 ? round($costo, 4) : null,
                    'precio' => $precio, 'importe' => Precios::importe($precio, $cant),
                ]);
            }
            $c->recalcularTotales();
        });
        $this->cargarPartidas();
        $this->mensaje = 'Cotización guardada.';
    }

    public function agregar(): void
    {
        $this->agregarRenglon();
    }

    public function quitar(int $i): void
    {
        unset($this->partidas[$i]);
        $this->partidas = array_values($this->partidas);
        if ($this->partidas === []) {
            $this->agregarRenglon();
        }
    }

    public function mover(int $i, int $delta): void
    {
        $j = $i + $delta;
        if (isset($this->partidas[$i], $this->partidas[$j])) {
            [$this->partidas[$i], $this->partidas[$j]] = [$this->partidas[$j], $this->partidas[$i]];
        }
    }

    /** Pega la lista que le mandaron (WhatsApp, Excel o renglón por renglón): llegan sin precio. */
    public function pegarLista(): void
    {
        $nuevas = ListaPartidas::interpretar($this->lista);
        if ($nuevas === []) {
            $this->addError('lista', 'No encontré partidas en lo que pegaste.');

            return;
        }
        // Quita el renglón vacío inicial.
        $this->partidas = array_values(array_filter($this->partidas, fn ($p) => trim((string) $p['descripcion']) !== ''));
        foreach ($nuevas as $n) {
            $this->partidas[] = [
                'id' => null,
                'costo' => '',
                'descripcion' => $n['descripcion'],
                'cantidad' => rtrim(rtrim(number_format($n['cantidad'], 3, '.', ''), '0'), '.'),
                'unidad' => $n['unidad'],
                'precio' => '',
            ];
        }
        $this->lista = '';
        $this->pegando = false;
        $this->mensaje = count($nuevas).' partidas agregadas. Pon tus precios y guarda.';
    }

    public function borrar()
    {
        NedCotizacion::findOrFail($this->cotizacionId)->delete();

        return $this->redirectRoute('ned.index');
    }

    public function render()
    {
        $c = NedCotizacion::findOrFail($this->cotizacionId);
        $importes = collect($this->partidas)->map(fn ($p) => Precios::importe(self::num($p['precio']), self::num($p['cantidad'])));

        return view('livewire.ned.editor', [
            'c' => $c,
            'importes' => $importes,
            'totales' => Precios::totales($importes),
            'verCostos' => $this->verCostos(),
            // Contactos del cliente guardado (para elegir "Atención a" con un clic).
            'contactos' => NedCliente::where('nombre', mb_strtoupper(trim($this->cliente_nombre)))->first()?->contactos ?? [],
        ]);
    }
}
