<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Reparte folios sin repetir aunque dos vendedores guarden al mismo tiempo.
 * "ultimo" guarda el último folio ya usado (misma convención que CONFIG!B2).
 */
final class Folios
{
    public static function siguiente(string $clave = 'cotizacion'): int
    {
        return DB::transaction(function () use ($clave) {
            $fila = DB::table('contadores')->where('clave', $clave)->lockForUpdate()->first();

            if (! $fila) {
                $inicial = (int) config("masume.folio_inicial.$clave", 0);
                DB::table('contadores')->insert([
                    'clave' => $clave, 'ultimo' => $inicial + 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return $inicial + 1;
            }

            $nuevo = $fila->ultimo + 1;
            DB::table('contadores')->where('clave', $clave)->update(['ultimo' => $nuevo, 'updated_at' => now()]);

            return $nuevo;
        });
    }
}
