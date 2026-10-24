<?php

namespace App\Enums;

enum TipoPedido: string
{
    case Oc = 'oc';                 // el cliente mandó orden de compra
    case NotaVenta = 'nota_venta';  // venta sin OC (etiqueta "NOTA DE VENTA" en Trello)

    public function etiqueta(): string
    {
        return match ($this) {
            self::Oc => 'Orden de compra',
            self::NotaVenta => 'Nota de venta',
        };
    }
}
