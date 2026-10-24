<?php

namespace App\Livewire\Finanzas;

use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\Nomina as NominaPago;
use App\Models\NominaLinea;
use App\Models\Prestamo;
use App\Models\PrestamoAbono;
use App\Models\User;
use App\Models\Venta;
use App\Support\CalculoNomina;
use App\Support\Patrimonio;
use App\Support\Semanas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Nómina y comisiones (Finanzas): lo que era la hoja NOMINA y el DASHBOARD del Sheets.
 * Pestañas: Semana (borrador de la nómina de lunes a sábado), Préstamos, Reparto del socio (mensual),
 * Historial (lo ya pagado, con cuánto se le ha pagado a cada quien) y Empleados (solo admin).
 *
 * Permisos: entra quien tenga el permiso "nómina" (o el admin). Empleados y reabrir una nómina pagada son solo del
 * admin. Cada acción vuelve a comprobarlo aquí en el servidor (los botones ocultos no bastan). La lógica de dinero
 * vive en Support\CalculoNomina; esta pantalla solo captura y muestra.
 */
#[Layout('layouts.app')]
#[Title('Nómina y comisiones')]
class Nomina extends Component
{
    public const PESTANAS = ['semana' => 'Semana', 'prestamos' => 'Préstamos', 'reparto' => 'Reparto del socio', 'historial' => 'Historial', 'empleados' => 'Empleados'];

    public const MESES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /** Campos de la línea que se editan en la semana, con el nombre que lleva el mensaje de error. */
    private const CAMPOS_LINEA = [
        'sueldo_base' => 'El sueldo base', 'comision_pct' => 'El porcentaje', 'comision' => 'La comisión',
        'ajuste' => 'El bono o descuento', 'descuento_prestamo' => 'El descuento de préstamo', 'notas' => 'La nota',
    ];

    #[Url]
    public string $ver = 'semana';

    // ===== Semana =====

    #[Url]
    public ?int $anio = null;

    #[Url]
    public ?int $semana = null;

    public bool $verCobros = false;

    public string $agregarUsuario = '';

    // ===== Préstamos =====

    public bool $nuevoPrestamo = false;

    public string $pUsuario = '';

    public string $pMonto = '';

    public string $pFecha = '';

    public string $pDescuento = '';

    public string $pNota = '';

    public ?int $abonoPara = null;

    public string $aMonto = '';

    public string $aFecha = '';

    public string $aNota = '';

    // ===== Reparto del socio =====

    public int $repAnio = 0;

    public int $repMes = 0;

    // ===== Historial =====

    public string $histAnio = '';

    public string $histMes = '';

    public ?int $abierta = null;

    // ===== Empleados (admin) =====

    /** @var array<int, array<string, mixed>> */
    public array $emp = [];

    // ===== Mensajes y versión de los campos (sube cuando un valor se rechaza, para que el campo regrese al guardado) =====

    public string $mensaje = '';

    public string $error = '';

    public int $version = 0;

    // ------------------------------------------------------------------------------------------------

    public function mount(): void
    {
        $this->autorizar();
        [$a, $s] = Semanas::deFecha(today());
        $this->anio = $this->anio >= 2000 && $this->anio <= 2100 ? $this->anio : $a;
        $this->semana = $this->semana !== null && $this->semana >= 1 && $this->semana <= Semanas::delAnio($this->anio) ? $this->semana : ($this->anio === $a ? $s : 1);
        $previo = today()->startOfMonth()->subMonthNoOverflow();
        $this->repAnio = $previo->year;
        $this->repMes = $previo->month;
        $this->histAnio = (string) today()->year;
        $this->pFecha = $this->aFecha = today()->toDateString();
        $this->arreglarPestana();
        if ($this->ver === 'empleados') {
            $this->cargarEmpleados();
        }
    }

    /** Cada petición vuelve a comprobar el permiso (si se lo quitan, ya no puede ni cambiar de pestaña). */
    public function hydrate(): void
    {
        $this->autorizar();
    }

    private function autorizar(bool $soloAdmin = false): void
    {
        $usuario = auth()->user();
        abort_unless($usuario && $usuario->puede('nomina'), 403);
        abort_if($soloAdmin && ! $usuario->esAdmin(), 403);
    }

    private function esAdmin(): bool
    {
        return (bool) auth()->user()?->esAdmin();
    }

    private function limpiarMensajes(): void
    {
        $this->mensaje = '';
        $this->error = '';
        $this->resetErrorBag();
    }

    private function fallo(string $texto): void
    {
        $this->error = $texto;
        $this->version++;
    }

    private function arreglarPestana(): void
    {
        if (! isset(self::PESTANAS[$this->ver]) || ($this->ver === 'empleados' && ! $this->esAdmin())) {
            $this->ver = 'semana';
        }
    }

