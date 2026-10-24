<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Varios contactos (vendedores) por proveedor: si el vendedor se va, solo se cambia el contacto. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedor_contactos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('puesto')->nullable();
            $table->string('correo')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->boolean('principal')->default(false);
            $table->timestamps();
            $table->index(['proveedor_id', 'principal']);
        });

        // El contacto que ya tenía cada proveedor pasa a ser su contacto principal.
        $ahora = now();
        foreach (DB::table('proveedores')->whereNotNull('contacto')->where('contacto', '!=', '')->get(['id', 'contacto', 'telefono', 'correo']) as $p) {
            DB::table('proveedor_contactos')->insert([
                'proveedor_id' => $p->id, 'nombre' => mb_substr($p->contacto, 0, 255), 'telefono' => $p->telefono,
                'correo' => $p->correo, 'principal' => true, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('proveedor_contactos');
    }
};
