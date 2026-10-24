<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use App\Models\Contacto;
use App\Models\Cotizacion;
use App\Models\CotizacionPartida;
use App\Models\User;
use App\Services\CotizacionPdf;
use App\Support\Precios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class PdfMuestra extends Command
{
    protected $signature = 'pdf:muestra';

    protected $description = 'Genera storage/app/muestra.pdf con 25 partidas de prueba (no deja datos en la BD)';

    /** [imagen, descripción, marca, modelo, cantidad, precio, entrega ('lenta' = 3-5 días, naranja)] */
    private const ARTICULOS = [
        ['8860', 'Juego de 22 Llaves Hexagonales Tipo “L”, Combinadas, Punta de Bola', 'Urrea', '49822', 2, 596.55, 'lenta'],
        ['8858', 'Pinza para Mecánico de 6-3/8″', 'Urrea', '276', 5, 168.97],
        ['8856', 'Marro de Bronce Anti-chispas con Mango Madera de Encino de 14″, 1.6 LB', 'Urrea', '1430', 1, 878.45],
        ['8854', 'Juego de 10 Destornilladores con Mango Ámbar, Combinados', 'Urrea', 'JBUD02', 3, 453.45],
        ['8852', 'Juego de 6 Destornilladores con Mango Ámbar, Punta Phillips', 'Urrea', '9600A', 4, 344.83, '1-2 días hábiles'],
        ['8850', 'Flexómetro con Carcasa de Caucho Anti-impacto 5 M X 27 MM', 'Urrea', '1586HD', 10, 244.83],
        ['8848', 'Juego de 6 Destornilladores con Mango Ámbar, Combinados', 'Urrea', '9600B', 2, 382.76],
        ['8846', 'Pistola Calafateadora con Sistema Antigoteo', 'Urrea', 'PSFU1', 6, 448.28, 'lenta'],
        ['8844', 'Juego de 10 Llaves Hexagonales Cortas Tipo “T” Ergonómicas, Métricas', 'Urrea', '4965GPM', 1, 1203.45, '1-2 días hábiles'],
        ['8842', 'Juego de Dados y Accesorios Cuadro de 1/4″ Métrico, 18 Piezas', 'Urrea', '47205', 2, 1149.14],
        ['8840', 'Juego de 7 Llaves Hexagonales Tipo “L”, Métricas', 'Urrea', '4972M', 3, 202.59],
        ['8838', 'Pinza de Extensión 19 Posiciones de 8″ Quick Release', 'Urrea', '274GI', 1, 301.72],
        ['8836', 'Llave Ajustable Cromada 8″', 'Urrea', '708', 8, 315.52, '1-2 días hábiles'],
        ['8834', 'Llave Ajustable Cromada 10″', 'Urrea', '710', 12, 389.66],
        ['8832', 'Flexómetro con Carcasa de Acero Inoxidable 5 M X 30 MM', 'Urrea', '1595LSW', 4, 171.55, 'lenta'],
        ['8830', 'Extensión Flexible para Inyector de Grasa de 45.7 CM', 'Urrea', '23610', 2, 127.59],
        ['8828', 'Juego de 9 Llaves Hexagonales Tipo “L”, Métricas, Punta de Bola', 'Urrea', '4996', 1, 283.62, '1-2 días hábiles'],
        ['8826', 'Juego de 13 Llaves Hexagonales Tipo “L”, en Pulgadas', 'Urrea', '4972R', 1, 262.07],
        ['8824', 'Juego de 8 Destornilladores con Mango Trimaterial 1000 V, Combinados', 'Urrea', '9700TA', 2, 1008.62],
        ['8817', 'Taladro/destornillador 1/2′, 20V, 1 batería 2Ah, TRUPER PRO', 'Truper', '20A2', 1, 1659.48],
        ['8809', 'Soldadora inversora 200A, bi-voltaje, Serie 500', 'Pretul', '520BP', 1, 2198.28, '1-2 días hábiles'],
        ['8801', 'Mini soldadora inversora 120A, 127 V, Serie 500', 'Pretul', '512MP', 3, 1137.93, 'lenta'],
        ['8793', 'Mini soldadora inversora 130A, bi-voltaje 127/220V', 'Truper', '120MBT', 2, 1465.52],
        ['8785', 'Soldadora inversora 130A, 127V', 'Truper', '130T', 1, 1616.38],
        ['8777', 'Soldadora inversora 100A, 127V', 'Truper', '100T', 2, 1336.21, '1-2 días hábiles'],
    ];

    public function handle(): int
    {
        $copiadas = [];
        $bytes = null;

        DB::beginTransaction();
        try {
            $vendedor = User::factory()->create(['name' => 'Rosa Ruiz']);
            $cliente = Cliente::factory()->create([
                'nombre' => 'CLIENTE DE PRUEBA',
                'razon_social' => 'CLIENTE DE PRUEBA, S.A. DE C.V.',
                'direccion' => 'Av. Ejemplo 123, Col. Industrial, Guadalajara, Jal. 44940',
            ]);
            $contacto = Contacto::create([
                'cliente_id' => $cliente->id, 'nombre' => 'Juan Pérez', 'puesto' => 'Compras',
                'correo' => 'compras@clienteprueba.com', 'telefono' => '33 1234 5678',
            ]);
            $cotizacion = Cotizacion::create([
                'folio' => 10500, 'cliente_id' => $cliente->id, 'contacto_id' => $contacto->id,
                'vendedor_id' => $vendedor->id, 'fecha' => now()->toDateString(), 'vigencia_dias' => 15,
                'moneda' => 'MXN', 'forma_pago' => 'Crédito 30 días',
                'condiciones_entrega' => config('masume.cotizacion.condiciones_entrega'), 'estado' => 'borrador',
            ]);

            Storage::disk('public')->makeDirectory('partidas');
            foreach (self::ARTICULOS as $i => $a) {
                $imagen = 'partidas/muestra-'.$a[0].'.jpg';
                $origen = base_path('docs/diseno/img/'.$a[0].'.jpg');
                $hayImagen = is_file($origen);
                if ($hayImagen) {
                    File::copy($origen, Storage::disk('public')->path($imagen));
                    $copiadas[] = $imagen;
                }
                $lenta = ($a[6] ?? null) === 'lenta';
                $precio = round($a[5], 2);
                CotizacionPartida::create([
                    'cotizacion_id' => $cotizacion->id, 'orden' => $i + 1, 'incluir' => true,
                    'descripcion' => $a[1], 'marca' => $a[2], 'modelo' => $a[3], 'unidad' => 'PZA',
                    'cantidad' => $a[4], 'costo' => round($precio / 1.3, 4), 'utilidad_pct' => 30,
                    'precio' => $precio, 'importe' => Precios::importe($precio, $a[4]),
                    'tiempo_entrega' => $lenta ? '3-5 días hábiles' : ($a[6] ?? 'Inmediata'),
                    'entrega_lenta' => $lenta,
                    'imagen_path' => $hayImagen ? $imagen : null,
                ]);
            }
            $cotizacion->recalcularTotales();

            $bytes = CotizacionPdf::generar($cotizacion->fresh());
        } finally {
            DB::rollBack();
            foreach ($copiadas as $f) {
                Storage::disk('public')->delete($f);
            }
        }

        $ruta = storage_path('app/muestra.pdf');
        File::put($ruta, $bytes);
        $this->info('PDF de muestra generado: '.$ruta);

        return self::SUCCESS;
    }
}
