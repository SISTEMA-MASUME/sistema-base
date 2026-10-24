<?php

namespace App\Support;

use App\Models\Ajuste;
use App\Models\Cliente;
use App\Models\DepositoCobro;
use App\Models\Venta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Facturas, notas de venta y pagos que manda el programa de la PC de Daniel (lee SICAR en solo lectura).
 * Reglas para no pisar lo que se hace a mano en el sistema:
 * - Se liga por sicar_id; si no, por folio (así se juntan con las importadas del Sheets).
 * - Montos, fecha, UUID y cancelación: manda SICAR.
 * - Costo: SICAR solo lo cambia si nadie lo ajustó a mano (costo == el último que dio SICAR, o 0).
 * - Pago: si SICAR dice que ya quedó saldada y en el sistema sigue pendiente, se marca pagada con la fecha
 *   del complemento (o de la factura si es PUE/contado). Nunca regresa a pendiente una que se pagó a mano.
 * - Cliente: por RFC; si no, por nombre; si no existe se da de alta (son clientes reales facturados).
 */
final class SincronizacionVentasSicar
{
    public const ULTIMA = 'sicar_ventas_ultima_sync';

    public const MAXIMO = 500;

    private array $resumen = ['creadas' => 0, 'actualizadas' => 0, 'pagadas' => 0, 'canceladas' => 0, 'sin_cambios' => 0, 'ligadas_a_pedido' => 0, 'errores' => []];

    /** @var list<int> ventas creadas o cambiadas en este envío (para ligarlas a su pedido por la OC del comentario) */
    private array $tocadas = [];

    /** @var array<string, int>|null llave de nombre → cliente_id */
    private ?array $clientesPorNombre = null;

    /**
     * @param  list<array<string, mixed>>  $documentos
     * @return array{creadas: int, actualizadas: int, pagadas: int, canceladas: int, sin_cambios: int, errores: list<array{sicar_id: ?string, error: string}>}
     */
    public function aplicar(array $documentos): array
    {
        foreach ($documentos as $doc) {
            $sicarId = is_array($doc) ? (string) ($doc['sicar_id'] ?? '') : '';
            try {
                $error = is_array($doc) ? $this->validar($doc) : 'documento inválido';
                if ($error) {
                    $this->resumen['errores'][] = ['sicar_id' => $sicarId ?: null, 'error' => $error];

                    continue;
                }
                DB::transaction(fn () => $this->aplicarUno($doc));
            } catch (Throwable $e) {
                $registro = RegistroErrores::registrar($e);
                $this->resumen['errores'][] = ['sicar_id' => $sicarId ?: null, 'error' => 'error interno'.($registro ? " ({$registro->codigo})" : '')];
            }
        }
        Trazabilidad::completarLigasDeSicar();
        // La OC del cliente que viene en el comentario de SICAR liga la factura/nota con su pedido.
        // y la factura cuyo XML/PDF ya se subió al pedido (UUID o serie-folio).
        foreach (Venta::whereIn('id', array_unique($this->tocadas))->get() as $v) {
            try {
                $this->resumen['ligadas_a_pedido'] += ($v->comentario_sicar ? Trazabilidad::ligarPedidosPorOc($v) : 0)
                    + FacturaArchivo::alLlegarVenta($v);
            } catch (Throwable $e) {
                RegistroErrores::registrar($e);
            }
        }
        Ajuste::guardar(self::ULTIMA, now()->toIso8601String());

        return $this->resumen;
    }

    public static function ultimaSincronizacion(): ?Carbon
    {
        $valor = Ajuste::obtener(self::ULTIMA);

        return $valor ? Carbon::parse($valor) : null;
    }

    private function validar(array $d): ?string
    {
        if (trim((string) ($d['sicar_id'] ?? '')) === '' || mb_strlen((string) $d['sicar_id']) > 40) {
            return 'falta sicar_id';
        }
        if (trim((string) ($d['folio'] ?? '')) === '' || mb_strlen((string) $d['folio']) > 40) {
            return 'falta el folio';
        }
        if (! in_array($d['tipo'] ?? 'factura', ['factura', 'nota'], true)) {
            return 'tipo debe ser factura o nota';
        }
        foreach (['fecha', 'pagada_en'] as $campo) {
            if (($campo === 'fecha' || filled($d[$campo] ?? null)) && ! $this->fecha($d[$campo] ?? null)) {
                return "{$campo} inválida";
            }
        }
        foreach (['subtotal', 'iva', 'retencion', 'total', 'costo'] as $campo) {
            if (isset($d[$campo]) && $d[$campo] !== null && (! is_numeric($d[$campo]) || (float) $d[$campo] < 0 || (float) $d[$campo] > 999999999)) {
                return "{$campo} inválido";
            }
        }
        if (trim((string) ($d['cliente'] ?? '')) === '') {
            return 'falta el cliente';
        }

        return null;
    }

