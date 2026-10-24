<?php

namespace App\Livewire\Articulos;

use App\Models\ProgramaAjuste;
use App\Models\ProgramaComercial;
use App\Models\Proveedor;
use App\Support\ListasPrecios;
use App\Support\ProgramasComerciales;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Metas con proveedores: cuánto se le lleva comprado a Urrea en el trimestre, qué nivel da (descuento en factura y
 * bono del trimestre siguiente), cuánto falta para el que sigue y si la lista de precios ya tiene el descuento que toca.
 */
#[Layout('layouts.app')]
#[Title('Metas con proveedores')]
class Programas extends Component
{
    public string $proveedorTexto = '';

    public ?int $proveedorId = null;

    // Ajuste a mano por programa (id => campos).
    public array $ajuste = [];

    // Bono que trae la factura real, por programa (id => %).
    public array $bonoFactura = [];

    public string $mensaje = '';

    public function mount(): void
    {
        // De entrada se propone al proveedor URREA (si existe y aún no tiene programa).
        $urrea = Proveedor::porNombre('URREA');
        if ($urrea && ! ProgramaComercial::where('proveedor_id', $urrea->id)->exists()) {
            $this->proveedorId = $urrea->id;
            $this->proveedorTexto = $urrea->nombre;
        }
    }

    private function soloAdmin(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
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

    public function usarUrreaM1(): void
    {
        $this->soloAdmin();
        $this->validate(['proveedorId' => ['required', 'exists:proveedores,id']], ['proveedorId.required' => 'Elige al proveedor (Urrea) de la lista.']);
        $p = ProgramasComerciales::usarUrreaM1($this->proveedorId);
        $this->reset(['proveedorId', 'proveedorTexto']);
        $this->mensaje = 'Listo: '.$p->proveedor?->nombre.' ya tiene el programa M1 de Urrea.';
    }

    /** "Tengo bono de volumen en factura" (Urreanet: "cuenta con Bono de Volumen en Factura"). */
    public function alternarBonoEnFactura(int $programaId): void
    {
        $this->soloAdmin();
        $p = ProgramaComercial::findOrFail($programaId);
        $p->update(['bono_en_factura' => ! $p->bono_en_factura]);
    }

    /** "¿Qué % de bono trae tu factura?": vale solo para el trimestre en curso; vacío = volver al cálculo. */
    public function guardarBonoFactura(int $programaId): void
    {
        $this->soloAdmin();
        $valor = trim(str_replace('%', '', (string) ($this->bonoFactura[$programaId] ?? '')));
        $this->bonoFactura[$programaId] = $valor;
        $this->validate(["bonoFactura.$programaId" => ['nullable', 'numeric', 'min:0', 'max:20']],
            ["bonoFactura.$programaId.*" => 'Escribe el % de bono como número, por ejemplo 4.']);
        $p = ProgramaComercial::findOrFail($programaId);
        $p->update($valor === '' ? ['bono_factura' => null, 'bono_factura_trimestre' => null]
            : ['bono_factura' => round((float) $valor, 2), 'bono_factura_trimestre' => ProgramasComerciales::claveTrimestre(now())]);
        $this->mensaje = $valor === '' ? 'El bono vuelve a ser el calculado.' : 'Listo: este trimestre se usa el '.$valor.'% de bono que trae tu factura.';
    }

    /** Pone en la lista de precios los descuentos que tocan este trimestre (base + descuento en factura). */
    public function actualizarLista(int $programaId): void
    {
        $this->soloAdmin();
        $p = ProgramaComercial::findOrFail($programaId);
        $r = ProgramasComerciales::resumen($p);
        abort_unless($r['lista'], 404);
        $lista = ListasPrecios::recalcular($r['lista'], $r['descuentos_hoy'], $r['lista']->precio_con_iva);
        $this->mensaje = 'La lista '.$lista->nombre.' ya tiene tu costo con '.$lista->descuentos.'%.';
    }

    public function agregarAjuste(int $programaId): void
    {
        $this->soloAdmin();
        $datos = $this->ajuste[$programaId] ?? [];
        $monto = str_replace([',', '$', ' '], '', (string) ($datos['monto'] ?? ''));
        $this->ajuste[$programaId]['monto'] = $monto;
        $this->validate([
            "ajuste.$programaId.fecha" => ['required', 'date', 'before_or_equal:today', 'after:2020-01-01'],
            "ajuste.$programaId.monto" => ['required', 'numeric', 'not_in:0', 'min:-9999999', 'max:9999999'],
            "ajuste.$programaId.nota" => ['nullable', 'string', 'max:191'],
            "ajuste.$programaId.tipo" => ['required', 'in:compra,total'],
        ], [
            '*.fecha.required' => 'Pon la fecha.', '*.fecha.before_or_equal' => 'La fecha no puede ser futura.',
            '*.monto.required' => 'Escribe el monto sin IVA.', '*.monto.numeric' => 'El monto es un número (negativo si es nota de crédito).',
            '*.monto.not_in' => 'El monto no puede ser cero.',
        ]);
        if (($datos['tipo'] ?? 'compra') === 'total' && (float) $monto < 0) {
            $this->addError("ajuste.$programaId.monto", 'El total del trimestre no puede ser negativo.');

            return;
        }
        ProgramaAjuste::create(['programa_comercial_id' => ProgramaComercial::findOrFail($programaId)->id, 'tipo' => $datos['tipo'] ?? 'compra', 'fecha' => $datos['fecha'],
            'monto' => round((float) $monto, 2), 'nota' => trim((string) ($datos['nota'] ?? '')) ?: null, 'user_id' => auth()->id()]);
        $this->ajuste[$programaId] = ['tipo' => 'total', 'fecha' => today()->toDateString(), 'monto' => '', 'nota' => ''];
        $this->mensaje = 'Se anotó el ajuste.';
    }

    public function quitarAjuste(int $id): void
    {
        $this->soloAdmin();
        ProgramaAjuste::findOrFail($id)->delete();
    }

    public function render()
    {
        $programas = ProgramaComercial::with(['proveedor:id,nombre', 'ajustes' => fn ($q) => $q->limit(10)])->get();
        foreach ($programas as $p) {
            $this->bonoFactura[$p->id] ??= $p->bono_factura !== null && $p->bono_factura_trimestre === ProgramasComerciales::claveTrimestre(now())
                ? rtrim(rtrim((string) $p->bono_factura, '0'), '.') : '';
            $this->ajuste[$p->id] ??= ['tipo' => 'total', 'fecha' => today()->toDateString(), 'monto' => '', 'nota' => ''];
        }

        return view('livewire.articulos.programas', [
            'programas' => $programas->map(fn ($p) => ['p' => $p, 'r' => ProgramasComerciales::resumen($p)]),
            'proveedoresSugeridos' => ! $this->proveedorId && mb_strlen(trim($this->proveedorTexto)) >= 2
                ? Proveedor::where('nombre', 'like', '%'.addcslashes(mb_strtoupper(trim($this->proveedorTexto)), '%_\\').'%')->orderBy('nombre')->limit(8)->get(['id', 'nombre'])
                : collect(),
        ]);
    }
}