    public function irA(string $pestana): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $this->ver = $pestana;
        $this->arreglarPestana();
        if ($this->ver === 'empleados') {
            $this->cargarEmpleados();
        }
    }

    public function updated(string $propiedad): void
    {
        if ($propiedad === 'anio' || $propiedad === 'semana') {
            $this->anio = max(2000, min(2100, (int) $this->anio));
            $this->semana = max(1, min(Semanas::delAnio($this->anio), (int) $this->semana));
            $this->verCobros = false;
            $this->limpiarMensajes();
        }
        if ($propiedad === 'repAnio' || $propiedad === 'repMes') {
            $this->repAnio = max(2000, min(2100, (int) $this->repAnio));
            $this->repMes = max(1, min(12, (int) $this->repMes));
            $this->limpiarMensajes();
        }
        if ($propiedad === 'histAnio' || $propiedad === 'histMes') {
            $this->abierta = null;
        }
    }

    /** Número escrito a mano ("$1,234.50") → float; null si no es un número. */
    private static function numero(mixed $valor): ?float
    {
        $t = trim(str_replace(['$', ',', ' '], '', (string) $valor));

        return $t !== '' && is_numeric($t) ? round((float) $t, 2) : null;
    }

    private static function dinero(float|string $n): string
    {
        return '$'.number_format((float) $n, 2);
    }

    // ================================================================================== SEMANA

    public function semanaActual(): void
    {
        $this->autorizar();
        [$this->anio, $this->semana] = Semanas::deFecha(today());
        $this->verCobros = false;
        $this->limpiarMensajes();
    }

    /** Quitar un cobro de la comisión (ya se comisionó antes, p. ej. lo marcó pagado a mano y el complemento llegó después). */
    public function quitarCobro(int $ventaId, ?string $motivo = null): void
    {
        $this->autorizar();
        $n = CalculoNomina::semana((int) $this->anio, (int) $this->semana);
        abort_if($n->pagada(), 403);
        $motivo = trim((string) $motivo);
        if (mb_strlen($motivo) < 5) {
            $this->error = 'Escribe por qué se quita (mínimo 5 letras), por ejemplo "ya se comisionó la semana pasada".';

            return;
        }
        $venta = CalculoNomina::cobros($n->anio, $n->semana)->findOrFail($ventaId);
        $venta->update(['comision_excluida_at' => now(), 'comision_excluida_por' => auth()->id(), 'comision_excluida_motivo' => mb_substr($motivo, 0, 255)]);
        $venta->anotar('comision', 'entra a comisión', 'no entra: '.$motivo);
        CalculoNomina::recalcular($n);
        $this->error = '';
        $this->mensaje = "Se quitó {$venta->folio} de la comisión. Ya no entra en ninguna semana (puedes regresarla abajo).";
    }

    /** @return array{quitados: Collection, quitadosAntes: Collection} */
    private function quitados(NominaPago $n): array
    {
        if ($n->pagada()) {
            return ['quitados' => collect(), 'quitadosAntes' => collect()];
        }
        [$inicio, $sabado] = Semanas::rangoCobro($n->anio, $n->semana);
        $todos = Venta::whereNotNull('comision_excluida_at')->whereNull('nomina_id')
            ->where('fecha_pago', '<=', $sabado->toDateString().' 23:59:59')
            ->with('comisionExcluidaPor:id,name')->latest('comision_excluida_at')->limit(100)->get();
        [$recientes, $antes] = $todos->partition(fn (Venta $v) => $v->comision_excluida_at->gte($inicio->copy()->startOfDay()));

        return ['quitados' => $recientes->values(), 'quitadosAntes' => $antes->values()];
    }

    /** Regresar a la comisión un cobro que se había quitado. */
    public function regresarCobro(int $ventaId): void
    {
        $this->autorizar();
        $n = CalculoNomina::semana((int) $this->anio, (int) $this->semana);
        abort_if($n->pagada(), 403);
        $venta = Venta::whereNotNull('comision_excluida_at')->whereNull('nomina_id')->findOrFail($ventaId);
        $venta->update(['comision_excluida_at' => null, 'comision_excluida_por' => null, 'comision_excluida_motivo' => null]);
        $venta->anotar('comision', 'no entra', 'entra a comisión');
        CalculoNomina::recalcular($n);
        $this->mensaje = "{$venta->folio} vuelve a entrar en la comisión.";
    }

    public function alternarCobros(): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $this->verCobros = ! $this->verCobros;
    }

    /** Guarda un campo de la línea de la semana (se llama al cambiar el campo) y recalcula la nómina. */
    public function guardarLinea(int $id, string $campo, mixed $valor): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $linea = NominaLinea::with('nomina')->findOrFail($id);
        $n = $linea->nomina;
        if ($n->pagada() || $n->tipo !== 'semanal') {
            $this->fallo('Esta nómina ya está pagada: no se puede cambiar.');

            return;
        }
        if (! isset(self::CAMPOS_LINEA[$campo])) {
            abort(422);
        }

        if ($campo === 'notas') {
            $linea->notas = mb_substr(trim((string) $valor), 0, 500) ?: null;
        } elseif ($campo === 'comision' && trim((string) $valor) === '') {
            $linea->comision_manual = false;   // campo vacío = que el sistema lo calcule otra vez
        } else {
            $numero = self::numero($valor);
            if ($numero === null && trim((string) $valor) === '' && in_array($campo, ['ajuste', 'descuento_prestamo', 'sueldo_base', 'comision_pct'], true)) {
                $numero = 0.0;
            }
            if ($numero === null) {
                $this->fallo(self::CAMPOS_LINEA[$campo].' tiene que ser un número.');

                return;
            }
            $limites = ['ajuste' => [-999999, 999999], 'comision_pct' => [0, 100]];
            [$min, $max] = $limites[$campo] ?? [0, 9999999];
            if ($numero < $min || $numero > $max) {
                $this->fallo(self::CAMPOS_LINEA[$campo].' tiene que estar entre '.number_format($min).' y '.number_format($max).'.');

                return;
            }
            if ($campo === 'descuento_prestamo' && $numero > 0) {
                $saldo = $linea->user_id ? Prestamo::activos()->where('user_id', $linea->user_id)->get()->sum(fn (Prestamo $p) => $p->saldo()) : 0;
                if ($numero > $saldo + 0.004) {
                    $this->fallo($saldo > 0
                        ? 'El descuento no puede pasar del saldo del préstamo ('.self::dinero($saldo).').'
                        : 'Esta persona no tiene préstamos con saldo. Para descontar otra cosa usa "Bono o descuento".');

                    return;
                }
            }
            $linea->{$campo} = $numero;
            if ($campo === 'comision') {
                $linea->comision_manual = true;
            }
        }
        $linea->save();
        CalculoNomina::recalcular($n);
    }

    /** Desde qué cuenta se le paga a esta persona esta semana (vacío = la de siempre del empleado o la general). */
    public function guardarCuentaLinea(int $id, mixed $cuentaId): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $linea = NominaLinea::with('nomina')->findOrFail($id);
        if ($linea->nomina->pagada()) {
            $this->fallo('Esta nómina ya está pagada: no se puede cambiar.');

            return;
        }
        $cuenta = (string) $cuentaId === '' ? null : CalculoNomina::cuentasDePago()->firstWhere('id', (int) $cuentaId);
        if ((string) $cuentaId !== '' && ! $cuenta) {
            $this->fallo('Elige una cuenta de débito o efectivo que esté activa.');
            $this->version++;

            return;
        }
        $linea->update(['cuenta_id' => $cuenta?->id]);
        $this->mensaje = $cuenta ? "A {$linea->nombre} se le paga esta semana desde {$cuenta->nombre}." : "A {$linea->nombre} se le paga desde su cuenta de siempre.";
    }

    /** "Pagar a todos desde…": la cuenta de esta semana para todas las personas (depende de en cuál haya dinero). */
    public function pagarTodosDesde(mixed $cuentaId): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $n = CalculoNomina::semana((int) $this->anio, (int) $this->semana);
        if ($n->pagada()) {
            $this->fallo('Esta nómina ya está pagada: no se puede cambiar.');

            return;
        }
        $cuenta = CalculoNomina::cuentasDePago()->firstWhere('id', (int) $cuentaId);
        if (! $cuenta) {
            $this->fallo('Elige una cuenta de débito o efectivo que esté activa.');

            return;
        }
        $n->lineas()->update(['cuenta_id' => $cuenta->id]);
        $this->version++;
        $this->mensaje = "Esta semana se le paga a todos desde {$cuenta->nombre}. Si a alguien le pagas de otra, cámbialo en su nombre.";
    }

    public function volverACalcular(int $id): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $linea = NominaLinea::with('nomina')->findOrFail($id);
        if ($linea->nomina->pagada() || $linea->nomina->tipo !== 'semanal') {
            $this->fallo('Esta nómina ya está pagada: no se puede cambiar.');

            return;
        }
        $linea->update(['comision_manual' => false]);
        CalculoNomina::recalcular($linea->nomina);
    }

    /** Pone en la línea el descuento de préstamo que se sugiere (el semanal, sin pasar del saldo). */
    public function usarDescuentoSugerido(int $id): void
    {
        $linea = NominaLinea::findOrFail($id);
        $this->guardarLinea($id, 'descuento_prestamo', $linea->user_id ? CalculoNomina::descuentoSugerido($linea->user_id) : 0);
        $this->version++;
    }

    public function agregarPersona(): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $n = CalculoNomina::semana((int) $this->anio, (int) $this->semana);
        $u = $this->usuariosActivos()->find((int) $this->agregarUsuario);
        if ($n->pagada() || ! $u || $n->lineas()->where('user_id', $u->id)->exists()) {
            $this->fallo('Elige a la persona que quieres agregar.');

            return;
        }
        $n->lineas()->create(['user_id' => $u->id, 'nombre' => $u->nombreReal(), 'sueldo_base' => $u->sueldo_base,
            'comision_pct' => $u->comision_pct, 'descuento_prestamo' => CalculoNomina::descuentoSugerido($u->id)]);
        CalculoNomina::recalcular($n);
        $this->agregarUsuario = '';
        $this->mensaje = "Se agregó a {$u->nombreReal()} a la nómina de esta semana.";
    }

    public function quitarLinea(int $id): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $linea = NominaLinea::with(['nomina', 'user'])->findOrFail($id);
        if ($linea->nomina->pagada() || $linea->nomina->tipo !== 'semanal') {
            $this->fallo('Esta nómina ya está pagada: no se puede cambiar.');

            return;
        }
        $nomina = $linea->nomina;
        $linea->delete();
        CalculoNomina::recalcular($nomina);
        $this->mensaje = "Se quitó a {$linea->nombre} de esta semana.";
    }

    public function registrarPago(): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $n = CalculoNomina::semana((int) $this->anio, (int) $this->semana);
        if ($n->pagada()) {
            $this->fallo('Esta nómina ya estaba pagada.');

            return;
        }
        if ($n->lineas()->doesntExist()) {
            $this->fallo('No hay nadie en la nómina de esta semana. Agrega personas primero.');

            return;
        }
        CalculoNomina::registrar($n);
        $this->mensaje = 'Pago registrado: la semana '.$n->semana.' quedó cerrada por '.self::dinero($n->fresh()->total()).'.';
    }

    public function reabrirSemana(): void
    {
        $this->autorizar(soloAdmin: true);
        $this->limpiarMensajes();
        $n = NominaPago::where(['tipo' => 'semanal', 'anio' => (int) $this->anio, 'semana' => (int) $this->semana])->first();
        $this->reabrir($n);
    }

    private function reabrir(?NominaPago $n): void
    {
        if (! $n || ! $n->pagada()) {
            $this->fallo('Esa nómina no está pagada.');

            return;
        }
        if ($n->origen === 'sheets') {
            $this->fallo('Esta nómina viene del Sheets: no se puede reabrir.');

            return;
        }
        CalculoNomina::reabrir($n);
        $this->mensaje = 'La nómina se reabrió: se soltaron las ventas y se quitaron los descuentos de préstamo que había hecho.';
    }

    /** Usuarios que pueden estar en una nómina (activos, no eliminados, no de Ned Tracking). */
    private function usuariosActivos(): Builder
    {
        return User::query()->where('activo', true)->whereNull('eliminado_at')->where('rol', '!=', 'nedtracking')->orderBy('name');
    }

    /** @return array<string, mixed> */
    private function datosSemana(): array
    {
        $n = CalculoNomina::semana((int) $this->anio, (int) $this->semana);
        $lineas = $n->lineas()->with(['user.cuentaNomina', 'cuenta'])->get();
        $cobros = $n->pagada() ? Venta::query()->where('nomina_id', $n->id) : CalculoNomina::cobros($n->anio, $n->semana);
        $inicio = Semanas::rangoCobro($n->anio, $n->semana)[0];
        $userIds = $lineas->pluck('user_id')->filter()->all();
        $saldos = [];
        foreach (Prestamo::activos()->whereIn('user_id', $userIds)->get() as $p) {
            $saldos[$p->user_id] = ($saldos[$p->user_id] ?? 0) + $p->saldo();
        }

        return [
            'n' => $n, 'lineas' => $lineas, 'saldos' => $saldos, 'total' => round((float) $lineas->sum('total'), 2),
            // De qué cuenta sale el dinero de cada quien y cuánto sale de cada una (para saber cuánto tener en BBVA, Hey…).
            'cuentasPago' => CalculoNomina::cuentasDePago(), 'porCuenta' => $n->pagada() && $lineas->contains(fn ($l) => ! $l->cuenta_id) ? collect() : CalculoNomina::porCuenta($n),
            'cuentaGeneral' => CalculoNomina::cuentaGeneral(),
            // Para decidir de cuál pagar: lo que tenía cada cuenta en el último corte de Mis finanzas (solo quien lo puede ver).
            'saldosCuentas' => auth()->user()->puede('finanzas') ? Patrimonio::ultimosSaldos() : collect(),
            'cobrosN' => (clone $cobros)->count(),
            'cobros' => $this->verCobros ? (clone $cobros)->orderBy('fecha_pago')->orderBy('id')->get() : collect(),
            'inicioCobros' => $inicio,
            // Quitados de la comisión (para poder regresarlos). Arriba solo los que se quitaron desde que empezó esta semana; los de
            // semanas anteriores van plegados: como nunca se comisionan, antes salían en TODAS las semanas siguientes.
            ...$this->quitados($n),
            'pctTotal' => round((float) $lineas->sum('comision_pct'), 2),
            'disponibles' => $n->pagada() ? collect() : $this->usuariosActivos()->whereNotIn('id', $userIds)->get(['id', 'name']),
            'desde' => CalculoNomina::desde(),
        ];
    }

    // ================================================================================== PRÉSTAMOS

    public function abrirNuevoPrestamo(): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $this->nuevoPrestamo = true;
        $this->abonoPara = null;
        $this->pUsuario = $this->pMonto = $this->pDescuento = $this->pNota = '';
        $this->pFecha = today()->toDateString();
    }

    public function cancelarPrestamo(): void
    {
        $this->nuevoPrestamo = false;
        $this->abonoPara = null;
        $this->resetErrorBag();
    }

    public function guardarPrestamo(): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $this->validate([
            'pUsuario' => ['required'], 'pMonto' => ['required'], 'pFecha' => ['required', 'date', 'after:1999-12-31', 'before:2101-01-01'],
            'pNota' => ['nullable', 'string', 'max:500'],
        ], [
            'pUsuario.required' => 'Elige a la persona.', 'pMonto.required' => 'Escribe el monto del préstamo.', 'pFecha.required' => 'Pon la fecha del préstamo.',
            'pFecha.date' => 'La fecha no es válida.', 'pFecha.after' => 'La fecha no es válida.', 'pFecha.before' => 'La fecha no es válida.',
            'pNota.max' => 'La nota es muy larga.',
        ]);
        $u = User::where('en_nomina', true)->whereNull('eliminado_at')->find((int) $this->pUsuario);
        $monto = self::numero($this->pMonto);
        $descuento = trim($this->pDescuento) === '' ? 0.0 : self::numero($this->pDescuento);
        if (! $u) {
            $this->addError('pUsuario', 'Elige a una persona que esté en la nómina.');

            return;
        }
        if ($monto === null || $monto <= 0 || $monto > 1000000) {
            $this->addError('pMonto', 'El monto tiene que ser mayor a cero y no pasar de $1,000,000.');

            return;
        }
        if ($descuento === null || $descuento < 0 || $descuento > $monto) {
            $this->addError('pDescuento', 'El descuento semanal tiene que ser un número y no pasar del monto.');

            return;
        }
        Prestamo::create(['user_id' => $u->id, 'nombre' => $u->nombreReal(), 'monto' => $monto, 'fecha' => $this->pFecha,
            'descuento_semanal' => $descuento, 'notas' => trim($this->pNota) ?: null]);
        $this->nuevoPrestamo = false;
        $this->mensaje = 'Préstamo de '.self::dinero($monto)." para {$u->nombreReal()} guardado."
            .($descuento > 0 ? ' El descuento se sugiere en las semanas nuevas; en la semana abierta usa "Sugerido" en su línea.' : '');
    }

    public function guardarDescuentoSemanal(int $id, mixed $valor): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $p = Prestamo::findOrFail($id);
        $numero = trim((string) $valor) === '' ? 0.0 : self::numero($valor);
        if ($numero === null || $numero < 0 || $numero > (float) $p->monto) {
            $this->fallo('El descuento semanal tiene que ser un número y no pasar del monto del préstamo.');

            return;
        }
        $p->update(['descuento_semanal' => $numero]);
        $this->mensaje = 'Descuento semanal de '.self::dinero($numero)." guardado para {$p->nombre}.";
    }

    public function abrirAbono(int $id): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $this->nuevoPrestamo = false;
        $this->abonoPara = $id;
        $this->aMonto = $this->aNota = '';
        $this->aFecha = today()->toDateString();
    }

    public function guardarAbono(): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $this->validate([
            'aMonto' => ['required'], 'aFecha' => ['required', 'date', 'after:1999-12-31', 'before:2101-01-01'], 'aNota' => ['nullable', 'string', 'max:255'],
        ], [
            'aMonto.required' => 'Escribe el monto del abono.', 'aFecha.required' => 'Pon la fecha del abono.', 'aFecha.date' => 'La fecha no es válida.',
            'aFecha.after' => 'La fecha no es válida.', 'aFecha.before' => 'La fecha no es válida.', 'aNota.max' => 'La nota es muy larga.',
        ]);
        $p = Prestamo::activos()->find($this->abonoPara);
        if (! $p) {
            $this->fallo('Ese préstamo ya está saldado.');
            $this->abonoPara = null;

            return;
        }
        $monto = self::numero($this->aMonto);
        $saldo = $p->saldo();
        if ($monto === null || $monto <= 0) {
            $this->addError('aMonto', 'El abono tiene que ser un número mayor a cero.');

            return;
        }
        if ($monto > $saldo + 0.004) {
            $this->addError('aMonto', 'El abono no puede pasar del saldo ('.self::dinero($saldo).').');

            return;
        }
        PrestamoAbono::create(['prestamo_id' => $p->id, 'nomina_id' => null, 'fecha' => $this->aFecha, 'monto' => $monto,
            'nota' => trim($this->aNota) ?: 'Abono suelto', 'created_at' => now()]);
        $p->revisarSaldo();
        $this->abonoPara = null;
        $this->mensaje = 'Abono de '.self::dinero($monto)." guardado. A {$p->nombre} le quedan ".self::dinero($p->saldo()).'.';
    }

    /** Quita un abono suelto capturado por error (los de nómina se quitan reabriendo esa nómina). */
    public function quitarAbono(int $id): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $a = PrestamoAbono::with('prestamo')->whereNull('nomina_id')->find($id);
        if (! $a) {
            $this->fallo('Ese abono salió de una nómina: para quitarlo se reabre esa nómina (Historial → Semana).');

            return;
        }
        $a->delete();
        $a->prestamo->revisarSaldo();
        $this->mensaje = 'Se quitó el abono de '.self::dinero($a->monto).'.';
    }

    /** @return Collection<int, array{nombre: string, activos: Collection, saldados: Collection, saldo: float}> */
    private function datosPrestamos(): Collection
    {
        return Prestamo::with(['abonos.nomina', 'user'])->orderBy('fecha')->orderBy('id')->get()
            ->map(fn (Prestamo $p) => ['p' => $p, 'saldo' => $p->saldo()])
            ->groupBy(fn ($x) => $x['p']->user_id ? 'u'.$x['p']->user_id : 'n'.mb_strtoupper($x['p']->nombre))
            ->map(fn (Collection $g) => [
                'nombre' => $g->first()['p']->user?->nombreReal() ?? $g->first()['p']->nombre,
                'activos' => $g->filter(fn ($x) => $x['p']->saldado_at === null)->values(),
                'saldados' => $g->filter(fn ($x) => $x['p']->saldado_at !== null)->values(),
                'saldo' => round((float) $g->filter(fn ($x) => $x['p']->saldado_at === null)->sum('saldo'), 2),
            ])->sortBy(fn ($x) => [$x['activos']->isEmpty() ? 1 : 0, mb_strtolower($x['nombre'])])->values();
    }

    // ================================================================================== REPARTO

    public function guardarGastos(mixed $valor): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $n = $this->repartoAbierto();
        if (! $n) {
            return;
        }
        $gastos = trim((string) $valor) === '' ? 0.0 : self::numero($valor);
        if ($gastos === null || $gastos < 0 || $gastos > 99999999) {
            $this->fallo('Los gastos tienen que ser un número (cero o más).');

            return;
        }
        CalculoNomina::reparto($this->repAnio, $this->repMes, $gastos);
    }

    /** "Usar este monto": los gastos MASUME del mes que ya están capturados en Gastos. */
    public function usarGastosDelModulo(): void
    {
        $this->guardarGastos(Gasto::delMesParaReparto($this->repAnio, $this->repMes));
    }

    public function guardarMontoReparto(mixed $valor): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $n = $this->repartoAbierto();
        if (! $n) {
            return;
        }
        $linea = $n->lineas()->first();
        if (trim((string) $valor) === '') {
            $this->volverACalcularReparto();

            return;
        }
        $monto = self::numero($valor);
        if (! $linea || $monto === null || $monto < 0 || $monto > 99999999) {
            $this->fallo('El monto tiene que ser un número (cero o más).');

            return;
        }
        $linea->comision = $monto;
        $linea->comision_manual = true;
        $linea->calcularTotal();
        $linea->save();
    }

    public function volverACalcularReparto(): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $n = $this->repartoAbierto();
        if ($n && ($linea = $n->lineas()->first())) {
            $linea->update(['comision_manual' => false]);
            CalculoNomina::reparto($this->repAnio, $this->repMes);
        }
    }

    /** El reparto del mes elegido si todavía no está pagado (si ya lo está, avisa y regresa null). */
    private function repartoAbierto(): ?NominaPago
    {
        $n = CalculoNomina::reparto($this->repAnio, $this->repMes);
        if ($n->pagada()) {
            $this->fallo('Este reparto ya está pagado: no se puede cambiar.');

            return null;
        }

        return $n;
    }

    public function registrarReparto(): void
    {
        $this->autorizar();
        $this->limpiarMensajes();
        $n = $this->repartoAbierto();
        if (! $n) {
            return;
        }
        CalculoNomina::registrar($n);
        $this->mensaje = 'Reparto registrado: '.self::MESES[$this->repMes].' '.$this->repAnio.' quedó cerrado por '.self::dinero($n->fresh()->total()).'.';
    }

    public function reabrirReparto(): void
    {
        $this->autorizar(soloAdmin: true);
        $this->limpiarMensajes();
        $this->reabrir(NominaPago::where(['tipo' => 'reparto', 'anio' => $this->repAnio, 'mes' => $this->repMes])->first());
    }

    // ================================================================================== HISTORIAL

    public function alternarNomina(int $id): void
    {
        $this->autorizar();
        $this->abierta = $this->abierta === $id ? null : $id;
    }

    /** @return array<string, mixed> */
    private function datosHistorial(): array
    {
        $anios = NominaPago::where('estatus', 'pagada')->distinct()->orderByDesc('anio')->pluck('anio')->map(fn ($a) => (int) $a)->all();
        $anio = $this->histAnio === 'todos' ? null : (int) $this->histAnio;
        $mes = (int) $this->histMes >= 1 && (int) $this->histMes <= 12 ? (int) $this->histMes : null;

        $nominas = NominaPago::where('estatus', 'pagada')->with(['lineas.user', 'user'])
            ->when($anio !== null, fn ($q) => $q->where('anio', $anio))
            ->orderByDesc('hasta')->orderByDesc('id')->limit(400)->get()
            // El mes de una semana es el de su sábado (el día de pago); el del reparto, su mes.
            ->filter(fn (NominaPago $n) => $mes === null || (int) $n->hasta->month === $mes)->values();

        $personas = [];
        foreach ($nominas as $n) {
            foreach ($n->lineas as $l) {
                $clave = $l->user_id ? 'u'.$l->user_id : 'n'.mb_strtoupper($l->nombre);
                $p = &$personas[$clave];
                $p ??= ['nombre' => $l->user?->nombreReal() ?? $l->nombre, 'sueldo' => 0.0, 'comisiones' => 0.0, 'reparto' => 0.0, 'bonos' => 0.0, 'total' => 0.0, 'pagos' => 0];
                $p['pagos']++;
                $p['total'] += (float) $l->total;
                if ($n->tipo === 'reparto') {
                    $p['reparto'] += (float) $l->total;
                } else {
                    $p['sueldo'] += (float) $l->sueldo_base;
                    // Una comisión negativa (así venía en el Sheets) era un descuento.
                    $p['comisiones'] += max(0, (float) $l->comision);
                    $p['bonos'] += min(0, (float) $l->comision) + (float) $l->ajuste - (float) $l->descuento_prestamo;
                }
                unset($p);
            }
        }
        $personas = collect($personas)->sortByDesc('total')->values();

        return [
            'nominas' => $nominas, 'personas' => $personas, 'aniosHistorial' => $anios,
            'totalPagado' => round((float) $personas->sum('total'), 2),
        ];
    }

    // ================================================================================== EMPLEADOS (admin)

    private function cargarEmpleados(): void
    {
        $this->emp = [];
        foreach ($this->usuariosActivos()->get() as $u) {
            $this->emp[$u->id] = [
                'en_nomina' => (bool) $u->en_nomina, 'sueldo_base' => (string) ($u->sueldo_base ?? 0), 'comision_pct' => (string) ($u->comision_pct ?? 0),
                'fecha_ingreso' => $u->fecha_ingreso?->toDateString() ?? '', 'trabaja_sabados' => (bool) $u->trabaja_sabados,
                'cuenta_nomina_id' => (string) ($u->cuenta_nomina_id ?? ''),
            ];
        }
    }

    public function guardarEmpleado(int $id): void
    {
        $this->autorizar(soloAdmin: true);
        $this->limpiarMensajes();
        $u = $this->usuariosActivos()->findOrFail($id);
        $this->validate([
            "emp.{$id}.sueldo_base" => ['required', 'numeric', 'min:0', 'max:999999'],
            "emp.{$id}.comision_pct" => ['required', 'numeric', 'min:0', 'max:100'],
            "emp.{$id}.fecha_ingreso" => ['nullable', 'date', 'after:1999-12-31', 'before:2101-01-01'],
        ], [
            '*.sueldo_base.required' => 'Escribe el sueldo base.', '*.sueldo_base.numeric' => 'El sueldo tiene que ser un número.',
            '*.sueldo_base.min' => 'El sueldo no puede ser negativo.', '*.sueldo_base.max' => 'El sueldo es demasiado grande.',
            '*.comision_pct.required' => 'Escribe el porcentaje (0 si no tiene).', '*.comision_pct.numeric' => 'El porcentaje tiene que ser un número.',
            '*.comision_pct.min' => 'El porcentaje no puede ser negativo.', '*.comision_pct.max' => 'El porcentaje no puede pasar de 100.',
            '*.fecha_ingreso.date' => 'La fecha de ingreso no es válida.', '*.fecha_ingreso.after' => 'La fecha de ingreso no es válida.',
            '*.fecha_ingreso.before' => 'La fecha de ingreso no es válida.',
        ]);
        $d = $this->emp[$id];
        $cuentaId = (string) ($d['cuenta_nomina_id'] ?? '');
        $cuenta = $cuentaId === '' ? null : CalculoNomina::cuentasDePago()->firstWhere('id', (int) $cuentaId);
        if ($cuentaId !== '' && ! $cuenta && (int) $cuentaId !== (int) $u->cuenta_nomina_id) {
            $this->addError("emp.{$id}.cuenta_nomina_id", 'Elige una cuenta de débito o efectivo que esté activa.');

            return;
        }
        $u->update(['cuenta_nomina_id' => $cuentaId === '' ? null : (int) $cuentaId,
            'en_nomina' => (bool) $d['en_nomina'], 'sueldo_base' => round((float) $d['sueldo_base'], 2),
            'comision_pct' => round((float) $d['comision_pct'], 2), 'fecha_ingreso' => ($d['fecha_ingreso'] ?? '') !== '' ? $d['fecha_ingreso'] : null,
            'trabaja_sabados' => (bool) $d['trabaja_sabados']]);
        $this->mensaje = "Se guardaron los datos de {$u->nombreReal()}. Los cambios aplican a las semanas nuevas; en la semana abierta se cambian en su línea.";
    }

    /** Suma de los % de comisión de quienes están en nómina y tienen comisión (para avisar si no da 100). */
    private function sumaPorcentajes(): float
    {
        $suma = 0.0;
        foreach ($this->emp as $d) {
            if (! empty($d['en_nomina']) && is_numeric($d['comision_pct']) && (float) $d['comision_pct'] > 0) {
                $suma += (float) $d['comision_pct'];
            }
        }

        return round($suma, 2);
    }

    // ================================================================================== PINTAR

    public function render()
    {
        $this->autorizar();
        $this->arreglarPestana();
        $datos = ['esAdmin' => $this->esAdmin(), 'pestanas' => collect(self::PESTANAS)->except($this->esAdmin() ? [] : ['empleados'])->all()];

        if ($this->ver === 'semana') {
            $hoy = Semanas::deFecha(today());
            $primero = 2026;
            $datos += $this->datosSemana() + [
                'anios' => range($primero, max($hoy[0], (int) $this->anio, $primero)),
                'semanasDelAnio' => range(1, Semanas::delAnio((int) $this->anio)),
                'esActual' => [(int) $this->anio, (int) $this->semana] === $hoy,
            ];
        } elseif ($this->ver === 'prestamos') {
            $datos += [
                'grupos' => $this->datosPrestamos(),
                'enNomina' => User::where('en_nomina', true)->whereNull('eliminado_at')->orderBy('name')->get(['id', 'name']),
                'abonoPrestamo' => $this->abonoPara ? Prestamo::find($this->abonoPara) : null,
            ];
        } elseif ($this->ver === 'reparto') {
            $n = CalculoNomina::reparto($this->repAnio, $this->repMes);
            $neta = max(0, round((float) $n->utilidad - (float) $n->gastos, 2));
            $datos += [
                'rep' => $n, 'repLinea' => $n->lineas()->with(['cuenta', 'user.cuentaNomina'])->first(), 'neta' => $neta, 'retenido' => round($neta - (float) $n->bolsa, 2),
                'aniosReparto' => range(2025, max(today()->year, $this->repAnio)),
                'gastosModulo' => Gasto::delMesParaReparto($this->repAnio, $this->repMes),
                'cuentasPago' => CalculoNomina::cuentasDePago(), 'cuentaGeneral' => CalculoNomina::cuentaGeneral(),
            ];
        } elseif ($this->ver === 'historial') {
            $datos += $this->datosHistorial();
        } elseif ($this->ver === 'empleados') {
            $datos += ['usuarios' => $this->usuariosActivos()->get(['id', 'name', 'rol', 'cuenta_nomina_id']), 'sumaPct' => $this->sumaPorcentajes(),
                'cuentasPago' => CalculoNomina::cuentasDePago(), 'cuentaGeneral' => CalculoNomina::cuentaGeneral(),
                'cuentasInactivas' => Cuenta::whereIn('id', $this->usuariosActivos()->whereNotNull('cuenta_nomina_id')->pluck('cuenta_nomina_id'))
                    ->whereNotIn('id', CalculoNomina::cuentasDePago()->pluck('id'))->get(['id', 'nombre'])];
        }

        return view('livewire.finanzas.nomina', $datos);
    }
}
