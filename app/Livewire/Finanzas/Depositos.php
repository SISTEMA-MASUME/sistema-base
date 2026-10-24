<?php

namespace App\Livewire\Finanzas;

use App\Models\Cliente;
use App\Models\ComplementoPago;
use App\Models\Cuenta;
use App\Models\DepositoCobro;
use App\Models\Venta;
use App\Support\CobrosProvisionales;
use App\Support\ComplementosSicar;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Depósitos')]
class Depositos extends Component
{
    use WithFileUploads, WithPagination;

    #[Locked]
    public string $clave = '';

    public string $clienteId = '';

    public string $cuentaId = '';

    public string $fecha = '';

    public string $importe = '';

    public string $referencia = '';

    public string $buscarFactura = '';

    public array $seleccion = [];

    public $archivoXml;

    #[Locked]
    public ?int $depositoId = null;

    public string $complementoId = '';

    public string $motivo = '';

    public string $mensaje = '';

    public string $filtro = 'pendientes';

    public function mount(): void
    {
        $this->autorizar();
        $this->clave = (string) Str::uuid();
        $this->fecha = today()->toDateString();
    }

    public function hydrate(): void
    {
        $this->autorizar();
    }

    private function autorizar(): void
    {
        abort_unless(auth()->user(), 403);
        CobrosProvisionales::autorizar(auth()->user());
    }

    public function updatedClienteId(): void
    {
        $this->seleccion = [];
        $this->buscarFactura = '';
    }

    public function guardar(): void
    {
        $this->autorizar();
        $dep = CobrosProvisionales::crear(['clave' => $this->clave, 'cliente_id' => $this->clienteId,
            'cuenta_id' => $this->cuentaId, 'fecha' => $this->fecha, 'importe' => $this->importe,
            'referencia' => $this->referencia, 'facturas' => $this->seleccion], auth()->user());
        $this->mensaje = $dep->folio().' registrado. Sus facturas entran a comisión; la asignación queda por confirmar.';
        $this->clave = (string) Str::uuid();
        $this->reset(['importe', 'referencia', 'seleccion', 'buscarFactura']);
        $this->resetValidation();
        $this->ver($dep->id);
    }

    public function importarXml(): void
    {
        $this->autorizar();
        $this->validate(['archivoXml' => ['required', 'file', 'max:2048']], ['archivoXml.required' => 'Elige el XML del complemento.']);
        $datos = ComplementosSicar::leerXml(file_get_contents($this->archivoXml->getRealPath()));
        $rep = ComplementosSicar::guardar($datos, auth()->id());
        CobrosProvisionales::evento(null, $rep, auth()->user(), 'lectura_xml', ['folio' => $rep->folio, 'uuid' => $rep->uuid]);
        $this->archivoXml->delete();
        $this->reset('archivoXml');
        $this->mensaje = 'Complemento '.$rep->folio.' leído. Confirma a qué depósito corresponde; aún no se cambió la asignación.';
    }

    public function ver(int $id): void
    {
        $this->autorizar();
        DepositoCobro::findOrFail($id);
        $this->depositoId = $id;
        $this->reset(['complementoId', 'motivo']);
        $this->resetValidation();
    }

    public function confirmar(): void
    {
        $this->autorizar();
        $this->validate(['complementoId' => ['required', 'integer', 'exists:complementos_pago,id']]);
        abort_unless($this->depositoId, 422);
        $dep = CobrosProvisionales::confirmar($this->depositoId, (int) $this->complementoId, $this->motivo, auth()->user());
        $this->mensaje = $dep->folio().' conciliado. Se corrigieron las facturas y se conservaron las comisiones ya pagadas.';
        $this->reset(['complementoId', 'motivo']);
        $this->resetValidation();
    }

    public function anular(): void
    {
        $this->autorizar();
        abort_unless($this->depositoId, 422);
        CobrosProvisionales::anular($this->depositoId, $this->motivo, auth()->user());
        $this->mensaje = 'Asignación anulada. Las facturas vuelven a pendientes y conservan sus comisiones pagadas.';
        $this->reset('motivo');
    }

    public function independiente(int $id): void
    {
        $this->autorizar();
        CobrosProvisionales::independiente($id, $this->motivo, auth()->user());
        $this->mensaje = 'Cobro confirmado como otro depósito. Las asignaciones provisionales anteriores siguen pendientes de revisión.';
        $this->reset('motivo');
    }

