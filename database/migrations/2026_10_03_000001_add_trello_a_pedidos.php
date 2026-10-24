<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tarjeta de Trello de donde salió el pedido (importación única; el id evita importarla dos veces). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->string('trello_card_id', 40)->nullable()->unique();
            $table->string('trello_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pedidos', function (Blueprint $table) {
            $table->dropUnique(['trello_card_id']);
            $table->dropColumn(['trello_card_id', 'trello_url']);
        });
    }
};
