<?php

namespace App\Support;

use App\Models\Cliente;
use App\Models\ClienteSucursal;
use App\Models\Contacto;
use App\Models\Cotizacion;
use App\Models\Pedido;
use Illuminate\Support\Facades\DB;

/**
 * Convierte clientes separados ("LUMEX MERIDA", "LUMEX TUXTLA"…) en plazas de un solo cliente, moviendo sus
 * cotizaciones, pedidos y contactos. Solo con vista previa y confirmación explícita (T16).
 */
class UnirClientes
{
    /**
     * @param  list<int>  $origenIds
     * @return list<array{id: int, nombre: string, cotizaciones: int, pedidos: int, contactos: int, conflictos: list<string>}>
     */
    public static function vistaPrevia(Cliente $destino, array $origenIds): array
    {
        $origenes = Cliente::whereIn('id', $origenIds)->where('id', '!=', $destino->id)->orderBy('nombre')->get();
        // Número de OC que ya existe en el destino (o en otro de los que se unen): chocaría con la llave única.
        $llaves = Pedido::withTrashed()->where('cliente_id', $destino->id)->whereNotNull('numero_oc_llave')->pluck('numero_oc_llave')->flip()->all();

        $filas = [];
        foreach ($origenes as $o) {
            $conflictos = [];
            foreach (Pedido::withTrashed()->where('cliente_id', $o->id)->whereNotNull('numero_oc_llave')->get(['folio', 'numero_oc_cliente', 'numero_oc_llave']) as $p) {
                if (isset($llaves[$p->numero_oc_llave])) {
                    $conflictos[] = "La OC {$p->numero_oc_cliente} (P-{$p->folio}) ya existe en {$destino->nombre} o en otro cliente de la lista.";
                }
                $llaves[$p->numero_oc_llave] = true;
            }
            $filas[] = [
                'id' => $o->id,
                'nombre' => $o->nombre,
                'cotizaciones' => Cotizacion::where('cliente_id', $o->id)->count(),
                'pedidos' => Pedido::withTrashed()->where('cliente_id', $o->id)->count(),
                'contactos' => Contacto::where('cliente_id', $o->id)->count(),
                'conflictos' => $conflictos,
            ];
        }

        return $filas;
    }

    /**
     * @param  list<int>  $origenIds
     * @return int número de plazas creadas
     */
    public static function aplicar(Cliente $destino, array $origenIds): int
    {
        $previa = self::vistaPrevia($destino, $origenIds);
        abort_if(collect($previa)->contains(fn ($f) => $f['conflictos'] !== []), 422, 'Hay OC repetidas; revísalas antes de unir.');

        return DB::transaction(function () use ($destino, $previa) {
            foreach ($previa as $fila) {
                $o = Cliente::findOrFail($fila['id']);
                $plaza = ClienteSucursal::create([
                    'cliente_id' => $destino->id,
                    'nombre' => $o->nombre,
                    'direccion' => $o->direccion,
                    'telefono' => $o->telefono,
                    'correos_facturacion' => $o->correo,
                    'notas' => trim("Antes era el cliente \"{$o->nombre}\" (#{$o->id}).".($o->notas ? "\n".$o->notas : '')),
                ]);
                Cotizacion::where('cliente_id', $o->id)->update(['cliente_id' => $destino->id]);
                Pedido::withTrashed()->where('cliente_id', $o->id)->whereNull('sucursal_id')->update(['sucursal_id' => $plaza->id]);
                Pedido::withTrashed()->where('cliente_id', $o->id)->update(['cliente_id' => $destino->id]);
                Contacto::where('cliente_id', $o->id)->update(['cliente_id' => $destino->id]);
                $o->delete();
            }

            return count($previa);
        });
    }
}
