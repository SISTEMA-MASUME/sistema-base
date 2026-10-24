<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cobranza: en el Sheets de Daniel ACME, PARQUES y PLAZA NORTE tenían 45 días de crédito (el resto 30).
 * Solo se cambia si siguen con el valor por defecto (30), para no pisar lo que ya se haya ajustado.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([] as $nombre) {
            DB::table('clientes')->where('nombre', 'like', "%{$nombre}%")->where('dias_credito', 30)->update(['dias_credito' => 45]);
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás: no se sabe cuáles tenían 30 antes.
    }
};
