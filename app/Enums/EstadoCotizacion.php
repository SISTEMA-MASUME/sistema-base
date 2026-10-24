<?php

namespace App\Enums;

enum EstadoCotizacion: string
{
    case Borrador = 'borrador';
    case Enviada = 'enviada';
    case Aceptada = 'aceptada';   // llegó OC → en el módulo 2 se vuelve tarjeta de pedido
    case Rechazada = 'rechazada';
    case Cancelada = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Enviada => 'Enviada',
            self::Aceptada => 'Aceptada (OC)',
            self::Rechazada => 'Rechazada',
            self::Cancelada => 'Cancelada',
        };
    }

    /** Clases de la etiqueta de color en la lista (gris borrador, azul enviada, verde aceptada, rojo rechazada). */
    public function colores(): string
    {
        return match ($this) {
            self::Borrador => 'bg-slate-100 text-slate-700',
            self::Enviada => 'bg-blue-100 text-blue-800',
            self::Aceptada => 'bg-green-100 text-green-800',
            self::Rechazada => 'bg-red-100 text-red-700',
            self::Cancelada => 'bg-slate-200 text-slate-500 line-through',
        };
    }
}
