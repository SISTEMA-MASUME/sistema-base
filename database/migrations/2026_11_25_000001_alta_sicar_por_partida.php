<?php

use App\Support\AltaSicarPartidas;
use App\Support\RegistroErrores;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué partida se dio de alta en SICAR y cómo era en ese momento (P-176, 24-oct-2026: la partida se cambió de producto
 * después del alta y el pasador nunca llegó a SICAR, sin que nada lo avisara). Respalda lo ya dado de alta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedido_partidas', function (Blueprint $t) {
            $t->timestamp('sicar_alta_at')->nullable();
            $t->foreignId('sicar_alta_lote_id')->nullable()->constrained('sicar_lotes')->nullOnDelete();
            $t->string('sicar_alta_firma', 40)->nullable();
        });
        Schema::table('sicar_lotes', function (Blueprint $t) {
            $t->json('firmas')->nullable();
        });

        try {
            AltaSicarPartidas::respaldar();
        } catch (Throwable $e) {
            // Si algo falla, se registra y la publicación sigue: las partidas quedan sin marca y se revisan a mano.
            RegistroErrores::registrar($e);
        }
    }

    public function down(): void
    {
        Schema::table('pedido_partidas', function (Blueprint $t) {
            $t->dropConstrainedForeignId('sicar_alta_lote_id');
            $t->dropColumn(['sicar_alta_at', 'sicar_alta_firma']);
        });
        Schema::table('sicar_lotes', function (Blueprint $t) {
            $t->dropColumn('firmas');
        });
    }
};