    public function revisar(): void
    {
        $this->autorizar();
        abort_unless($this->depositoId, 422);
        CobrosProvisionales::revisar($this->depositoId, $this->motivo, auth()->user());
        $this->mensaje = 'Conciliación abierta para revisión. Revisa el complemento correcto antes de aplicar otro cambio.';
        $this->reset(['motivo', 'complementoId']);
    }

    public function render()
    {
        $this->autorizar();
        $dep = $this->depositoId ? DepositoCobro::with(['cliente', 'cuenta', 'complemento', 'facturas', 'eventos'])->findOrFail($this->depositoId) : null;
        $comparacion = null;
        $errorComparacion = '';
        $rep = null;
        if ($dep && ctype_digit($this->complementoId)) {
            $rep = ComplementoPago::find((int) $this->complementoId);
            if ($rep) {
                try {
                    $comparacion = CobrosProvisionales::comparar($dep, $rep);
                } catch (ValidationException $e) {
                    $errorComparacion = collect($e->errors())->flatten()->first();
                }
            }
        }
        $facturas = Venta::where('cliente_id', (int) $this->clienteId)->where('tipo', 'factura')->where('estatus', 'pendiente')
            ->whereNull('deposito_cobro_id')->whereNull('factura_sustituta')->whereDoesntHave('reemplazadaPor')
            ->when(trim($this->buscarFactura) !== '', fn ($q) => $q->where('folio', 'like', '%'.str_replace(['%', '_'], '', trim($this->buscarFactura)).'%'))
            ->orderByDesc('fecha')->orderBy('id')->limit(100)->get();

        $depositos = DepositoCobro::with(['cliente', 'cuenta', 'complemento'])->when($this->filtro !== 'todos',
            fn ($q) => $q->whereIn('estatus', ['provisional', 'revisar']))->latest('id')->paginate(15);
        $sugerencias = [];
        foreach ($depositos as $d) {
            if (! in_array($d->estatus, ['provisional', 'revisar'])) {
                continue;
            }
            $reps = ComplementoPago::where('rfc', $d->cliente->rfc)->where('importe', $d->importe)
                ->where('cancelado', false)->where('estatus', '!=', 'independiente')
                ->where(fn ($q) => $q->whereNull('deposito_id')->orWhere('deposito_id', $d->id))->latest('id')->limit(5)->get();
            foreach ($reps as $r) {
                try {
                    $c = CobrosProvisionales::comparar($d, $r);
                    $texto = $c['quitar']->isEmpty() && $c['agregar']->isEmpty() ? 'Las facturas coinciden; falta confirmar el vínculo.'
                        : 'Diferencia detectada: retirar '.($c['quitar']->pluck('folio')->implode(', ') ?: 'ninguna')
                            .' y agregar '.($c['agregar']->pluck('folio')->implode(', ') ?: 'ninguna').'.';
                } catch (ValidationException $e) {
                    $texto = collect($e->errors())->flatten()->first();
                }
                $sugerencias[$d->id][] = 'REP '.$r->folio.': '.$texto;
            }
        }

        return view('livewire.finanzas.depositos', [
            'clientes' => Cliente::whereNotNull('rfc')->orderBy('nombre')->get(['id', 'nombre', 'rfc']),
            'cuentas' => Cuenta::where('activa', true)->where('de', 'masume')->whereIn('tipo', ['debito', 'efectivo'])->orderBy('nombre')->get(),
            'facturas' => $facturas, 'totalSeleccionado' => Venta::whereIn('id', array_slice($this->seleccion, 0, 200))->sum('total'),
            'depositos' => $depositos, 'sugerencias' => $sugerencias,
            'pendientes' => DepositoCobro::whereIn('estatus', ['provisional', 'revisar'])->count(),
            // Los REP habituales no son pendientes. Las acciones solo aparecen al abrir un depósito.
            'complementos' => $dep ? ComplementoPago::where(fn ($q) => $q->whereNull('deposito_id')->orWhere('deposito_id', $dep->id))
                ->where('estatus', '!=', 'independiente')
                ->where('rfc', $dep->cliente->rfc)->latest('id')->limit(50)->get() : collect(),
            'deposito' => $dep, 'comparacion' => $comparacion, 'errorComparacion' => $errorComparacion,
            'complementoSeleccionado' => $rep,
        ]);
    }
}
