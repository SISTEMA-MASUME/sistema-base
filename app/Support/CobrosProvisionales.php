<?php

namespace App\Support;

use App\Models\Cliente;
use App\Models\ComplementoPago;
use App\Models\Cuenta;
use App\Models\DepositoCobro;
use App\Models\DepositoEvento;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CobrosProvisionales
{
    public static function autorizar(User $usuario): void
    {
        $u = $usuario->fresh();
        abort_unless($u && $u->activo && ! $u->eliminado_at && $u->puede('ventas'), 403);
    }

    public static function centavos(mixed $importe): int
    {
        return (int) round((float) $importe * 100);
    }

    public static function crear(array $datos, User $usuario): DepositoCobro
    {
        self::autorizar($usuario);
        $d = Validator::make($datos, [
            'clave' => ['required', 'uuid'], 'cliente_id' => ['required', 'integer', 'exists:clientes,id'],
            'cuenta_id' => ['required', 'integer', 'exists:cuentas,id'],
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.CalculoNomina::desde(), 'before_or_equal:'.today()->toDateString()],
            'importe' => ['required', 'numeric', 'min:0.01', 'max:99999999999', 'decimal:0,2'],
            'referencia' => ['nullable', 'string', 'max:120'],
            'facturas' => ['required', 'array', 'min:1', 'max:200'],
            'facturas.*' => ['required', 'integer', 'distinct', 'exists:ventas,id'],
        ], ['fecha.after_or_equal' => 'La fecha debe estar dentro del periodo de comisiones del sistema.',
            'fecha.before_or_equal' => 'El depósito debe haber sido recibido; no uses una fecha futura.'])->validate();

        return DB::transaction(function () use ($d, $usuario) {
            $cliente = Cliente::whereKey($d['cliente_id'])->lockForUpdate()->firstOrFail();
            if (! preg_match('/^[A-ZÑ&0-9]{12,13}$/u', (string) $cliente->rfc)
                || in_array($cliente->rfc, ['XAXX010101000', 'XEXX010101000'], true)) {
                ComplementosSicar::error('El cliente necesita su RFC real para identificar sus complementos.');
            }
            $cuenta = Cuenta::whereKey($d['cuenta_id'])->where('activa', true)->where('de', 'masume')
                ->whereIn('tipo', ['debito', 'efectivo'])->first();
            if (! $cuenta) {
                ComplementosSicar::error('Elige una cuenta receptora activa de MASUME (débito o efectivo).');
            }
            $ids = array_map('intval', $d['facturas']);
            sort($ids);
            $anterior = DepositoCobro::where('clave', $d['clave'])->lockForUpdate()->first();
            if ($anterior) {
                $originales = $anterior->facturas()->wherePivot('original', true)->orderBy('ventas.id')->pluck('ventas.id')->all();
                if ($originales !== $ids || $anterior->cliente_id !== $cliente->id || $anterior->cuenta_id !== $cuenta->id
                    || $anterior->fecha->toDateString() !== $d['fecha'] || self::centavos($anterior->importe) !== self::centavos($d['importe'])
                    || ($anterior->referencia ?? '') !== trim($d['referencia'] ?? '')) {
                    ComplementosSicar::error('Esta operación ya se registró con otros datos. Recarga antes de intentar otro depósito.');
                }

                return $anterior;
            }
            $ventas = Venta::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($ventas->count() !== count($ids)) {
                ComplementosSicar::error('Una factura ya no está disponible.');
            }
            foreach ($ventas as $v) {
                if ($v->cliente_id !== $cliente->id || $v->tipo !== 'factura' || $v->estatus !== 'pendiente'
                    || $v->deposito_cobro_id || $v->reemplazadaPor()->exists() || $v->factura_sustituta) {
                    ComplementosSicar::error('La factura '.$v->folio.' no está pendiente y disponible para este cliente.');
                }
            }
            if ($ventas->sum(fn ($v) => self::centavos($v->total)) !== self::centavos($d['importe'])) {
                ComplementosSicar::error('Las facturas completas seleccionadas deben sumar exactamente el depósito.');
            }
            $dep = DepositoCobro::create(['clave' => $d['clave'], 'cliente_id' => $cliente->id, 'cuenta_id' => $cuenta->id,
                'fecha' => $d['fecha'], 'importe' => $d['importe'], 'referencia' => trim($d['referencia'] ?? '') ?: null,
                'estatus' => 'provisional', 'user_id' => $usuario->id]);
            foreach ($ventas as $v) {
                $dep->facturas()->attach($v->id, ['importe' => $v->total, 'original' => true, 'activa' => true]);
                self::pagar($v, $dep, $dep->fecha->toDateString(), 'Depósito provisional '.$dep->folio());
            }
            self::evento($dep, null, $usuario, 'alta_provisional', ['importe' => $dep->importe, 'fecha' => $d['fecha'],
                'cuenta' => $cuenta->nombre, 'facturas' => $ventas->pluck('folio')->all()]);

            return $dep;
        });
    }

    /** Comparación que también se ejecuta de nuevo bajo bloqueo al confirmar; nunca confiar en la vista previa. */
    public static function comparar(DepositoCobro $dep, ComplementoPago $rep, bool $bloquear = false): array
    {
        if ($rep->cancelado || ($rep->requiere_revision && ($rep->deposito_id !== $dep->id || $dep->estatus !== 'revisar'))) {
            ComplementosSicar::error('El complemento está cancelado o cambió. Carga un complemento vigente y revisa la incidencia.');
        }
        if ($rep->deposito_id && $rep->deposito_id !== $dep->id) {
            ComplementosSicar::error('Este complemento ya se vinculó con otro depósito.');
        }
        if ($dep->id && $rep->estatus === 'independiente') {
            ComplementosSicar::error('Este complemento ya se confirmó como otro depósito. No puede aplicarse de nuevo.');
        }
        if (! in_array($dep->estatus, ['provisional', 'revisar', 'conciliado'], true)
            || $dep->cliente->rfc !== $rep->rfc || $rep->moneda !== 'MXN'
            || self::centavos($dep->importe) !== self::centavos($rep->importe)) {
            ComplementosSicar::error('El cliente, moneda o importe del complemento no corresponde al depósito.');
        }
        $ventas = [];
        foreach ($rep->documentos as $doc) {
            $q = Venta::query();
            if ($doc['uuid']) {
                $q->whereRaw('UPPER(uuid) = ?', [strtoupper($doc['uuid'])]);
            } else {
                $q->where('sicar_id', $doc['sicar_id']);
            }
            $v = ($bloquear ? $q->lockForUpdate() : $q)->get();
            if ($v->count() !== 1) {
                ComplementosSicar::error('No pude identificar de forma única la factura '.$doc['folio'].'. Sincroniza SICAR y vuelve a revisar.');
            }
            $v = $v->first();
            if ($v->tipo !== 'factura' || $v->estatus === 'cancelada' || $v->cliente_id !== $dep->cliente_id
                || $v->reemplazadaPor()->exists() || $v->factura_sustituta || isset($ventas[$v->id])
                || ($v->deposito_cobro_id && $v->deposito_cobro_id !== $dep->id)) {
                ComplementosSicar::error('La factura '.$v->folio.' está cancelada, sustituida o pertenece a otro cliente/depósito.');
            }
            if ($doc['moneda'] !== 'MXN' || self::centavos($doc['saldo_insoluto']) !== 0
                || self::centavos($doc['importe_pagado']) !== self::centavos($v->total)
                || self::centavos($doc['saldo_anterior']) !== self::centavos($v->total)) {
                ComplementosSicar::error('La factura '.$v->folio.' tiene abonos parciales o un importe distinto. Este módulo admite solo facturas completas en MXN.');
            }
            if ($v->estatus === 'pagada' && ! $v->deposito_cobro_id && $v->pago_referencia !== 'REP '.$rep->folio) {
                ComplementosSicar::error('La factura '.$v->folio.' ya tiene otro cobro registrado; requiere revisión.');
            }
            $ventas[$v->id] = $v;
        }
        if (array_sum(array_map(fn ($v) => self::centavos($v->total), $ventas)) !== self::centavos($dep->importe)) {
            ComplementosSicar::error('El complemento no aplica el importe completo a las facturas seleccionadas.');
        }
        $actuales = $dep->facturas()->withTrashed()->wherePivot('activa', true)->orderBy('ventas.id');
        $actuales = ($bloquear ? $actuales->lockForUpdate() : $actuales)->get();
        foreach ($actuales as $v) {
            if ($v->trashed() || $v->deposito_cobro_id !== $dep->id || $v->estatus !== 'pagada'
                || self::centavos($v->total) !== self::centavos($v->pivot->importe) || $v->cliente_id !== $dep->cliente_id
                || $v->reemplazadaPor()->exists() || $v->factura_sustituta) {
                ComplementosSicar::error('La factura '.$v->folio.' cambió después del registro. Revisa la incidencia antes de conciliar.');
            }
        }

        return ['ventas' => collect($ventas), 'actuales' => $actuales,
            'quitar' => $actuales->whereNotIn('id', array_keys($ventas))->values(),
            'agregar' => collect($ventas)->whereNotIn('id', $actuales->pluck('id'))->values(),
            'fecha_distinta' => $dep->fecha->toDateString() !== $rep->fecha_pago->toDateString()];
    }

    public static function confirmar(int $depositoId, int $complementoId, string $motivo, User $usuario): DepositoCobro
    {
        self::autorizar($usuario);
        Validator::make(['motivo' => trim($motivo)], ['motivo' => ['required', 'string', 'min:10', 'max:1000']],
            ['motivo.min' => 'Escribe una explicación de al menos 10 caracteres.'])->validate();

        return DB::transaction(function () use ($depositoId, $complementoId, $motivo, $usuario) {
            $clienteId = DepositoCobro::findOrFail($depositoId)->cliente_id;
            Cliente::whereKey($clienteId)->lockForUpdate()->firstOrFail();
            $dep = DepositoCobro::lockForUpdate()->findOrFail($depositoId);
            $rep = ComplementoPago::lockForUpdate()->findOrFail($complementoId);
            if ($dep->estatus === 'conciliado') {
                if ($dep->complemento_id === $rep->id && ! $rep->cancelado && ! $rep->requiere_revision) {
                    return $dep;
                }
                ComplementosSicar::error('El depósito ya tiene un complemento confirmado.');
            }
            // Bloquear todas las ventas involucradas en el mismo orden que Nómina.
            Venta::where('cliente_id', $clienteId)->orderBy('id')->lockForUpdate()->get();
            $c = self::comparar($dep, $rep, true);
            foreach ($c['quitar'] as $v) {
                self::deshacer($v, $dep);
                $dep->facturas()->updateExistingPivot($v->id, ['activa' => false]);
            }
            foreach ($c['ventas'] as $v) {
                $dep->facturas()->syncWithoutDetaching([$v->id => ['importe' => $v->total, 'activa' => true]]);
                self::pagar($v, $dep, $rep->fecha_pago->toDateString(), 'REP '.$rep->folio, $rep->id);
            }
            $dep->update(['complemento_id' => $rep->id, 'estatus' => 'conciliado']);
            $rep->update(['deposito_id' => $dep->id, 'estatus' => 'vinculado', 'requiere_revision' => false]);
            self::evento($dep, $rep, $usuario, 'conciliacion', ['motivo' => trim($motivo),
                'quitar' => $c['quitar']->pluck('folio')->all(), 'agregar' => $c['agregar']->pluck('folio')->all(),
                'confirmadas' => $c['ventas']->pluck('folio')->all(), 'fecha_pago' => $rep->fecha_pago->toDateString()]);

            return $dep;
        });
    }

    public static function anular(int $id, string $motivo, User $usuario): void
    {
        self::autorizar($usuario);
        Validator::make(['motivo' => trim($motivo)], ['motivo' => ['required', 'min:10', 'max:1000']])->validate();
        DB::transaction(function () use ($id, $motivo, $usuario) {
            $clienteId = DepositoCobro::findOrFail($id)->cliente_id;
            Cliente::whereKey($clienteId)->lockForUpdate()->firstOrFail();
            $dep = DepositoCobro::lockForUpdate()->findOrFail($id);
            if ($dep->estatus === 'anulado') {
                return;
            }
            if ($dep->complemento_id || ! in_array($dep->estatus, ['provisional', 'revisar'])) {
                ComplementosSicar::error('Solo se puede anular una asignación provisional sin complemento confirmado.');
            }
            $ventas = Venta::withTrashed()->where('deposito_cobro_id', $id)->orderBy('id')->lockForUpdate()->get();
            foreach ($ventas as $v) {
                self::deshacer($v, $dep);
                $dep->facturas()->updateExistingPivot($v->id, ['activa' => false]);
            }
            $dep->update(['estatus' => 'anulado']);
            self::evento($dep, null, $usuario, 'anulacion', ['motivo' => trim($motivo), 'facturas' => $ventas->pluck('folio')->all()]);
        });
    }

    public static function revisar(int $id, string $motivo, User $usuario): void
    {
        self::autorizar($usuario);
        abort_unless($usuario->fresh()->esAdmin(), 403);
        Validator::make(['motivo' => trim($motivo)], ['motivo' => ['required', 'string', 'min:10', 'max:1000']])->validate();
        DB::transaction(function () use ($id, $motivo, $usuario) {
            $clienteId = DepositoCobro::findOrFail($id)->cliente_id;
            Cliente::whereKey($clienteId)->lockForUpdate()->firstOrFail();
            $dep = DepositoCobro::lockForUpdate()->findOrFail($id);
            if ($dep->estatus !== 'conciliado') {
                ComplementosSicar::error('Solo un depósito conciliado puede abrirse para revisión.');
            }
            $dep->update(['estatus' => 'revisar']);
            self::evento($dep, $dep->complemento, $usuario, 'revision_manual', ['motivo' => trim($motivo)]);
        });
    }

    /** El operador confirma que el REP pertenece a OTRO depósito recibido, no a los provisionales sugeridos. */
    public static function independiente(int $complementoId, string $motivo, User $usuario): void
    {
        self::autorizar($usuario);
        Validator::make(['motivo' => trim($motivo)], ['motivo' => ['required', 'string', 'min:10', 'max:1000']])->validate();
        DB::transaction(function () use ($complementoId, $motivo, $usuario) {
            $rfc = ComplementoPago::findOrFail($complementoId)->rfc;
            $clientes = Cliente::where('rfc', $rfc)->orderBy('id')->lockForUpdate()->get();
            if ($clientes->count() !== 1) {
                ComplementosSicar::error('El RFC debe identificar un solo cliente antes de registrar este cobro.');
            }
            $rep = ComplementoPago::lockForUpdate()->findOrFail($complementoId);
            if ($rep->estatus === 'independiente' && ! $rep->cancelado && ! $rep->requiere_revision) {
                return;
            }
            if ($rep->deposito_id) {
                ComplementosSicar::error('Este complemento ya está vinculado con un depósito.');
            }
            $dep = new DepositoCobro(['cliente_id' => $clientes->first()->id, 'fecha' => $rep->fecha_pago,
                'importe' => $rep->importe, 'estatus' => 'provisional']);
            $dep->id = 0;
            Venta::where('cliente_id', $dep->cliente_id)->orderBy('id')->lockForUpdate()->get();
            $c = self::comparar($dep, $rep, true);
            foreach ($c['ventas'] as $v) {
                $antes = $v->estatus;
                $fechaAntes = $v->fecha_pago?->toDateString();
                $v->update(['estatus' => 'pagada', 'fecha_pago' => $rep->fecha_pago->toDateString(), 'pago_referencia' => 'REP '.$rep->folio, 'complemento_pago_id' => $rep->id, 'pago_registrado_at' => now()]);
                $v->anotar('estatus', $antes, 'pagada');
                $v->anotar('fecha_pago', $fechaAntes, $rep->fecha_pago->toDateString());
            }
            $rep->update(['estatus' => 'independiente']);
            self::evento(null, $rep, $usuario, 'pago_independiente', ['motivo' => trim($motivo), 'importe' => $rep->importe,
                'facturas' => $c['ventas']->pluck('folio')->all()]);
        });
    }

    private static function pagar(Venta $v, DepositoCobro $dep, string $fecha, string $referencia, ?int $complementoId = null): void
    {
        $antes = $v->only(['estatus', 'fecha_pago', 'cuenta_id', 'pago_referencia', 'deposito_cobro_id']);
        $v->update(['estatus' => 'pagada', 'fecha_pago' => $fecha, 'cuenta_id' => $dep->cuenta_id, 'pago_registrado_at' => now(),
            'pago_referencia' => $referencia, 'deposito_cobro_id' => $dep->id, 'complemento_pago_id' => $complementoId]);
        foreach ($antes as $campo => $valor) {
            $v->anotar($campo, $valor instanceof \DateTimeInterface ? $valor->format('Y-m-d') : $valor,
                $campo === 'fecha_pago' ? $fecha : $v->{$campo});
        }
        // nomina_id y comision_excluida_* nunca se trasladan, borran o recalculan aquí.
    }

    private static function deshacer(Venta $v, DepositoCobro $dep): void
    {
        if ($v->deposito_cobro_id !== $dep->id || ! in_array($v->estatus, ['pagada', 'cancelada'])) {
            ComplementosSicar::error('La factura '.$v->folio.' cambió. No se puede quitar su cobro automáticamente.');
        }
        $antes = $v->only(['estatus', 'fecha_pago', 'cuenta_id', 'pago_referencia', 'deposito_cobro_id']);
        $v->update(['estatus' => $v->estatus === 'cancelada' ? 'cancelada' : 'pendiente', 'fecha_pago' => null, 'cuenta_id' => null, 'pago_registrado_at' => null,
            'pago_referencia' => null, 'deposito_cobro_id' => null, 'complemento_pago_id' => null]);
        foreach ($antes as $campo => $valor) {
            $v->anotar($campo, $valor instanceof \DateTimeInterface ? $valor->format('Y-m-d') : $valor, $v->{$campo});
        }
    }

    public static function evento(?DepositoCobro $dep, ?ComplementoPago $rep, User $usuario, string $accion, array $datos): void
    {
        DepositoEvento::create(['deposito_id' => $dep?->id, 'complemento_id' => $rep?->id, 'user_id' => $usuario->id,
            'accion' => $accion, 'datos' => $datos, 'created_at' => now()]);
    }

    /** El puente viejo también se frena por cliente: no convertir otro grupo en cobrado mientras se revisa el depósito. */
    public static function retenerPago(?int $clienteId, string $referencia): bool
    {
        if (! $clienteId || in_array($referencia, ['PUE', 'CONTADO'], true)) {
            return false;
        }

        return DepositoCobro::where('cliente_id', $clienteId)->whereIn('estatus', ['provisional', 'revisar'])->exists();
    }
}
