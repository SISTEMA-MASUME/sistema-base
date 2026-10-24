<?php

namespace App\Support;

use App\Models\Ajuste;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Serializa la comprobación del tope y la creación del trabajo; nunca mantiene el candado durante HTTP. */
final class PresupuestoIa
{
    public static function reservar(callable $crear, string $modelo): mixed
    {
        // insertOrIgnore no abre una lectura MVCC antes de esperar al candado en MariaDB.
        Ajuste::query()->insertOrIgnore(['clave' => 'ia_presupuesto_candado', 'valor' => '1', 'created_at' => now(), 'updated_at' => now()]);

        return DB::transaction(function () use ($crear, $modelo) {
            Ajuste::whereKey('ia_presupuesto_candado')->lockForUpdate()->firstOrFail();
            if ($aviso = LecturaOc::sinPresupuesto($modelo)) {
                throw new DomainException($aviso);
            }

            return $crear();
        });
    }
}
