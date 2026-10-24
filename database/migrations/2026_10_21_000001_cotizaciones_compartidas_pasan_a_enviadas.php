<?php

use App\Models\Cotizacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cotizaciones que sí se compartieron (WhatsApp, correo o descarga) pero se quedaron en "borrador"
 * (el registro se hacía desde el navegador y a veces se perdía). Pasan a "enviada" con la fecha del envío.
 */
return new class extends Migration
{
    public function up(): void
    {
        $compartidas = DB::table('documento_envios')
            ->where('documento_type', Cotizacion::class)->where('exito', true)->where('canal', '!=', 'enlace')
            ->groupBy('documento_id')->selectRaw('documento_id as id, min(created_at) as fecha')->pluck('fecha', 'id');
        $porCorreo = DB::table('cotizacion_envios')->where('exito', true)
            ->groupBy('cotizacion_id')->selectRaw('cotizacion_id as id, min(created_at) as fecha')->pluck('fecha', 'id');

        foreach ($compartidas->union($porCorreo) as $id => $fecha) {
            DB::table('cotizaciones')->where('id', $id)->where('estado', 'borrador')
                ->update(['estado' => 'enviada', 'enviada_at' => DB::raw('COALESCE(enviada_at, '.DB::getPdo()->quote((string) $fecha).')')]);
        }
    }

    public function down(): void
    {
        // Sin vuelta atrás.
    }
};
