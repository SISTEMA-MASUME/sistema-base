<?php

namespace App\Models;

use App\Support\Nombres;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nombre (razón social) con el que se factura a un cliente en SICAR. Una razón social puede serlo de varios clientes. */
class ClienteRazonSocial extends Model
{
    protected $table = 'cliente_razones_sociales';

    protected $fillable = ['cliente_id', 'nombre', 'rfc', 'confirmada_por'];

    protected static function booted(): void
    {
        static::saving(function (self $r) {
            $r->nombre = Nombres::limpiar($r->nombre);
            $r->nombre_llave = Nombres::llave($r->nombre);
        });
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
