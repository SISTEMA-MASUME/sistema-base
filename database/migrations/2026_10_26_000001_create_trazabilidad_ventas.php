<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad (Daniel, 1-oct-2026): notas que se facturan (varias notas → una factura), facturas canceladas que se
 * rehacen (sustituida por) y pedidos ↔ facturas/notas (muchos a muchos). Se pasan las ligas que ya había en
 * ventas.factura_sustituta (del Sheets o de SICAR) cuando la factura destino existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venta_vinculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('origen_id')->constrained('ventas')->cascadeOnDelete();   // la nota o la factura vieja
            $table->foreignId('destino_id')->constrained('ventas')->cascadeOnDelete();  // la factura que la reemplaza
            $table->string('tipo', 20);              // facturada_en | sustituida_por
            $table->string('fuente', 10);            // sicar | sheets | manual
            $table->string('estatus_anterior', 12)->nullable();   // para deshacer
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['origen_id', 'destino_id']);
        });

        Schema::create('pedido_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $table->foreignId('venta_id')->constrained('ventas')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['pedido_id', 'venta_id']);
        });

        $ahora = now();
        DB::table('ventas')->whereNotNull('factura_sustituta')->orderBy('id')
            ->select(['id', 'tipo', 'factura_sustituta', 'estatus', 'notas'])
            ->chunk(500, function ($filas) use ($ahora) {
                foreach ($filas as $v) {
                    $folio = mb_strtoupper(preg_replace('/\s+/u', '', (string) $v->factura_sustituta));
                    $destino = DB::table('ventas')->where('folio_llave', 'factura|'.$folio)->where('id', '!=', $v->id)->value('id');
                    if (! $destino || DB::table('venta_vinculos')->where('origen_id', $v->id)->where('destino_id', $destino)->exists()) {
                        continue;
                    }
                    DB::table('venta_vinculos')->insert([
                        'origen_id' => $v->id, 'destino_id' => $destino,
                        'tipo' => $v->tipo === 'nota' ? 'facturada_en' : 'sustituida_por',
                        'fuente' => str_contains((string) $v->notas, '(SICAR)') ? 'sicar' : 'sheets',
                        'estatus_anterior' => null, 'user_id' => null, 'created_at' => $ahora,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_venta');
        Schema::dropIfExists('venta_vinculos');
    }
};
