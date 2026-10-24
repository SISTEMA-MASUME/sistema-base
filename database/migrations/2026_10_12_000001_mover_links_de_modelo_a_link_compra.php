<?php

use App\Support\LinkCompra;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Partidas que traían el link de compra pegado en "Modelo": se pasa a su campo "Link de compra". */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cotizacion_partidas', 'pedido_partidas'] as $tabla) {
            DB::table($tabla)->where(fn ($q) => $q->where('modelo', 'like', 'http%')->orWhere('modelo', 'like', 'www.%'))
                ->orderBy('id')->get(['id', 'modelo', 'link_compra'])
                ->each(function ($p) use ($tabla) {
                    [$modelo, $link] = LinkCompra::separar($p->modelo, $p->link_compra);
                    if ($modelo === null) {
                        DB::table($tabla)->where('id', $p->id)->update(['modelo' => null, 'link_compra' => $link]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás: el link queda en su campo.
    }
};
