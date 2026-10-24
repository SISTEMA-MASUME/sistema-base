<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Clave de producto o servicio del catálogo oficial del SAT (c_ClaveProdServ, CFDI 4.0). */
class SatClave extends Model
{
    protected $table = 'sat_claves';

    protected $primaryKey = 'clave';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['clave', 'descripcion', 'similares', 'texto'];
}
