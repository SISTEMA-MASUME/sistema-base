<?php

namespace App\Enums;

/** Avance de cada partida de un pedido. El orden importa: cada estado implica los anteriores. */
enum EstadoPartidaPedido: string
{
    case Pendiente = 'pendiente';     // aún no se pide al proveedor
    case Solicitada = 'solicitada';   // ya tiene OC a proveedor
    case Recibida = 'recibida';       // ya se recogió (etiqueta "Recogida")
    case Entregada = 'entregada';     // se entregó al cliente

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Solicitada => 'Pedida al proveedor',
            self::Recibida => 'Recogida',
            self::Entregada => 'Entregada',
        };
    }

    public function nivel(): int
    {
        return array_search($this, self::cases(), true);
    }
}
