<?php

namespace App\Models\Ned;

use Illuminate\Database\Eloquent\Model;

class NedPartida extends Model
{
    protected $table = 'ned_partidas';

    protected $fillable = ['ned_cotizacion_id', 'orden', 'descripcion', 'cantidad', 'unidad', 'costo', 'precio', 'importe'];

    protected $casts = ['cantidad' => 'decimal:3', 'costo' => 'decimal:4', 'precio' => 'decimal:2', 'importe' => 'decimal:2'];
}
