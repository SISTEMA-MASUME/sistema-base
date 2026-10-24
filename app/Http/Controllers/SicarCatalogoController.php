<?php

namespace App\Http\Controllers;

use App\Models\SicarArticulo;
use App\Models\SicarSincronizacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Recibe el catálogo de SICAR por lotes (lo envía el programa de la PC de Daniel). */
class SicarCatalogoController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $esperado = (string) config('services.sicar.token');
        $recibido = (string) $request->bearerToken();
        if ($esperado === '' || $recibido === '' || ! hash_equals($esperado, $recibido)) {
            return response()->json(['ok' => false, 'error' => 'No autorizado.'], 401);
        }

        $datos = $request->validate([
            'lote' => ['required', 'integer', 'min:1'],
            'total_lotes' => ['required', 'integer', 'min:1'],
            'sincronizacion_id' => ['nullable', 'integer'],
            'origen' => ['nullable', 'string', 'max:80'],
            'articulos' => ['present', 'array', 'max:2000'],
            'articulos.*.art_id' => ['required', 'integer', 'min:1'],
            'articulos.*.clave' => ['nullable', 'string', 'max:60'],
            'articulos.*.clave_alterna' => ['nullable', 'string', 'max:60'],
            'articulos.*.descripcion' => ['nullable', 'string'],
            'articulos.*.unidad' => ['nullable', 'string', 'max:20'],
            'articulos.*.precio_compra' => ['nullable', 'numeric'],
            'articulos.*.precio1' => ['nullable', 'numeric'],
            'articulos.*.clave_prod_serv' => ['nullable', 'string', 'max:10'],
            'articulos.*.categoria' => ['nullable', 'string', 'max:80'],
            'articulos.*.status' => ['nullable', 'integer'],
        ]);

        $sync = ! empty($datos['sincronizacion_id']) ? SicarSincronizacion::find($datos['sincronizacion_id']) : null;
        $sync ??= SicarSincronizacion::create(['inicio' => now(), 'origen' => $datos['origen'] ?? $request->userAgent()]);

        $ahora = now();
        DB::transaction(function () use ($datos, $ahora) {
            foreach ($datos['articulos'] as $a) {
                SicarArticulo::updateOrCreate(['art_id' => $a['art_id']], [
                    'clave' => $a['clave'] ?? null,
                    'clave_alterna' => $a['clave_alterna'] ?? null,
                    'descripcion' => $a['descripcion'] ?? null,
                    'unidad' => $a['unidad'] ?? null,
                    'precio_compra' => $a['precio_compra'] ?? null,
                    'precio1' => $a['precio1'] ?? null,
                    'clave_prod_serv' => $a['clave_prod_serv'] ?? null,
                    'categoria' => $a['categoria'] ?? null,
                    'status' => $a['status'] ?? 1,
                    'clave_llave' => SicarArticulo::llave($a['clave'] ?? ''),
                    'sincronizado_at' => $ahora,
                ]);
            }
        });

        $sync->recibidos += count($datos['articulos']);
        if ($datos['lote'] >= $datos['total_lotes']) {
            $sync->fin = now();
            $sync->activos = SicarArticulo::where('status', 1)->count();
        }
        $sync->save();

        return response()->json(['ok' => true, 'sincronizacion_id' => $sync->id, 'recibidos' => $sync->recibidos]);
    }
}
