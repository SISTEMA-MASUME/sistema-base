<?php

namespace App\Models\Concerns;

use App\Support\Nombres;

/**
 * Para clientes y proveedores (nombre_llave único, con borrado suave): buscar por nombre debe ver también
 * los borrados; si no, al escribir un nombre que se unió en Duplicados o se borró, se intentaba crear otro
 * con la misma llave y la base lo rechazaba (error "Duplicate entry ... nombre_llave_unique").
 */
trait BuscaPorNombre
{
    /**
     * El registro que sigue vigente: si este se unió a otro en Duplicados (borrado con unido_a_id), el principal;
     * si no está borrado, él mismo; si se borró sin unirse, null.
     */
    public function vigente(): ?static
    {
        $r = $this;
        for ($saltos = 0; $r && $r->trashed() && $saltos < 5; $saltos++) {
            $r = $r->unido_a_id ? static::withTrashed()->find($r->unido_a_id) : null;
        }

        return $r && ! $r->trashed() ? $r : null;
    }

    /**
     * El que tiene ese nombre (compara nombre_llave) y sigue vigente; si se unió a otro en Duplicados, el principal.
     * Los borrados sin unirse no cuentan (null): revísalos con borradoConNombre() antes de dar de alta.
     */
    public static function porNombre(string $nombre): ?static
    {
        $llave = Nombres::llave(Nombres::limpiar($nombre));

        return $llave === '' ? null : static::withTrashed()->where('nombre_llave', $llave)->first()?->vigente();
    }

    /**
     * Uno borrado a propósito (sin unirse a otro) con ese nombre. No se reactiva solo: si el admin lo borró es
     * porque no debe usarse; quien lo escribe recibe el aviso. Su nombre_llave sigue ocupado, así que no se puede
     * dar de alta otro igual.
     */
    public static function borradoConNombre(string $nombre): ?static
    {
        $llave = Nombres::llave(Nombres::limpiar($nombre));

        return $llave === '' ? null : static::onlyTrashed()->where('nombre_llave', $llave)->whereNull('unido_a_id')->first();
    }
}
