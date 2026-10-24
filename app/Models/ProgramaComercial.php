<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Programa comercial de un proveedor (Urrea M1): niveles por consumo trimestral. Ver Support\ProgramasComerciales. */
class ProgramaComercial extends Model
{
    protected $table = 'programas_comerciales';

    protected $fillable = ['proveedor_id', 'nombre', 'descuento_base', 'anticipado', 'niveles', 'acelerador', 'acelerador_desde', 'bono_maximo', 'bono_en_factura',
        'bono_factura', 'bono_factura_trimestre'];

    protected function casts(): array
    {
        return ['niveles' => 'array', 'acelerador' => 'array', 'descuento_base' => 'decimal:2', 'anticipado' => 'decimal:2',
            'acelerador_desde' => 'decimal:2', 'bono_maximo' => 'decimal:2', 'bono_en_factura' => 'boolean', 'bono_factura' => 'decimal:2'];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class)->withTrashed();
    }

    public function ajustes(): HasMany
    {
        return $this->hasMany(ProgramaAjuste::class)->orderByDesc('fecha')->orderByDesc('id');
    }
}
