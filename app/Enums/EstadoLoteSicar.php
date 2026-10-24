<?php

namespace App\Enums;

/** Ciclo de un lote que la web manda a SICAR por el puente de la PC de Daniel. */
enum EstadoLoteSicar: string
{
    case Pendiente = 'pendiente';     // esperando que la PC haga el simulacro
    case Simulado = 'simulado';       // la PC ya simuló; falta que alguien confirme
    case Confirmado = 'confirmado';   // confirmado; esperando que la PC guarde de verdad
    case Aplicado = 'aplicado';       // guardado en SICAR
    case Error = 'error';
    case Cancelado = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Esperando simulacro',
            self::Simulado => 'Simulacro listo: falta confirmar',
            self::Confirmado => 'Confirmado: guardando en SICAR',
            self::Aplicado => 'Guardado en SICAR',
            self::Error => 'Con error',
            self::Cancelado => 'Cancelado',
        };
    }

    /** Clases Tailwind del chip. */
    public function color(): string
    {
        return match ($this) {
            self::Pendiente => 'bg-slate-200 text-slate-800',
            self::Simulado => 'bg-yellow-100 text-yellow-800',
            self::Confirmado => 'bg-blue-100 text-blue-800',
            self::Aplicado => 'bg-green-100 text-green-800',
            self::Error => 'bg-red-100 text-red-800',
            self::Cancelado => 'bg-slate-100 text-slate-500',
        };
    }

    /** Estados que ocupan a sus pedidos (un pedido no puede estar en dos lotes abiertos). */
    public static function abiertos(): array
    {
        return [self::Pendiente, self::Simulado, self::Confirmado];
    }
}
