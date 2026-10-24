<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Support\ComplementosSicar;
use App\Support\SincronizacionVentasSicar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Facturas, notas y pagos de SICAR que manda el programa de la PC de Daniel (token Bearer SICAR_SYNC_TOKEN).
 * Contrato en docs/SICAR-VENTAS-PC.md.
 */
class SicarVentasController extends Controller
{
    public function recibir(Request $request): JsonResponse
    {
        $documentos = $request->json('documentos');
        if (! is_array($documentos) || ! array_is_list($documentos)) {
            return response()->json(['ok' => false, 'error' => 'Falta la lista "documentos".'], 422);
        }
        if (count($documentos) > SincronizacionVentasSicar::MAXIMO) {
            return response()->json(['ok' => false, 'error' => 'Máximo '.SincronizacionVentasSicar::MAXIMO.' documentos por envío.'], 422);
        }

        $complementos = $request->json('complementos', []);
        Validator::make(['complementos' => $complementos], ['complementos' => ['array', 'max:500'],
            'complementos.*' => ['array']])->validate();
        // Validar el lote completo antes de guardar. El formato anterior sigue siendo compatible.
        $complementos = array_map(fn ($rep) => ComplementosSicar::normalizar($rep), $complementos);
        foreach ($complementos as $rep) {
            ComplementosSicar::guardar($rep);
        }

        return response()->json(['ok' => true, 'complementos_recibidos' => count($complementos)] + (new SincronizacionVentasSicar)->aplicar($documentos));
    }

    /** Para que la PC sepa desde cuándo mandar: la última sincronización y la fecha más reciente que ya tiene. */
    public function estado(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'ultima_sync' => SincronizacionVentasSicar::ultimaSincronizacion()?->toIso8601String(),
            'ultima_fecha_sicar' => ($f = Venta::whereNotNull('sicar_id')->max('fecha')) ? substr((string) $f, 0, 10) : null,
            'pendientes' => Venta::where('estatus', 'pendiente')->count(),
        ]);
    }
}
