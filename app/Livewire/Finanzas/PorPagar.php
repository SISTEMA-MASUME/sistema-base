<?php

namespace App\Livewire\Finanzas;

use App\Models\Cuenta;
use App\Models\CuentaPorPagar;
use App\Models\PagoProveedor;
use App\Models\Proveedor;
use App\Support\CuentasPorPagar;
use App\Support\Nombres;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/** "A quién le debo" (solo el admin): compras a crédito pendientes por proveedor, pagarlas con comprobante y el historial. */
#[Layout('layouts.app')]
#[Title('A quién le debo')]
class PorPagar extends Component
{
    use WithFileUploads;

    public string $pestana = 'pendientes';

    /** @var list<string> ids de deudas marcadas */
    public array $marcadas = [];

    public string $mensaje = '';

    public string $error = '';

    // Registrar un pago
    public ?int $pagandoProveedor = null;

    public string $pFecha = '';

    public string $pCuenta = '';

    public string $pReferencia = '';

    public $pComprobante = null;

    /** Comprobante que se sube después, a un pago ya registrado. */
    public $comprobanteTarde = null;

    // Agregar deuda a mano
    public bool $agregando = false;

    public string $aProveedor = '';

    public string $aFolio = '';

    public string $aDescripcion = '';

    public string $aFecha = '';

    public string $aVence = '';

    public string $aImporte = '';

