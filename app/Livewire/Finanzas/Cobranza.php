<?php

namespace App\Livewire\Finanzas;

use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Cobranza (la parte 3 de CONTROL y la hoja DEUDA del Sheets): cuánto se debe, qué está vencido, qué se
 * espera cobrar en 7 y 14 días, a quién llamar hoy y cuánto debe cada cliente. Sale de Ventas pendientes;
 * vence = fecha de la factura + días de crédito del cliente (30 si no está ligado a un cliente).
 */
#[Layout('layouts.app')]
#[Title('Cobranza')]
class Cobranza extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->puede('cobranza'), 403);
    }

    /** Fecha con la que se marcan cobradas las que SICAR ya da por saldadas (de ella depende la semana de comisión). */
    public string $fechaSaldadas = '';

    public string $mensaje = '';

    /** Buscador de folio (factura o nota). */
    public string $folio = '';

    /** @var list<array{id: int, texto: string}> coincidencias cuando el folio no es exacto */
    public array $encontradas = [];

    public string $errorFolio = '';

    /** Cliente abierto en la ventana flotante ('c12' o 'nNOMBRE'). */
    public string $clienteAbierto = '';

    public function buscarFolio()
    {
        $this->encontradas = [];
        $this->errorFolio = '';
        $q = mb_strtoupper(trim($this->folio));
        if ($q === '') {
            return null;
        }
        $exactas = Venta::whereIn('folio_llave', [Venta::llave('factura', $q), Venta::llave('nota', $q), Venta::llave('nota', 'NOTA-'.$q)])->get(['id', 'folio', 'cliente_nombre', 'fecha', 'total']);
        $lista = $exactas->isNotEmpty() ? $exactas
            : Venta::where('folio', 'like', '%'.str_replace(['%', '_'], '', $q).'%')->orderByDesc('fecha')->limit(10)->get(['id', 'folio', 'cliente_nombre', 'fecha', 'total']);
        if ($lista->count() === 1) {
            $this->verVenta($lista->first()->id);

            return null;
        }
        if ($lista->isEmpty()) {
            $this->errorFolio = 'No encontré ninguna factura o nota con "'.$this->folio.'".';

            return null;
        }
        $this->encontradas = $lista->map(fn (Venta $v) => ['id' => $v->id,
            'texto' => $v->folio.' · '.$v->cliente_nombre.' · '.$v->fecha->format('d/m/Y').' · $'.number_format((float) $v->total, 2)])->all();

        return null;
    }

    /** Venta abierta en la ventana de detalle (sin salir de Cobranza). */
    public ?int $ventaAbierta = null;

    public function verVenta(int $id): void
    {
        $this->ventaAbierta = Venta::whereKey($id)->exists() ? $id : null;
        $this->encontradas = [];
    }

    public function cerrarVenta(): void
    {
        $this->ventaAbierta = null;
    }

    public function abrirCliente(string $clave): void
    {
        $this->clienteAbierto = $clave;
    }

    public function cerrarCliente(): void
    {
        $this->clienteAbierto = '';
    }

    /** Pendientes aquí que en SICAR ya tienen saldo 0 (SICAR no mandó la fecha del pago). */
    private function saldadasEnSicar()
    {
        return Venta::query()->where('estatus', 'pendiente')->whereNotNull('saldo_sicar')->where('saldo_sicar', '<=', 0.5)
            ->orderBy('fecha')->get(['id', 'tipo', 'folio', 'cliente_nombre', 'fecha', 'total']);
    }

    public function marcarSaldadas(): void
    {
        abort_unless(auth()->user()?->puede('ventas'), 403);
        $this->validate(['fechaSaldadas' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']],
            ['fechaSaldadas.required' => 'Escribe la fecha en que se cobraron.', 'fechaSaldadas.before_or_equal' => 'La fecha no puede ser futura.']);
        $n = 0;
        foreach ($this->saldadasEnSicar() as $v) {
            $v = Venta::find($v->id);
            $v->update(['estatus' => 'pagada', 'fecha_pago' => $this->fechaSaldadas, 'pago_referencia' => 'Saldada en SICAR']);
            $v->anotar('estatus', 'pendiente', 'pagada');
            $v->anotar('fecha_pago', null, $this->fechaSaldadas);
            $n++;
        }
        $this->mensaje = $n === 1 ? 'Listo: 1 venta quedó cobrada.' : "Listo: {$n} ventas quedaron cobradas.";
        $this->fechaSaldadas = '';
    }

    /** @return Collection<int, array{venta: Venta, vence: Carbon, dias: int, vencida: bool, monto: float}> */
    private function pendientes(): Collection
    {
        $hoy = today();

        return Venta::query()->where('estatus', 'pendiente')->where('total', '>', 0)
            ->with('cliente:id,nombre,dias_credito')
            ->get(['id', 'tipo', 'folio', 'cliente_id', 'cliente_nombre', 'fecha', 'total', 'estatus_portal'])
            ->map(function (Venta $v) use ($hoy) {
                $vence = $v->vence();

                return [
                    'venta' => $v,
                    'vence' => $vence,
                    'vencida' => $vence->lt($hoy),
                    // Días de atraso (vencida) o que faltan para que venza.
                    'dias' => (int) abs($vence->diffInDays($hoy)),
                    'monto' => (float) $v->total,
                ];
            });
    }

    public function render()
    {
        $hoy = today();
        $p = $this->pendientes();
        $porVencer = fn (int $dias) => $p->filter(fn ($x) => ! $x['vencida'] && $x['vence']->lte($hoy->copy()->addDays($dias)));

        $clientes = $p->groupBy(fn ($x) => $x['venta']->cliente_id ? 'c'.$x['venta']->cliente_id : 'n'.$x['venta']->cliente_nombre)
            ->map(fn (Collection $g, string $clave) => [
                'clave' => $clave,
                'documentos' => $g->sortBy(fn ($x) => $x['vence']->format('Ymd'))->values(),
                'nombre' => $g->first()['venta']->cliente?->nombre ?? $g->first()['venta']->cliente_nombre,
                'dias_credito' => $g->first()['venta']->diasCredito(),
                'total' => $g->sum('monto'),
                'vencido' => $g->where('vencida', true)->sum('monto'),
                'facturas' => $g->count(),
                'mas_atrasada' => (int) $g->where('vencida', true)->max('dias'),
            ])->sortByDesc('total')->values();

        $portal = $p->groupBy(fn ($x) => $x['venta']->estatus_portal ?? 'sin')
            ->map(fn (Collection $g, string $clave) => [
                'etiqueta' => Venta::PORTAL[$clave] ?? 'Sin estatus',
                'clave' => $clave,
                'facturas' => $g->count(),
                'total' => $g->sum('monto'),
            ])->sortByDesc('total')->values();

        return view('livewire.finanzas.cobranza', [
            'cartera' => $p->sum('monto'),
            'pendientes' => $p->count(),
            'vencida' => $p->where('vencida', true)->sum('monto'),
            'vencidas' => $p->where('vencida', true)->count(),
            'siete' => $porVencer(7)->sum('monto'),
            'catorce' => $porVencer(14)->sum('monto'),
            'llamar' => $p->where('vencida', true)->sortByDesc('monto')->take(15)->values(),
            'clientes' => $clientes,
            'portal' => $portal,
            'puedeVentas' => auth()->user()->puede('ventas'),
            'saldadas' => $this->saldadasEnSicar(),
            'venta' => $this->ventaAbierta ? Venta::with(['pedidos:id,folio', 'reemplazadaPor.destino:id,folio', 'reemplaza.origen:id,folio', 'cliente:id,nombre,dias_credito'])->find($this->ventaAbierta) : null,
        ]);
    }
}
