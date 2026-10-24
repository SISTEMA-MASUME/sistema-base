<?php

namespace App\Enums;

enum EstadoOrdenProveedor: string
{
    case Emitida = 'emitida';       // generada, aún no compartida
    case Enviada = 'enviada';       // se compartió al proveedor (WhatsApp, correo o descarga)
    case Recibida = 'recibida';     // llegó todo el material
    case Cancelada = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Emitida => 'Emitida',
            self::Enviada => 'Enviada',
            self::Recibida => 'Recibida',
            self::Cancelada => 'Cancelada',
        };
    }

    /** A crédito: la compra se anota sola en "A quién le debo" (Finanzas) para que no se pase pagarla. */
    public const CREDITO = 'CRÉDITO';

    public const METODOS_PAGO = [self::CREDITO, 'TARJETA', 'TRANSFERENCIA', 'EFECTIVO', 'CHEQUE'];
}