    /** Deuda a mano que se está corrigiendo (null = agregar una nueva). */
    public ?int $editandoId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        $this->pestana = in_array(request()->query('pestana'), ['pendientes', 'pagadas'], true) ? request()->query('pestana') : 'pendientes';
    }

    /** Marca solo esa deuda y abre el registro del pago. */
    public function pagarUna(int $id): void
    {
        $d = CuentaPorPagar::where('estatus', 'pendiente')->findOrFail($id);
        $this->marcadas = [(string) $d->id];
        $this->abrirPago($d->proveedor_id);
    }

    /** Abre el registro del pago con lo marcado de ese proveedor. */
    public function pagarMarcadas(int $proveedorId): void
    {
        $ids = $this->idsMarcadas($proveedorId);
        if ($ids === []) {
            $this->error = 'Marca primero las facturas de ese proveedor que pagaste.';

            return;
        }
        $this->marcadas = array_map('strval', $ids);
        $this->abrirPago($proveedorId);
    }

    private function abrirPago(int $proveedorId): void
    {
        $this->error = $this->mensaje = '';
        $this->resetErrorBag();
        $this->pagandoProveedor = $proveedorId;
        $this->pFecha = today()->toDateString();
        $this->pReferencia = '';
        $this->pComprobante = null;
    }

    /** @return list<int> */
    private function idsMarcadas(int $proveedorId): array
    {
        return CuentaPorPagar::where('estatus', 'pendiente')->where('proveedor_id', $proveedorId)
            ->whereIn('id', array_map('intval', $this->marcadas))->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function cancelarPago(): void
    {
        $this->pagandoProveedor = null;
        $this->pComprobante = null;
        $this->resetErrorBag();
    }

    public function guardarPago(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        $this->validate([
            'pFecha' => ['required', 'date'],
            'pCuenta' => ['nullable', 'exists:cuentas,id'],
            'pReferencia' => ['nullable', 'string', 'max:120'],
            'pComprobante' => ['nullable', 'file', 'mimes:'.CuentasPorPagar::MIMES_COMPROBANTE, 'max:15360'],
        ], [
            'pFecha.required' => 'Escribe la fecha del pago.',
            'pComprobante.mimes' => 'El comprobante debe ser PDF o imagen (foto o captura).',
            'pComprobante.max' => 'El comprobante pesa más de 15 MB.',
        ]);
        $ids = $this->pagandoProveedor ? $this->idsMarcadas($this->pagandoProveedor) : [];
        try {
            $pago = CuentasPorPagar::pagar($ids, $this->pFecha, $this->pCuenta !== '' ? (int) $this->pCuenta : null, $this->pReferencia, $this->pComprobante, auth()->user());
        } catch (DomainException $e) {
            $this->error = $e->getMessage();

            return;
        }
        $this->mensaje = 'Listo: se registró el pago de $'.number_format((float) $pago->monto, 2).' a '.$pago->proveedor->nombre
            .' y se quitó de lo que debes.'.($pago->comprobante_path ? '' : ' Ojo: quedó sin comprobante.');
        $this->marcadas = [];
        $this->pagandoProveedor = null;
        $this->pComprobante = null;
    }

    /** Sube (o cambia) el comprobante de un pago ya registrado. */
    public function guardarComprobante(int $pagoId): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        $this->error = $this->mensaje = '';
        $v = validator(['comprobante' => $this->comprobanteTarde],
            ['comprobante' => ['required', 'file', 'mimes:'.CuentasPorPagar::MIMES_COMPROBANTE, 'max:15360']],
            ['comprobante.required' => 'No llegó el archivo. Inténtalo otra vez.', 'comprobante.mimes' => 'El comprobante debe ser PDF o imagen (foto o captura).',
                'comprobante.max' => 'El comprobante pesa más de 15 MB.']);
        if ($v->fails()) {
            $this->error = $v->errors()->first();
            $this->comprobanteTarde = null;

            return;
        }
        $pago = PagoProveedor::findOrFail($pagoId);
        $habia = (bool) $pago->comprobante_path;
        CuentasPorPagar::adjuntarComprobante($pago, $this->comprobanteTarde);
        $this->comprobanteTarde = null;
        $this->mensaje = $habia ? 'Se cambió el comprobante del pago a '.$pago->proveedor->nombre.'.' : 'Listo: se agregó el comprobante del pago a '.$pago->proveedor->nombre.'.';
    }

    public function anularPago(int $id): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        CuentasPorPagar::anularPago(PagoProveedor::findOrFail($id), auth()->user());
        $this->mensaje = 'Pago anulado: sus facturas regresaron a "Por pagar".';
    }

    public function editar(int $id): void
    {
        $d = CuentaPorPagar::with('proveedor:id,nombre')->where('estatus', 'pendiente')->whereNull('orden_proveedor_id')->findOrFail($id);
        $this->resetErrorBag();
        $this->editandoId = $d->id;
        $this->aProveedor = $d->proveedor->nombre;
        $this->aFolio = (string) $d->folio;
        $this->aDescripcion = (string) $d->descripcion;
        $this->aFecha = $d->fecha->toDateString();
        $this->aVence = (string) $d->vence?->toDateString();
        $this->aImporte = number_format((float) $d->importe, 2, '.', '');
        $this->agregando = true;
    }

    public function abrirAgregar(): void
    {
        $this->editandoId = null;
        $this->reset(['aProveedor', 'aFolio', 'aDescripcion', 'aVence', 'aImporte']);
        $this->aFecha = today()->toDateString();
        $this->agregando = true;
        $this->resetErrorBag();
    }

    public function agregar(): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        $importe = str_replace([',', '$', ' '], '', $this->aImporte);
        $this->validate([
            'aProveedor' => ['required', 'string'],
            'aFolio' => ['nullable', 'string', 'max:60'],
            'aDescripcion' => ['nullable', 'string', 'max:255'],
            'aFecha' => ['required', 'date'],
            'aVence' => ['nullable', 'date'],
        ], ['aProveedor.required' => 'Elige el proveedor.', 'aFecha.required' => 'Escribe la fecha de la factura.']);
        $proveedor = Proveedor::where('nombre_llave', Nombres::llave($this->aProveedor))->first();
        if (! $proveedor) {
            $this->addError('aProveedor', 'No hay un proveedor con ese nombre. Elígelo de la lista.');

            return;
        }
        if (! is_numeric($importe) || (float) $importe <= 0) {
            $this->addError('aImporte', 'Escribe el importe con IVA (lo que vas a pagar).');

            return;
        }
        if ($this->editandoId) {
            $d = CuentaPorPagar::where('estatus', 'pendiente')->whereNull('orden_proveedor_id')->findOrFail($this->editandoId);
            $folio = trim($this->aFolio);
            if ($folio !== '' && CuentaPorPagar::where('proveedor_id', $proveedor->id)->where('folio', $folio)->where('estatus', '!=', 'cancelada')->whereKeyNot($d->id)->exists()) {
                $this->addError('aFolio', 'Ya está anotada la factura '.$folio.' de ese proveedor.');

                return;
            }
            $d->update(['proveedor_id' => $proveedor->id, 'folio' => $folio !== '' ? $folio : null, 'descripcion' => trim($this->aDescripcion) ?: null,
                'fecha' => $this->aFecha, 'vence' => $this->aVence !== '' ? $this->aVence : null, 'importe' => round((float) $importe, 2)]);
            $this->agregando = false;
            $this->editandoId = null;
            $this->mensaje = 'Deuda corregida.';

            return;
        }
        try {
            CuentasPorPagar::agregar([
                'proveedor_id' => $proveedor->id, 'folio' => $this->aFolio, 'descripcion' => $this->aDescripcion,
                'fecha' => $this->aFecha, 'vence' => $this->aVence, 'importe' => (float) $importe,
            ], auth()->user());
        } catch (DomainException $e) {
            $this->addError('aFolio', $e->getMessage());

            return;
        }
        $this->agregando = false;
        $this->mensaje = 'Deuda anotada a '.$proveedor->nombre.'.';
    }

    /** Una deuda capturada a mano por error se quita (queda cancelada, no se borra). Las de una OC se quitan cancelando la OC. */
    public function quitar(int $id): void
    {
        abort_unless(auth()->user()->esAdmin(), 403);
        $d = CuentaPorPagar::where('estatus', 'pendiente')->whereNull('orden_proveedor_id')->findOrFail($id);
        $d->update(['estatus' => 'cancelada']);
        $this->marcadas = array_values(array_diff($this->marcadas, [(string) $id]));
        $this->mensaje = 'Se quitó la deuda'.($d->folio ? ' '.$d->folio : '').'.';
    }

    public function render()
    {
        $pendientes = CuentaPorPagar::with(['proveedor:id,nombre', 'orden:id,folio,pedido_id', 'orden.pedido:id,folio', 'user:id,name'])
            ->where('estatus', 'pendiente')->orderBy('fecha')->orderBy('id')->get();
        $grupos = $pendientes->groupBy('proveedor_id')
            ->map(fn ($ds) => ['proveedor' => $ds->first()->proveedor, 'deudas' => $ds, 'total' => (float) $ds->sum('importe'), 'vieja' => $ds->min('fecha')])
            ->sortByDesc('total')->values();
        $pagando = $this->pagandoProveedor ? CuentaPorPagar::whereIn('id', $this->idsMarcadas($this->pagandoProveedor))->get() : collect();

        return view('livewire.finanzas.por-pagar', [
            'grupos' => $grupos,
            'total' => (float) $pendientes->sum('importe'),
            'masVieja' => $pendientes->first(),
            'pagadoMes' => (float) PagoProveedor::whereNull('anulado_at')->whereBetween('fecha', [today()->startOfMonth(), today()->endOfMonth()])->sum('monto'),
            'pagos' => $this->pestana === 'pagadas'
                ? PagoProveedor::with(['proveedor:id,nombre', 'cuenta:id,nombre', 'user:id,name'])->orderByDesc('fecha')->orderByDesc('id')->limit(200)->get()
                : collect(),
            'pagando' => $pagando,
            'pagandoNombre' => $this->pagandoProveedor ? Proveedor::whereKey($this->pagandoProveedor)->value('nombre') : null,
            'cuentas' => Cuenta::where('activa', true)->whereIn('tipo', ['debito', 'efectivo', 'credito'])->orderBy('orden')->orderBy('nombre')->get(['id', 'nombre']),
            'proveedores' => $this->agregando ? Proveedor::orderBy('nombre')->pluck('nombre') : collect(),
        ]);
    }
}
