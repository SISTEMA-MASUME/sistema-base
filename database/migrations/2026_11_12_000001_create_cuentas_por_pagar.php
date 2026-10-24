<?php

use App\Support\Nombres;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "A quién le debo": compras a crédito a proveedores (desde la OC con método CRÉDITO o capturadas a mano) y sus pagos
 * con comprobante. Marca los proveedores que nos venden a crédito, carga las deudas iniciales
 * y desactiva las cuentas "DEUDA ..." de Mis finanzas, que ahora salen de aquí (no se cuentan dos veces).
 */
return new class extends Migration
{
    private const A_CREDITO = [];

    /** Deudas iniciales (vacío en la copia de ejemplo): proveedor => [folio, fecha, vence, importe, descripción]. */
    private const DEUDAS = [];

    public function up(): void
    {
        Schema::table('proveedores', fn (Blueprint $table) => $table->boolean('vende_a_credito')->default(false)->after('notas'));

        Schema::create('pagos_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->date('fecha');
            $table->foreignId('cuenta_id')->nullable()->constrained('cuentas')->nullOnDelete();   // de qué cuenta salió
            $table->string('referencia', 120)->nullable();
            $table->decimal('monto', 14, 2);
            $table->string('comprobante_path')->nullable();                                        // disco local privado
            $table->string('comprobante_nombre')->nullable();
            $table->string('comprobante_mime', 100)->nullable();
            $table->json('detalle')->nullable();                                                   // qué deudas liquidó (folio e importe)
            $table->foreignId('user_id')->constrained('users');
            $table->timestamp('anulado_at')->nullable();                                           // "fue un error": las deudas regresan
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cuentas_por_pagar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->foreignId('orden_proveedor_id')->nullable()->unique()->constrained('ordenes_proveedor')->nullOnDelete();
            $table->string('folio', 60)->nullable();                                               // factura del proveedor u OC
            $table->string('descripcion', 255)->nullable();
            $table->date('fecha');
            $table->date('vence')->nullable();                                                     // opcional: no hace falta saber los días
            $table->decimal('importe', 14, 2);                                                     // con IVA: lo que se paga
            $table->string('estatus', 20)->default('pendiente')->index();                          // pendiente | pagada | cancelada
            $table->foreignId('pago_id')->nullable()->constrained('pagos_proveedor')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['proveedor_id', 'estatus']);
        });

        $llaves = array_map(fn ($n) => Nombres::llave($n), self::A_CREDITO);
        DB::table('proveedores')->whereIn('nombre_llave', $llaves)->update(['vende_a_credito' => true]);

        foreach (self::DEUDAS as $nombre => $deudas) {
            $proveedorId = DB::table('proveedores')->where('nombre_llave', Nombres::llave($nombre))->value('id');
            if (! $proveedorId) {
                continue;   // en una base sin ese proveedor (pruebas) no se carga nada
            }
            foreach ($deudas as [$folio, $fecha, $vence, $importe, $descripcion]) {
                if (DB::table('cuentas_por_pagar')->where('proveedor_id', $proveedorId)->where('folio', $folio)->exists()) {
                    continue;
                }
                DB::table('cuentas_por_pagar')->insert([
                    'proveedor_id' => $proveedorId, 'folio' => $folio, 'descripcion' => $descripcion, 'fecha' => $fecha, 'vence' => $vence,
                    'importe' => $importe, 'estatus' => 'pendiente', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // Las deudas a proveedores ya no se capturan como cuenta en Mis finanzas: salen de "A quién le debo".
        DB::table('cuentas')->where('tipo', 'prestamo')->where('nombre', 'like', 'DEUDA %')->update(['activa' => false]);
    }

    public function down(): void
    {
        DB::table('cuentas')->where('tipo', 'prestamo')->where('nombre', 'like', 'DEUDA %')->update(['activa' => true]);
        Schema::dropIfExists('cuentas_por_pagar');
        Schema::dropIfExists('pagos_proveedor');
        Schema::table('proveedores', fn (Blueprint $table) => $table->dropColumn('vende_a_credito'));
    }
};
