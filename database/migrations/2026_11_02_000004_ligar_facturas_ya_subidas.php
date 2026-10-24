<?php

use App\Models\PedidoArchivo;
use App\Support\FacturaArchivo;
use App\Support\RegistroErrores;
use Illuminate\Database\Migrations\Migration;

/** Una sola vez: lee el UUID/folio de las facturas (XML/PDF) que ya estaban en los expedientes y las liga con SICAR. */
return new class extends Migration
{
    public function up(): void
    {
        PedidoArchivo::whereIn('tipo', ['factura_xml', 'factura_pdf'])->whereNull('cfdi_uuid')->whereNull('cfdi_folio')
            ->with('pedido')->orderBy('id')
            ->each(function (PedidoArchivo $a) {
                try {
                    if ($a->pedido) {
                        FacturaArchivo::procesar($a);
                    }
                } catch (Throwable $e) {
                    RegistroErrores::registrar($e);
                }
            });
    }

    public function down(): void {}
};
