<?php

namespace App\Livewire\Finanzas;

use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\SaldoCorte;
use App\Models\SaldoLinea;
use App\Models\Venta;
use App\Support\Nombres;
use App\Support\Patrimonio;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Mis finanzas: patrimonio (lo que tengo, lo que debo y lo que me deben) a partir de "cortes" de saldos que se llenan de vez
 * en cuando. No se registra movimiento por movimiento. La cartera de MASUME sale en vivo de Ventas.
 */
#[Layout('layouts.app')]
#[Title('Mis finanzas')]
class MisFinanzas extends Component
{
    public const GRUPOS = [
        'disponible' => ['titulo' => 'Débito, efectivo e inversión', 'tipos' => ['debito', 'efectivo', 'inversion', 'apartado']],
        'tarjetas' => ['titulo' => 'Tarjetas de crédito (lo que debo)', 'tipos' => ['credito']],
        'creditos' => ['titulo' => 'Créditos y deudas (lo que debo)', 'tipos' => ['prestamo']],
        'deben' => ['titulo' => 'Me deben', 'tipos' => ['por_cobrar']],
    ];

    /** Cuenta cuyo saldo se está actualizando sola (botón "Actualizar" en Mis saldos). */
    public ?int $unoId = null;

    public string $unoSaldo = '';

    public string $unoPago = '';

    public string $unoFecha = '';

    /** resumen | cuentas */
    public string $pestana = 'resumen';

    public bool $actualizando = false;

    /** @var array<int, string> cuenta_id => saldo escrito */
    public array $saldos = [];

    /** @var array<int, string> */
    public array $pagos = [];

    /** @var array<int, string> */
    public array $limites = [];

    public string $notas = '';

    public string $mensaje = '';

    // Formulario de cuentas.
    public ?int $cuentaId = null;

    public bool $editandoCuenta = false;

    public string $nombre = '';

    public string $tipo = 'debito';

    public string $de = 'personal';

    public ?string $diaCorte = null;

    public ?string $diaPago = null;

    public ?string $limite = null;

    public bool $activa = true;

    public string $orden = '0';

    public function mount(): void
    {
        abort_unless(auth()->user()?->puede('finanzas'), 403);
    }

    private function autorizar(): void
    {
        abort_unless(auth()->user()?->puede('finanzas'), 403);
    }

    // ---- Actualizar saldos ----

    public function abrirActualizar(): void
    {
        $this->autorizar();
        $ultimos = Patrimonio::ultimosSaldos();
        $cobros = Patrimonio::cobrosDespuesDelCorte($ultimos);
        $this->saldos = $this->pagos = $this->limites = [];
        foreach (Cuenta::where('activa', true)->get() as $c) {
            $l = $ultimos[$c->id] ?? null;
            // Ya trae sumados los cobros que llegaron a esa cuenta después del último saldo (complementos de SICAR).
            $this->saldos[$c->id] = $l ? $this->num((float) $l->saldo + ($cobros[$c->id]['total'] ?? 0)) : '';
            if ($c->tipo === 'credito') {
                $this->pagos[$c->id] = $l?->pago_sin_intereses !== null ? $this->num($l->pago_sin_intereses) : '';
                $this->limites[$c->id] = $l?->fecha_limite?->format('Y-m-d') ?? '';
            }
        }
        $this->notas = '';
        $this->mensaje = '';
        $this->resetErrorBag();
        $this->actualizando = true;
    }

    public function cancelarActualizar(): void
    {
        $this->actualizando = false;
        $this->resetErrorBag();
    }

