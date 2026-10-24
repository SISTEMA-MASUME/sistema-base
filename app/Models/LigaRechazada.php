<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un "No" de Daniel: esa razón social NO factura por ese cliente; no se vuelve a proponer. */
class LigaRechazada extends Model
{
    protected $table = 'ligas_rechazadas';

    protected $fillable = ['cliente_id', 'nombre_llave', 'user_id'];
}