    private function fecha(mixed $valor): ?string
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $valor)) {
            return null;
        }
        try {
            return Carbon::parse(substr($valor, 0, 10))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function num(mixed $v): float
    {
        return round((float) ($v ?? 0), 2);
    }

    private function aplicarUno(array $d): void
    {
        $tipo = $d['tipo'] ?? 'factura';
        $folio = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string) $d['folio'])));
        if ($tipo === 'nota' && filled($d['facturada_en'] ?? null)) {
            $this->notaFacturada($d, $folio, mb_strtoupper(trim((string) $d['facturada_en'])));

            return;
        }
        $clienteId = $this->cliente($d)?->id;
        if ($clienteId) {
            Cliente::whereKey($clienteId)->lockForUpdate()->first();
        }
        $venta = Venta::withTrashed()->where('sicar_id', (string) $d['sicar_id'])->lockForUpdate()->first()
            ?? Venta::withTrashed()->where('folio_llave', Venta::llave($tipo, $folio))->lockForUpdate()->first();

        $subtotal = $this->num($d['subtotal'] ?? 0);
        $iva = array_key_exists('iva', $d) && $d['iva'] !== null ? $this->num($d['iva']) : round($subtotal * Precios::iva(), 2);
        $retencion = $this->num($d['retencion'] ?? 0);
        $datos = [
            'tipo' => $tipo,
            'folio' => $folio,
            'fecha' => $this->fecha($d['fecha']),
            'cliente_nombre' => mb_substr(Nombres::limpiar((string) $d['cliente']), 0, 255),
            'cliente_id' => $clienteId,
            'subtotal' => $subtotal,
            'iva' => $iva,
            'retencion' => $retencion,
            'total' => array_key_exists('total', $d) && $d['total'] !== null ? $this->num($d['total']) : round($subtotal + $iva - $retencion, 2),
            'metodo_pago' => in_array($d['metodo_pago'] ?? null, ['PUE', 'PPD'], true) ? $d['metodo_pago'] : null,
            'uuid' => filled($d['uuid'] ?? null) ? mb_substr((string) $d['uuid'], 0, 40) : null,
        ];
        if (isset($d['saldo']) && is_numeric($d['saldo'])) {
            $datos['saldo_sicar'] = $this->num($d['saldo']);
        }
        if (filled($d['concepto'] ?? null)) {
            $datos['concepto'] = mb_substr(trim((string) $d['concepto']), 0, 2000);
        }
        if (filled($d['comentario'] ?? null)) {
            $datos['comentario_sicar'] = mb_substr(trim((string) $d['comentario']), 0, 2000);
        }
        $costoSicar = isset($d['costo']) && $d['costo'] !== null ? $this->num($d['costo']) : null;
        $pagadaEn = filled($d['pagada_en'] ?? null) ? $this->fecha($d['pagada_en']) : null;
        $referencia = filled($d['pago_referencia'] ?? null) ? mb_substr((string) $d['pago_referencia'], 0, 120) : null;
        $cancelada = (bool) ($d['cancelada'] ?? false);
        // Un depósito provisional necesita conciliar la asignación completa, no sumar otro grupo de facturas.
        // Se conserva saldo_sicar como evidencia y se difiere únicamente el cambio automático de pago.
        if (($venta?->deposito_cobro_id) || CobrosProvisionales::retenerPago($clienteId, $referencia ?? '')) {
            $pagadaEn = null;
        }
        if ($venta?->deposito_cobro_id && ($cancelada || CobrosProvisionales::centavos($datos['total']) !== CobrosProvisionales::centavos($venta->total)
            || $clienteId !== $venta->cliente_id || ($datos['uuid'] && $venta->uuid && strcasecmp($datos['uuid'], $venta->uuid) !== 0))) {
            DepositoCobro::whereKey($venta->deposito_cobro_id)->where('estatus', '!=', 'anulado')->update(['estatus' => 'revisar']);
        }
        // SICAR dice saldo 0 pero no mandó la fecha del pago: si es anterior al inicio de comisiones se da por cobrada con la
        // fecha del documento (no afecta nóminas); las recientes se dejan pendientes y Cobranza las avisa para poner la fecha.
        if (! $cancelada && ! $pagadaEn && isset($d['saldo']) && is_numeric($d['saldo']) && abs((float) $d['saldo']) <= 0.5
            && ($datos['fecha'] ?? null) && $datos['fecha'] < CalculoNomina::desde()) {
            $pagadaEn = $datos['fecha'];
            $referencia ??= 'Saldada en SICAR';
        }

        // Si se facturó una nota que ya estaba cobrada, el dinero ya llegó: la factura hereda esa fecha
        // (si no, la comisión se pagaría dos veces).
        $nota = $tipo === 'factura' && ! $cancelada ? $this->notaCobradaDe($folio) : null;
        if ($nota && (! $pagadaEn || $nota->fecha_pago->toDateString() < $pagadaEn)) {
            $pagadaEn = $nota->fecha_pago->toDateString();
            $referencia = "Cobrada como {$nota->folio}";
        }

        if (($venta?->deposito_cobro_id) || CobrosProvisionales::retenerPago($clienteId, $referencia ?? '')) {
            $pagadaEn = null;
        }

        if (! $venta) {
            $this->tocadas[] = Venta::create($datos + [
                'sicar_id' => (string) $d['sicar_id'],
                'origen' => 'sicar',
                'costo' => $costoSicar ?? 0,
                'costo_sicar' => $costoSicar,
                'estatus' => $cancelada ? 'cancelada' : ($pagadaEn ? 'pagada' : 'pendiente'),
                'fecha_pago' => $cancelada ? null : $pagadaEn,
                'pago_referencia' => $pagadaEn ? $referencia : null,
            ])->id;
            $this->resumen['creadas']++;
            if ($cancelada) {
                $this->resumen['canceladas']++;
            } elseif ($pagadaEn) {
                $this->resumen['pagadas']++;
            }

            return;
        }

        if ($venta->trashed()) {
            // La borraron a mano en el sistema: no se revive.
            $this->resumen['sin_cambios']++;

            return;
        }

        $antes = $venta->only(['subtotal', 'total', 'costo', 'estatus', 'fecha_pago', 'fecha', 'cliente_nombre']);
        $venta->fill($datos);
        $venta->sicar_id = (string) $d['sicar_id'];

        // Costo: solo si nadie lo ajustó a mano.
        if ($costoSicar !== null) {
            $ajustadoAMano = $venta->costo_sicar !== null
                ? round((float) $venta->costo, 2) !== round((float) $venta->costo_sicar, 2)
                : (float) $venta->costo > 0 && $venta->origen !== 'sicar';
            if (! $ajustadoAMano || (float) $venta->costo == 0.0) {
                $venta->costo = $costoSicar;
            }
            $venta->costo_sicar = $costoSicar;
        }

        $pagada = false;
        $canceladaAhora = false;
        if ($cancelada && $venta->estatus !== 'cancelada') {
            $venta->estatus = 'cancelada';
            $canceladaAhora = true;
        } elseif (! $cancelada && $pagadaEn && $venta->estatus === 'pendiente') {
            $venta->estatus = 'pagada';
            $venta->fecha_pago = $pagadaEn;
            $venta->pago_referencia = $referencia;
            $venta->pago_registrado_at = now();   // complemento: la nómina lo comisiona aunque la fecha sea anterior al arranque
            $pagada = true;
        }

        if (! $venta->isDirty()) {
            $this->resumen['sin_cambios']++;

            return;
        }
        $venta->save();
        $this->tocadas[] = $venta->id;

        foreach (['subtotal', 'total', 'costo', 'estatus', 'fecha_pago', 'fecha', 'cliente_nombre'] as $campo) {
            $nuevo = $venta->{$campo};
            $viejo = $antes[$campo];
            $fmt = fn ($v) => $v instanceof Carbon ? $v->toDateString() : (is_numeric($v) ? number_format((float) $v, 2, '.', '') : $v);
            $venta->anotar($campo, $fmt($viejo), $fmt($nuevo), 'sicar');
        }
        $this->resumen['actualizadas']++;
        if ($pagada) {
            $this->resumen['pagadas']++;
        }
        if ($canceladaAhora) {
            $this->resumen['canceladas']++;
        }
    }

    /** Nota cancelada por haberse facturado en ese folio y que ya estaba cobrada. */
    private function notaCobradaDe(string $folioFactura): ?Venta
    {
        return Venta::where('tipo', 'nota')->where('factura_sustituta', $folioFactura)->whereNotNull('fecha_pago')
            ->orderBy('fecha_pago')->first();
    }

    /**
     * Nota de venta que después se facturó: no se da de alta (ya va en la factura). Si ya estaba en el sistema
     * (del Sheets) se cancela con "Se facturó en …" y, si estaba cobrada, la factura hereda la fecha de pago.
     */
    private function notaFacturada(array $d, string $folio, string $folioFactura): void
    {
        $nota = Venta::where('sicar_id', (string) $d['sicar_id'])->first()
            ?? Venta::where('folio_llave', Venta::llave('nota', $folio))->first();
        if (! $nota) {
            $this->resumen['sin_cambios']++;

            return;
        }
        $nota->sicar_id ??= (string) $d['sicar_id'];
        $antes = $nota->estatus;
        if ($nota->estatus !== 'cancelada') {
            $nota->estatus = 'cancelada';   // la fecha de pago se conserva: la hereda la factura
            $nota->notas = trim(($nota->notas ? $nota->notas."\n" : '')."Se facturó en {$folioFactura} (SICAR).");
        }
        $nota->factura_sustituta = $folioFactura;
        if (! $nota->isDirty()) {
            $this->resumen['sin_cambios']++;

            return;
        }
        $nota->save();
        $nota->anotar('estatus', $antes, $nota->estatus, 'sicar');
        $this->resumen[$antes !== 'cancelada' ? 'canceladas' : 'actualizadas']++;

        if (! $nota->fecha_pago) {
            return;
        }
        $factura = Venta::where('folio_llave', Venta::llave('factura', $folioFactura))->first();
        $fecha = $nota->fecha_pago->toDateString();
        $heredar = $factura && ! $factura->deposito_cobro_id && $factura->estatus !== 'cancelada' && (
            $factura->estatus === 'pendiente'
            || ($factura->origen === 'sicar' && $factura->fecha_pago && $factura->fecha_pago->toDateString() > $fecha)
        );
        if ($heredar) {
            $estatusAntes = $factura->estatus;
            $pagoAntes = $factura->fecha_pago?->toDateString();
            $factura->update(['estatus' => 'pagada', 'fecha_pago' => $fecha, 'pago_referencia' => "Cobrada como {$nota->folio}"]);
            $factura->anotar('estatus', $estatusAntes, 'pagada', 'sicar');
            $factura->anotar('fecha_pago', $pagoAntes, $fecha, 'sicar');
        }
    }

    /** Cliente por RFC, luego por nombre; si no existe se crea (viene de una factura real de SICAR). */
    private function cliente(array $d): ?Cliente
    {
        $rfc = mb_strtoupper(preg_replace('/[^A-Za-z0-9&Ññ]/u', '', (string) ($d['rfc'] ?? '')));
        $rfc = strlen($rfc) >= 12 && strlen($rfc) <= 13 && ! in_array($rfc, ['XAXX010101000', 'XEXX010101000'], true) ? $rfc : null;
        $nombre = Nombres::limpiar((string) $d['cliente']);
        $dias = isset($d['dias_credito']) && is_numeric($d['dias_credito']) && (int) $d['dias_credito'] > 0 && (int) $d['dias_credito'] <= 365
            ? (int) $d['dias_credito'] : null;

        $cliente = $rfc ? Cliente::where('rfc', $rfc)->first() : null;
        $cliente ??= Cliente::where('nombre_llave', Nombres::llave($nombre))->first();
        if (! $cliente && ($borrado = Cliente::onlyTrashed()->where('nombre_llave', Nombres::llave($nombre))->first())) {
            // Se unió a otro en Duplicados: se usa el principal. Si solo se borró, se revive (SICAR le sigue facturando).
            $cliente = $borrado->vigente();
            if (! $cliente && ! $borrado->unido_a_id) {
                $borrado->restore();
                $cliente = $borrado;
            }
        }
        if (! $cliente) {
            $this->clientesPorNombre ??= Cliente::query()->get(['id', 'nombre'])
                ->mapWithKeys(fn (Cliente $c) => [UnificarDuplicados::base($c->nombre) => $c->id])->all();
            $id = $this->clientesPorNombre[UnificarDuplicados::base($nombre)] ?? null;
            $cliente = $id ? Cliente::find($id) : null;
        }
        if (! $cliente) {
            $cliente = Cliente::create(['nombre' => $nombre, 'rfc' => $rfc, 'dias_credito' => $dias ?? 30]);
            $this->clientesPorNombre = null;

            return $cliente;
        }
        $cambios = [];
        if ($rfc && blank($cliente->rfc)) {
            $cambios['rfc'] = $rfc;
        }
        if ($dias && $dias !== (int) $cliente->dias_credito) {
            $cambios['dias_credito'] = $dias;
        }
        if ($cambios) {
            $cliente->update($cambios);
        }

        return $cliente;
    }
}
