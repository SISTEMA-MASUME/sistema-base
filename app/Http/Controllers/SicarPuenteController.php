<?php

namespace App\Http\Controllers;

use App\Enums\EstadoLoteSicar;
use App\Models\Ajuste;
use App\Models\PedidoActividad;
use App\Models\PedidoPartida;
use App\Models\SicarLote;
use App\Models\SicarProveedor;
use App\Support\AltaSicarPartidas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** API del puente SICAR para el programa de la PC de Daniel (el token lo revisa el middleware TokenSicar). */
class SicarPuenteController extends Controller
{
    /** Transiciones que puede pedir la PC: estado actual → estados nuevos. */
    private const TRANSICIONES = [
        'pendiente' => ['simulado', 'error'],
        'confirmado' => ['aplicado', 'error'],
    ];

    public function estado(): JsonResponse
    {
        Ajuste::registrarConexionPuente();
        $activo = Ajuste::puenteActivo();
        $lote = null;
        if ($activo) {
            $lote = SicarLote::where('estado', EstadoLoteSicar::Confirmado->value)->orderBy('id')->first()
                ?? SicarLote::where('estado', EstadoLoteSicar::Pendiente->value)->orderBy('id')->first();
        }

        return response()->json([
            'activo' => $activo,
            'lote' => $lote ? ['id' => $lote->id, 'estado' => $lote->estado->value, 'datos' => $lote->datos] : null,
        ]);
    }

    public function actualizar(Request $request, int $id): JsonResponse
    {
        $entrada = $request->validate([
            'estado' => ['required', 'in:simulado,aplicado,error'],
            'resultado' => ['nullable', 'array'],
            'mensaje' => ['nullable', 'string', 'max:5000'],
        ]);

        return DB::transaction(function () use ($id, $entrada) {
            $lote = SicarLote::with('pedidos')->find($id);
            if (! $lote) {
                return response()->json(['ok' => false, 'error' => 'Lote no encontrado.'], 404);
            }
            $nuevo = $entrada['estado'];
            if (! in_array($nuevo, self::TRANSICIONES[$lote->estado->value] ?? [], true)) {
                return response()->json(['ok' => false, 'error' => "El lote está {$lote->estado->value}: no puede pasar a {$nuevo}."], 409);
            }

            $lote->estado = EstadoLoteSicar::from($nuevo);
            $lote->resultado = $entrada['resultado'] ?? null;
            $lote->mensaje = $entrada['mensaje'] ?? null;
            if ($nuevo === 'simulado') {
                $lote->simulado_at = now();
            }
            if ($nuevo === 'aplicado') {
                $lote->aplicado_at = now();
                $this->aplicar($lote);
            }
            $lote->save();

            return response()->json(['ok' => true, 'id' => $lote->id, 'estado' => $lote->estado->value]);
        });
    }

    /** Marca los pedidos como dados de alta y guarda el artículo de SICAR en sus partidas. */
    private function aplicar(SicarLote $lote): void
    {
        $quien = $lote->confirmador?->name ?? 'sin dato';
        $pedidoIds = $lote->pedidos->pluck('id')->all();

        foreach ((array) ($lote->resultado['articulos'] ?? []) as $a) {
            $artId = (int) ($a['art_id'] ?? 0);
            $ids = array_map('intval', (array) ($a['partida_ids'] ?? []));
            if ($artId > 0 && $ids !== []) {
                PedidoPartida::whereIn('id', $ids)->whereIn('pedido_id', $pedidoIds)->update([
                    'sicar_art_id' => $artId, 'sicar_clave' => null, 'sicar_clave_sat' => null, 'sicar_sat_ia' => null, 'sicar_unidad' => null, 'sicar_categoria' => null,
                ]);
            }
        }

        AltaSicarPartidas::marcarDeLote($lote);

        foreach ($lote->pedidos as $pedido) {
            $pedido->update(['alta_sicar' => true]);
            PedidoActividad::create([
                'pedido_id' => $pedido->id, 'user_id' => $lote->confirmado_por, 'tipo' => 'alta_sicar',
                'descripcion' => mb_substr("Dado de alta en SICAR (lote {$lote->id}) — confirmó {$quien}", 0, 500),
            ]);
        }
    }

    public function proveedores(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'proveedores' => ['present', 'array', 'max:5000'],
            'proveedores.*.pro_id' => ['required', 'integer', 'min:1'],
            'proveedores.*.nombre' => ['required', 'string', 'max:200'],
            'proveedores.*.status' => ['nullable', 'integer'],
        ]);
        $ahora = now();
        foreach ($datos['proveedores'] as $p) {
            SicarProveedor::updateOrCreate(['pro_id' => $p['pro_id']], [
                'nombre' => $p['nombre'], 'status' => $p['status'] ?? 1, 'sincronizado_at' => $ahora,
            ]);
        }

        return response()->json(['ok' => true, 'recibidos' => count($datos['proveedores'])]);
    }
}
