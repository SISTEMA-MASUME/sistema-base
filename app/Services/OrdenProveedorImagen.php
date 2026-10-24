<?php

namespace App\Services;

use App\Models\OrdenProveedor;
use App\Support\LinkCompra;
use App\Support\Precios;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * La OC al proveedor como IMAGEN (PNG) para mandarla por WhatsApp: se ve completa sin abrir un PDF y está
 * pensada para leerse en el celular. El MODELO/SKU va grande y resaltado para que Ernesto y Hugo encuentren la
 * pieza. Se dibuja con GD (ya lo usa el sistema para las fotos) y la fuente DejaVu que trae mPDF: sin binarios.
 * Igual que el PDF: nunca lleva precio de venta ni el nombre de nuestro cliente.
 */
class OrdenProveedorImagen
{
    private const ANCHO = 1080;

    private const MARGEN = 40;

    private $img;

    private array $colores = [];

    private string $fuente;

    private string $negrita;

    public static function png(OrdenProveedor $o): string
    {
        return (new self)->generar($o);
    }

    public function generar(OrdenProveedor $o): string
    {
        if (! function_exists('imagettftext')) {
            throw new RuntimeException('El servidor no puede dibujar texto en imágenes (falta FreeType en GD).');
        }
        $dir = base_path('vendor/mpdf/mpdf/ttfonts/');
        $this->fuente = $dir.'DejaVuSans.ttf';
        $this->negrita = $dir.'DejaVuSans-Bold.ttf';

        $o->loadMissing(['proveedor', 'pedido', 'user']);
        $partidas = $o->partidas()->get();
        $iva = Precios::iva();
        $totales = Precios::totales([$partidas->sum(fn ($p) => (float) $p->costo * (float) $p->cantidad)]);
        $datosProv = $o->proveedor?->datosContacto() ?? ['nombre' => null, 'telefono' => null];
        $atendio = $partidas->pluck('proveedor_vendedor')->map(fn ($v) => trim((string) $v))->filter()->unique()->implode(', ');

        // Dos pasadas: la primera solo mide el alto; la segunda dibuja.
        $alto = $this->dibujar($o, $partidas, $iva, $totales, $datosProv, $atendio, false);
        $this->img = imagecreatetruecolor(self::ANCHO, $alto);
        $this->colores = [
            'fondo' => imagecolorallocate($this->img, 255, 255, 255),
            'marino' => imagecolorallocate($this->img, 0x12, 0x23, 0x5A),
            'naranja' => imagecolorallocate($this->img, 0xEF, 0x7D, 0x22),
            'naranjaClaro' => imagecolorallocate($this->img, 0xFF, 0xED, 0xD5),
            'texto' => imagecolorallocate($this->img, 0x1E, 0x29, 0x3B),
            'gris' => imagecolorallocate($this->img, 0x64, 0x74, 0x8B),
            'linea' => imagecolorallocate($this->img, 0xE2, 0xE8, 0xF0),
            'caja' => imagecolorallocate($this->img, 0xF1, 0xF5, 0xF9),
            'blanco' => imagecolorallocate($this->img, 255, 255, 255),
        ];
        imagefill($this->img, 0, 0, $this->colores['fondo']);
        $this->dibujar($o, $partidas, $iva, $totales, $datosProv, $atendio, true);

        ob_start();
        imagepng($this->img, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($this->img);

        return $png;
    }

    /** Dibuja (o solo mide si $pintar = false) y devuelve el alto usado. */
    private function dibujar(OrdenProveedor $o, $partidas, float $iva, array $totales, array $datosProv, string $atendio, bool $pintar): int
    {
        $m = self::MARGEN;
        $ancho = self::ANCHO - 2 * $m;
        $y = 0;

        // Encabezado
        if ($pintar) {
            imagefilledrectangle($this->img, 0, 0, self::ANCHO, 150, $this->colores['marino']);
            $this->texto('MASUME INDUSTRIAL', $m, 62, 30, 'blanco', true);
            $this->texto('ORDEN DE COMPRA', $m, 108, 20, 'naranja', true);
            $this->texto($o->folioTexto(), self::ANCHO - $m - $this->anchoTexto($o->folioTexto(), 40, true), 78, 40, 'blanco', true);
            $fecha = 'Fecha '.$o->created_at?->timezone('America/Mexico_City')->format('d/m/Y').'  ·  Elaboró '.($o->user?->nombreReal() ?? '');
            $this->texto($fecha, self::ANCHO - $m - $this->anchoTexto($fecha, 17), 120, 17, 'blanco');
        }
        $y = 150 + 30;

        // Proveedor y condiciones
        $lineas = array_filter([
            ['PROVEEDOR: '.($o->proveedor?->nombre ?? ''), 24, true],
            $datosProv['nombre'] ? ['Contacto: '.$datosProv['nombre'], 20, false] : null,
            $atendio !== '' ? ['Atendió: '.$atendio, 20, false] : null,
            ['Método de pago: '.$o->metodo_pago.($o->pedido ? '   ·   Referencia: Pedido P-'.$o->pedido->folio : ''), 20, true],
        ]);
        // Primero se acomodan las líneas para saber el alto de la caja; luego caja y texto encima.
        $acomodadas = [];
        foreach ($lineas as [$t, $tam, $neg]) {
            foreach ($this->envolver($t, $tam, $neg, $ancho - 40) as $l) {
                $acomodadas[] = [$l, $tam, $neg];
            }
        }
        $altoCaja = 14 + array_sum(array_map(fn ($a) => $a[1] + 14, $acomodadas)) + 20;
        if ($pintar) {
            imagefilledrectangle($this->img, $m, $y, $m + $ancho, $y + $altoCaja, $this->colores['caja']);
        }
        $yy = $y + 14;
        foreach ($acomodadas as [$l, $tam, $neg]) {
            $yy += $tam + 14;
            if ($pintar) {
                $this->texto($l, $m + 20, $yy, $tam, 'texto', $neg);
            }
        }
        $y += $altoCaja + 24;

        if (filled($o->comentarios)) {
            foreach ($this->envolver('Comentarios: '.$o->comentarios, 20, true, $ancho) as $l) {
                $y += 34;
                if ($pintar) {
                    $this->texto($l, $m, $y, 20, 'naranja', true);
                }
            }
            $y += 20;
        }

        // Partidas: una tarjeta por partida, con el MODELO/SKU resaltado
        foreach ($partidas as $i => $p) {
            $top = $y;
            $foto = $this->foto($p->imagen_path);
            $xTexto = $m + 20 + ($foto ? 200 : 0);
            $anchoTexto = $m + $ancho - 20 - $xTexto;
            $y += 16;

            $y += 30;
            if ($pintar) {
                $this->texto(($i + 1).'.', $xTexto, $y, 22, 'gris', true);
            }
            foreach ($this->envolver((string) $p->descripcion, 22, true, $anchoTexto - 40) as $k => $l) {
                if ($k > 0) {
                    $y += 32;
                }
                if ($pintar) {
                    $this->texto($l, $xTexto + 40, $y, 22, 'texto', true);
                }
            }
            if (filled($p->marca)) {
                $y += 32;
                if ($pintar) {
                    $this->texto('Marca: '.$p->marca, $xTexto + 40, $y, 19, 'gris');
                }
            }

            $modelo = LinkCompra::esUrl($p->modelo) ? '' : trim((string) $p->modelo);
            if ($modelo !== '') {
                $y += 20;
                $etiqueta = 'MODELO / SKU: '.$modelo;
                $alto = 58;
                if ($pintar) {
                    $w = min($anchoTexto - 40, $this->anchoTexto($etiqueta, 28, true) + 36);
                    imagefilledrectangle($this->img, $xTexto + 40, $y, $xTexto + 40 + $w, $y + $alto, $this->colores['naranjaClaro']);
                    imagefilledrectangle($this->img, $xTexto + 40, $y, $xTexto + 46, $y + $alto, $this->colores['naranja']);
                    $this->texto($etiqueta, $xTexto + 58, $y + 41, 28, 'marino', true);
                }
                $y += $alto;
            }

            $cant = rtrim(rtrim(number_format((float) $p->cantidad, 3, '.', ''), '0'), '.').' '.$p->unidad;
            $unit = (float) $p->costo * (1 + $iva);
            $y += 46;
            if ($pintar) {
                $this->texto('Cantidad: '.$cant, $xTexto + 40, $y, 24, 'marino', true);
                $precio = '$'.number_format($unit, 2).' c/u  ·  $'.number_format($unit * (float) $p->cantidad, 2);
                $this->texto($precio, $m + $ancho - 20 - $this->anchoTexto($precio, 20), $y, 20, 'gris');
            }
            $y += 22;
            if ($foto) {
                $y = max($y, $top + 16 + 180 + 16);
            }
            if ($pintar) {
                imagerectangle($this->img, $m, $top, $m + $ancho, $y, $this->colores['linea']);
                if ($foto) {
                    $this->pegarFoto($foto, $m + 20, $top + 16, 180);
                }
            }
            if ($foto) {
                imagedestroy($foto);
            }
            $y += 16;
        }

        // Totales (costos con IVA; nunca precio de venta)
        $y += 20;
        foreach ([['Subtotal', $totales['subtotal'], false], ['IVA', $totales['iva'], false], ['TOTAL', $totales['total'], true]] as [$t, $v, $neg]) {
            $y += $neg ? 48 : 36;
            if ($pintar) {
                $tam = $neg ? 30 : 21;
                $valor = '$'.number_format((float) $v, 2);
                $this->texto($t, self::ANCHO - $m - 420, $y, $tam, $neg ? 'marino' : 'gris', $neg);
                $this->texto($valor, self::ANCHO - $m - $this->anchoTexto($valor, $tam, $neg), $y, $tam, $neg ? 'marino' : 'texto', $neg);
            }
        }
        $y += 40;
        $pie = config('masume.empresa.nombre').' · '.config('masume.empresa.correo').' · '.config('masume.empresa.telefono');
        if ($pintar) {
            $this->texto($pie, $m, $y, 16, 'gris');
        }

        return $y + 30;
    }

    // ------------------------------------------------------------------ utilidades de dibujo

    private function texto(string $t, int $x, int $y, int $tam, string $color, bool $negrita = false): void
    {
        imagettftext($this->img, $tam * 0.75, 0, $x, $y, $this->colores[$color], $negrita ? $this->negrita : $this->fuente, $t);
    }

    private function anchoTexto(string $t, int $tam, bool $negrita = false): int
    {
        $c = imagettfbbox($tam * 0.75, 0, $negrita ? $this->negrita : $this->fuente, $t);

        return (int) abs($c[2] - $c[0]);
    }

    /** @return list<string> líneas que caben en $ancho px */
    private function envolver(string $t, int $tam, bool $negrita, int $ancho): array
    {
        $lineas = [];
        $actual = '';
        foreach (preg_split('/\s+/u', trim($t)) ?: [] as $palabra) {
            $prueba = $actual === '' ? $palabra : $actual.' '.$palabra;
            if ($actual !== '' && $this->anchoTexto($prueba, $tam, $negrita) > $ancho) {
                $lineas[] = $actual;
                $actual = $palabra;
            } else {
                $actual = $prueba;
            }
        }
        if ($actual !== '') {
            $lineas[] = $actual;
        }

        return $lineas ?: [''];
    }

    private function foto(?string $path)
    {
        if (! $path) {
            return null;
        }
        $ruta = Storage::disk('public')->path($path);
        $img = is_file($ruta) ? @imagecreatefromstring((string) file_get_contents($ruta)) : false;

        return $img ?: null;
    }

    private function pegarFoto($foto, int $x, int $y, int $lado): void
    {
        $w = imagesx($foto);
        $h = imagesy($foto);
        $esc = min($lado / $w, $lado / $h);
        $nw = (int) round($w * $esc);
        $nh = (int) round($h * $esc);
        imagecopyresampled($this->img, $foto, $x + intdiv($lado - $nw, 2), $y + intdiv($lado - $nh, 2), 0, 0, $nw, $nh, $w, $h);
    }
}
