<?php

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use App\Models\OrdenProveedor;
use App\Models\Pedido;
use App\Models\SolicitudCotizacion;
use Illuminate\Http\RedirectResponse;

class IrAFolioController extends Controller
{
    /**
     * Atajo "Ir a folio" del menú: 90123 o COT 90123 = cotización, P-45 = pedido (aunque esté archivado),
     * OC-12 = orden al proveedor, S-8 = solicitud de Por cotizar.
     */
    public function __invoke(string $folio): RedirectResponse
    {
        $texto = mb_strtoupper(trim($folio));
        $campo = auth()->user()->esCampo();
        if (! preg_match('/^(P|OC|S|COT)?[\s.#:-]*0*(\d{1,9})$/', $texto, $m)) {
            return $this->noExiste($folio);
        }
        [$tipo, $numero] = [$m[1], (int) $m[2]];

        if ($tipo === 'P') {
            $pedido = Pedido::where('folio', $numero)->first();

            return $pedido ? redirect()->route('pedidos.index', ['pedido' => $pedido->id]) : $this->noExiste("P-{$numero}");
        }
        if ($tipo === 'OC') {
            $orden = OrdenProveedor::where('folio', $numero)->first();

            return $orden ? redirect()->route('ordenes-proveedor.ver', $orden) : $this->noExiste('OC-'.str_pad((string) $numero, 4, '0', STR_PAD_LEFT));
        }
        // Cotizaciones y solicitudes traen precios de venta: el personal de campo no las abre.
        if ($campo) {
            return back(fallback: route('pedidos.index'))->with('error', 'Desde aquí solo puedes abrir pedidos (P-45) y órdenes a proveedores (OC-12).');
        }
        if ($tipo === 'S') {
            $solicitud = SolicitudCotizacion::where('folio', $numero)->first();

            return $solicitud ? redirect()->route('solicitudes.ver', $solicitud) : $this->noExiste("S-{$numero}");
        }
        $cot = Cotizacion::where('folio', $numero)->first();

        return $cot ? redirect()->route('cotizaciones.editar', $cot) : $this->noExiste("la cotización {$numero}");
    }

    private function noExiste(string $que): RedirectResponse
    {
        return back(fallback: url('/'))->with('error', "No encontré {$que}. Escribe el número de la cotización, o P-45 para un pedido, OC-12 para una orden o S-8 para una solicitud.");
    }
}
