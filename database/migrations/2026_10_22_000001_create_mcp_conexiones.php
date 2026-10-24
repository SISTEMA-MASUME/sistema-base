<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conexión de asistentes de IA (Claude, ChatGPT) al sistema por MCP con OAuth. Solo el admin autoriza.
 * Los códigos y llaves se guardan con hash (sha256), nunca en claro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_clientes', function (Blueprint $table) {
            $table->id();
            $table->string('client_id', 80)->unique();
            $table->string('nombre')->nullable();
            $table->json('redirect_uris');
            $table->timestamps();
        });

        Schema::create('mcp_codigos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_hash', 64)->unique();
            $table->string('client_id', 80);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('redirect_uri', 1000);
            $table->string('code_challenge', 200);
            $table->timestamp('expira_at');
            $table->timestamps();
        });

        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');                                   // "Claude", "ChatGPT", "Llave personal"
            $table->string('client_id', 80)->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('refresh_hash', 64)->nullable()->unique();
            $table->timestamp('expira_at')->nullable();                 // null = llave personal sin caducidad
            $table->timestamp('refresh_expira_at')->nullable();
            $table->timestamp('ultimo_uso_at')->nullable();
            $table->timestamp('revocado_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tokens');
        Schema::dropIfExists('mcp_codigos');
        Schema::dropIfExists('mcp_clientes');
    }
};