    public function guardarSaldos(): void
    {
        $this->autorizar();
        $this->resetErrorBag();
        $cuentas = Cuenta::where('activa', true)->get()->keyBy('id');
        $lineas = [];
        foreach ($cuentas as $id => $c) {
            $saldo = Patrimonio::numero($this->saldos[$id] ?? '');
            if ($saldo === false || ($saldo !== null && abs($saldo) > 99999999999.99)) {
                $this->addError("saldos.$id", 'Escribe un número, por ejemplo 12500.50');

                continue;
            }
            $pago = null;
            $fecha = null;
            if ($c->tipo === 'credito') {
                $pago = Patrimonio::numero($this->pagos[$id] ?? '');
                if ($pago === false || ($pago !== null && abs($pago) > 99999999999.99)) {
                    $this->addError("pagos.$id", 'Escribe un número, por ejemplo 3500');

                    continue;
                }
                $f = trim((string) ($this->limites[$id] ?? ''));
                if ($f !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
                    $this->addError("limites.$id", 'Escribe una fecha válida.');

                    continue;
                }
                $fecha = $f !== '' ? $f : null;
            }
            // Sin saldo escrito = sin cambio (se queda el último que había).
            if ($saldo !== null) {
                $lineas[] = ['cuenta_id' => $id, 'saldo' => $saldo, 'pago_sin_intereses' => $pago, 'fecha_limite' => $fecha];
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        if (! $lineas) {
            $this->addError('general', 'Escribe al menos un saldo.');

            return;
        }
        $notas = trim($this->notas);
        $this->validate(['notas' => ['nullable', 'string', 'max:500']]);
        DB::transaction(function () use ($lineas, $notas) {
            $corte = SaldoCorte::create(['fecha' => today(), 'user_id' => auth()->id(), 'notas' => $notas !== '' ? $notas : null]);
            foreach ($lineas as $l) {
                SaldoLinea::create($l + ['saldo_corte_id' => $corte->id]);
            }
        });
        $this->actualizando = false;
        $this->mensaje = 'Listo: guardé tus saldos de hoy.';
    }

    // ---- Actualizar el saldo de una sola cuenta ----

    public function abrirUno(int $id): void
    {
        $this->autorizar();
        $c = Cuenta::where('activa', true)->findOrFail($id);
        $ultimos = Patrimonio::ultimosSaldos();
        $l = $ultimos[$c->id] ?? null;
        $this->unoId = $c->id;
        $this->unoSaldo = $l ? $this->num((float) $l->saldo + (Patrimonio::cobrosDespuesDelCorte($ultimos)[$c->id]['total'] ?? 0)) : '';
        $this->unoPago = $l?->pago_sin_intereses !== null ? $this->num($l->pago_sin_intereses) : '';
        $this->unoFecha = $l?->fecha_limite?->format('Y-m-d') ?? '';
        $this->mensaje = '';
        $this->resetErrorBag();
    }

    public function cancelarUno(): void
    {
        $this->unoId = null;
        $this->resetErrorBag();
    }

    /** Guarda el saldo de esa cuenta en el corte de hoy (si ya hay uno de hoy se agrega ahí; si no, se crea). */
    public function guardarUno(): void
    {
        $this->autorizar();
        $this->resetErrorBag();
        $c = Cuenta::where('activa', true)->findOrFail((int) $this->unoId);
        $saldo = Patrimonio::numero($this->unoSaldo);
        if ($saldo === null || $saldo === false || abs($saldo) > 99999999999.99) {
            $this->addError('unoSaldo', 'Escribe un número, por ejemplo 12500.50');

            return;
        }
        $pago = null;
        $fecha = null;
        if ($c->tipo === 'credito') {
            $pago = Patrimonio::numero($this->unoPago);
            if ($pago === false || ($pago !== null && abs($pago) > 99999999999.99)) {
                $this->addError('unoPago', 'Escribe un número, por ejemplo 3500');

                return;
            }
            $f = trim($this->unoFecha);
            if ($f !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $f)) {
                $this->addError('unoFecha', 'Escribe una fecha válida.');

                return;
            }
            $fecha = $f !== '' ? $f : null;
        }
        DB::transaction(function () use ($c, $saldo, $pago, $fecha) {
            $corte = SaldoCorte::whereDate('fecha', today())->orderByDesc('id')->first()
                ?? SaldoCorte::create(['fecha' => today(), 'user_id' => auth()->id()]);
            SaldoLinea::updateOrCreate(['saldo_corte_id' => $corte->id, 'cuenta_id' => $c->id],
                ['saldo' => $saldo, 'pago_sin_intereses' => $pago, 'fecha_limite' => $fecha]);
        });
        $this->unoId = null;
        $this->mensaje = "Listo: actualicé {$c->nombre}.";
    }

    // ---- Cuentas ----

    public function nuevaCuenta(): void
    {
        $this->autorizar();
        $this->resetErrorBag();
        $this->reset(['cuentaId', 'nombre', 'diaCorte', 'diaPago', 'limite']);
        $this->tipo = 'debito';
        $this->de = 'personal';
        $this->activa = true;
        $this->orden = (string) ((int) Cuenta::max('orden') + 1);
        $this->editandoCuenta = true;
    }

    public function editarCuenta(int $id): void
    {
        $this->autorizar();
        $this->resetErrorBag();
        $c = Cuenta::findOrFail($id);
        $this->cuentaId = $c->id;
        $this->nombre = $c->nombre;
        $this->tipo = $c->tipo;
        $this->de = $c->de;
        $this->diaCorte = $c->dia_corte ? (string) $c->dia_corte : null;
        $this->diaPago = $c->dia_pago ? (string) $c->dia_pago : null;
        $this->limite = $c->limite !== null ? $this->num($c->limite) : null;
        $this->activa = (bool) $c->activa;
        $this->orden = (string) $c->orden;
        $this->editandoCuenta = true;
    }

    public function cancelarCuenta(): void
    {
        $this->editandoCuenta = false;
        $this->resetErrorBag();
    }

    public function guardarCuenta(): void
    {
        $this->autorizar();
        $this->nombre = Nombres::limpiar($this->nombre);
        $llave = Nombres::llave($this->nombre);
        $this->limite = $this->limite !== null ? str_replace(['$', ',', ' '], '', $this->limite) : null;
        $this->validate([
            'nombre' => ['required', 'string', 'max:80', function ($atributo, $valor, $falla) use ($llave) {
                if ($llave !== '' && Cuenta::where('nombre_llave', $llave)->when($this->cuentaId, fn ($q) => $q->where('id', '!=', $this->cuentaId))->exists()) {
                    $falla('Ya hay una cuenta con ese nombre.');
                }
            }],
            'tipo' => ['required', Rule::in(array_keys(Cuenta::TIPOS))],
            'de' => ['required', Rule::in(['masume', 'personal'])],
            'diaCorte' => ['nullable', 'integer', 'between:1,31'],
            'diaPago' => ['nullable', 'integer', 'between:1,31'],
            'limite' => ['nullable', 'numeric', 'min:0', 'max:99999999999.99'],
            'orden' => ['required', 'integer', 'between:0,65000'],
        ], [], ['nombre' => 'nombre', 'diaCorte' => 'día de corte', 'diaPago' => 'día de pago', 'limite' => 'límite', 'orden' => 'orden']);

        $datos = [
            'nombre' => $this->nombre, 'tipo' => $this->tipo, 'de' => $this->de, 'activa' => $this->activa, 'orden' => (int) $this->orden,
            'dia_corte' => $this->tipo === 'credito' && $this->diaCorte ? (int) $this->diaCorte : null,
            'dia_pago' => $this->tipo === 'credito' && $this->diaPago ? (int) $this->diaPago : null,
            'limite' => $this->tipo === 'credito' && $this->limite !== null && $this->limite !== '' ? $this->limite : null,
        ];
        $this->cuentaId ? Cuenta::findOrFail($this->cuentaId)->update($datos) : Cuenta::create($datos);
        $this->editandoCuenta = false;
        $this->mensaje = 'Listo: guardé la cuenta.';
    }

    /**
     * Elimina una cuenta que no se usa. Si ya tiene saldos, ventas o gastos, no se borra (se perdería el historial):
     * se desactiva para que ya no aparezca al actualizar saldos.
     */
    public function eliminarCuenta(int $id): void
    {
        $this->autorizar();
        $c = Cuenta::findOrFail($id);
        $usos = SaldoLinea::where('cuenta_id', $c->id)->count()
            + Venta::withTrashed()->where('cuenta_id', $c->id)->count()
            + Gasto::withTrashed()->where(fn ($q) => $q->where('cuenta_id', $c->id)->orWhere('cuenta_destino_id', $c->id))->count();
        if ($usos > 0) {
            $c->update(['activa' => false]);
            $this->mensaje = "La cuenta {$c->nombre} ya tiene historial ({$usos} registros), así que no la borré: la desactivé y ya no "
                .'aparece al actualizar saldos. Si algún día la vuelves a usar, edítala y palomea "Cuenta activa".';
        } else {
            $c->delete();
            $this->mensaje = "Listo: eliminé la cuenta {$c->nombre}.";
        }
        $this->editandoCuenta = false;
    }

    private function num(mixed $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') ?: '0';
    }

    public function render()
    {
        $resumen = Patrimonio::resumen();
        $historia = Patrimonio::historia();
        $cuentas = Cuenta::orderBy('orden')->orderBy('nombre')->get();
        $ultimos = Patrimonio::ultimosSaldos();

        return view('livewire.finanzas.mis-finanzas', [
            'resumen' => $resumen,
            'tarjetas' => Patrimonio::tarjetas(),
            'historia' => $historia,
            'cuentas' => $cuentas,
            'ultimos' => $ultimos,
            'cobros' => $cobros = Patrimonio::cobrosDespuesDelCorte($ultimos),
            'desgloseDisponible' => Patrimonio::desgloseDisponible($ultimos, $cobros),
            'grupos' => self::GRUPOS,
            'activas' => $cuentas->where('activa', true),
            'puntos' => count($historia) >= 2 ? array_slice($historia, -24) : [],
            'pagosProximos' => Patrimonio::pagosProximos(conNomina: (bool) auth()->user()?->puede('nomina')),
            // Cuánto cambió el patrimonio contra el corte anterior.
            'cambioNeto' => count($historia) >= 2 ? round($historia[count($historia) - 1]['neto'] - $historia[count($historia) - 2]['neto'], 2) : null,
        ]);
    }
}
