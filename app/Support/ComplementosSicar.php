<?php

namespace App\Support;

use App\Models\Cliente;
use App\Models\ComplementoPago;
use App\Models\DepositoCobro;
use App\Models\DepositoEvento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Detalle del REP separado del resumen de saldo de cada factura. No emite CFDI ni escribe en SICAR. */
final class ComplementosSicar
{
    public static function normalizar(array $datos): array
    {
        $v = Validator::make($datos, [
            'sicar_id' => ['nullable', 'string', 'max:40'],
            'uuid' => ['nullable', 'uuid'], 'folio' => ['required', 'string', 'max:40'],
            'rfc' => ['required', 'regex:/^[A-ZÑ&0-9]{12,13}$/u'],
            'cliente' => ['required', 'string', 'max:255'],
            'fecha_pago' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->addMonth()->toDateString()],
            'importe' => ['required', 'numeric', 'min:0.01', 'max:99999999999', 'decimal:0,2'],
            'moneda' => ['required', 'string', 'size:3'],
            'cancelado' => ['sometimes', 'boolean'],
            'referencia' => ['nullable', 'string', 'max:120'],
            'documentos' => ['required', 'array', 'min:1', 'max:500'],
            'documentos.*.uuid' => ['nullable', 'uuid'],
            'documentos.*.sicar_id' => ['nullable', 'string', 'max:40'],
            'documentos.*.folio' => ['required', 'string', 'max:40'],
            'documentos.*.importe_pagado' => ['required', 'numeric', 'min:0.01', 'max:99999999999', 'decimal:0,2'],
            'documentos.*.saldo_anterior' => ['required', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,2'],
            'documentos.*.saldo_insoluto' => ['required', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,2'],
            'documentos.*.moneda' => ['required', 'string', 'size:3'],
        ], ['required' => 'Falta :attribute en el complemento.', 'uuid' => 'El UUID no es válido.',
            'decimal' => 'Los importes deben tener como máximo dos decimales.']);
        $d = $v->validate();
        if (empty($d['uuid']) && empty($d['sicar_id'])) {
            self::error('Falta el identificador único del complemento.');
        }
        $docs = [];
        foreach ($d['documentos'] as $doc) {
            if (empty($doc['uuid']) && empty($doc['sicar_id'])) {
                self::error('Una factura del complemento no tiene identificador único.');
            }
            $clave = strtoupper($doc['uuid'] ?? $doc['sicar_id']);
            if (isset($docs[$clave])) {
                self::error('El complemento repite una factura; requiere revisión.');
            }
            $docs[$clave] = [
                'uuid' => filled($doc['uuid'] ?? null) ? strtoupper($doc['uuid']) : null,
                'sicar_id' => $doc['sicar_id'] ?? null, 'folio' => strtoupper(trim($doc['folio'])),
                'importe_pagado' => number_format((float) $doc['importe_pagado'], 2, '.', ''),
                'saldo_anterior' => number_format((float) $doc['saldo_anterior'], 2, '.', ''),
                'saldo_insoluto' => number_format((float) $doc['saldo_insoluto'], 2, '.', ''),
                'moneda' => strtoupper($doc['moneda']),
            ];
        }
        ksort($docs);

        return ['sicar_id' => $d['sicar_id'] ?? null, 'uuid' => filled($d['uuid'] ?? null) ? strtoupper($d['uuid']) : null,
            'folio' => strtoupper(trim($d['folio'])), 'rfc' => $d['rfc'], 'cliente_nombre' => Nombres::limpiar($d['cliente']),
            'fecha_pago' => $d['fecha_pago'], 'importe' => number_format((float) $d['importe'], 2, '.', ''),
            'moneda' => strtoupper($d['moneda']), 'cancelado' => (bool) ($d['cancelado'] ?? false), 'documentos' => array_values($docs),
            // La "Referencia" del abono en SICAR dice a qué banco llegó el dinero (no cambia nada del cobro de las facturas).
            'referencia' => filled($d['referencia'] ?? null) ? Str::limit(trim($d['referencia']), 120, '') : null];
    }

    public static function guardar(array $d, ?int $usuarioId = null): ComplementoPago
    {
        return DB::transaction(function () use ($d, $usuarioId) {
            // El RFC se bloquea también al crear/conciliar depósitos: los movimientos de un cliente se serializan.
            Cliente::where('rfc', $d['rfc'])->orderBy('id')->lockForUpdate()->get();
            $porFolio = ComplementoPago::where('rfc', $d['rfc'])->where('folio', $d['folio'])->lockForUpdate()->first();
            $porUuid = $d['uuid'] ? ComplementoPago::where('uuid', $d['uuid'])->lockForUpdate()->first() : null;
            $porId = $d['sicar_id'] ? ComplementoPago::where('sicar_id', $d['sicar_id'])->lockForUpdate()->first() : null;
            $ids = collect([$porFolio, $porUuid, $porId])->filter()->pluck('id')->unique();
            if ($ids->count() > 1) {
                self::error('Los identificadores del complemento apuntan a registros distintos.');
            }
            $rep = $porFolio ?? $porUuid ?? $porId;
            if ($usuarioId && $rep?->cancelado) {
                self::error('SICAR reportó este complemento cancelado. Cargar su XML anterior no lo vuelve vigente.');
            }
            if ($rep && (($rep->uuid && $d['uuid'] && $rep->uuid !== $d['uuid'])
                || ($rep->sicar_id && $d['sicar_id'] && $rep->sicar_id !== $d['sicar_id'])
                || $rep->rfc !== $d['rfc'] || $rep->folio !== $d['folio'])) {
                self::error('El folio o identificador del complemento ya pertenece a otro registro.');
            }
            // Sin referencia (p. ej. al cargar el XML) se conserva la que ya mandó SICAR; la cuenta sale de la referencia.
            $d['referencia'] = ($d['referencia'] ?? null) ?? $rep?->referencia;
            $d['cuenta_id'] = $d['referencia'] !== null ? CuentaPorReferencia::buscar($d['referencia']) : null;
            $financieros = $d;
            unset($financieros['uuid'], $financieros['sicar_id'], $financieros['cliente_nombre'], $financieros['cancelado'],
                $financieros['referencia'], $financieros['cuenta_id']);
            $financieros['documentos'] = array_map(function ($doc) {
                // Agregar el ID interno al mismo UUID no constituye una diferencia financiera.
                if ($doc['uuid']) {
                    unset($doc['sicar_id'], $doc['folio']);
                }

                return $doc;
            }, $d['documentos']);
            $huella = hash('sha256', json_encode($financieros, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $cambio = $rep && $rep->huella !== $huella;
            $cancelacion = $rep && $rep->cancelado !== $d['cancelado'];
            $antes = $rep?->only(['importe', 'fecha_pago', 'documentos', 'cancelado']);
            if ($rep) {
                $d['uuid'] ??= $rep->uuid;
                $d['sicar_id'] ??= $rep->sicar_id;
            }
            $rep ??= new ComplementoPago;
            $rep->fill($d + ['huella' => $huella]);
            if (($cambio || $cancelacion) && ($rep->deposito_id || $rep->estatus === 'independiente')) {
                $rep->requiere_revision = true;
                $rep->estatus = 'revisar';
                if ($rep->deposito_id) {
                    DepositoCobro::whereKey($rep->deposito_id)->update(['estatus' => 'revisar']);
                }
            }
            $rep->save();
            if ($cambio || $cancelacion) {
                DepositoEvento::create(['deposito_id' => $rep->deposito_id, 'complemento_id' => $rep->id,
                    'user_id' => $usuarioId, 'accion' => 'cambio_complemento', 'datos' => ['antes' => $antes, 'despues' => $d], 'created_at' => now()]);
            }

            return $rep;
        });
    }

    public static function leerXml(string $xml): array
    {
        if (strlen($xml) > 2 * 1024 * 1024 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            self::error('El XML es demasiado grande o contiene declaraciones no permitidas.');
        }
        $anterior = libxml_use_internal_errors(true);
        try {
            $r = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
            if (! $r || $r->getName() !== 'Comprobante' || (string) $r['TipoDeComprobante'] !== 'P') {
                self::error('Carga un XML de complemento de pago CFDI 4.0.');
            }
            $r->registerXPathNamespace('c', 'http://www.sat.gob.mx/cfd/4');
            $r->registerXPathNamespace('p', 'http://www.sat.gob.mx/Pagos20');
            $r->registerXPathNamespace('t', 'http://www.sat.gob.mx/TimbreFiscalDigital');
            $emisor = $r->xpath('/c:Comprobante/c:Emisor')[0] ?? null;
            $receptor = $r->xpath('/c:Comprobante/c:Receptor')[0] ?? null;
            $pagos = $r->xpath('/c:Comprobante/c:Complemento/p:Pagos/p:Pago');
            $timbre = $r->xpath('/c:Comprobante/c:Complemento/t:TimbreFiscalDigital')[0] ?? null;
            if (! $emisor || (string) $emisor['Rfc'] !== 'AAAA010101AA1' || ! $receptor || ! $timbre || count($pagos ?: []) !== 1) {
                self::error('El XML debe ser de MASUME y contener un solo pago timbrado. Los pagos múltiples requieren revisión.');
            }
            $p = $pagos[0];
            $docs = [];
            foreach ($p->children('http://www.sat.gob.mx/Pagos20')->DoctoRelacionado as $doc) {
                $doc = $doc->attributes();
                if (isset($doc['EquivalenciaDR']) && (float) $doc['EquivalenciaDR'] !== 1.0) {
                    self::error('La conversión entre monedas requiere revisión; este módulo admite MXN sin conversión.');
                }
                $folio = trim((string) $doc['Serie'].'-'.(string) $doc['Folio'], '-');
                $docs[] = ['uuid' => (string) $doc['IdDocumento'],
                    'folio' => $folio ?: (string) $doc['IdDocumento'],
                    'importe_pagado' => (string) $doc['ImpPagado'], 'saldo_anterior' => (string) $doc['ImpSaldoAnt'],
                    'saldo_insoluto' => (string) $doc['ImpSaldoInsoluto'], 'moneda' => (string) $doc['MonedaDR']];
            }

            return self::normalizar(['uuid' => (string) $timbre['UUID'],
                'folio' => trim((string) $r['Serie'].'-'.(string) $r['Folio'], '-'),
                'rfc' => (string) $receptor['Rfc'], 'cliente' => (string) $receptor['Nombre'],
                'fecha_pago' => substr((string) $p['FechaPago'], 0, 10), 'importe' => (string) $p['Monto'],
                'moneda' => (string) $p['MonedaP'], 'documentos' => $docs]);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
    }

    public static function error(string $mensaje): never
    {
        throw ValidationException::withMessages(['conciliacion' => $mensaje]);
    }
}
