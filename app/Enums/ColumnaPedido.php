<?php

namespace App\Enums;

/** Columnas del tablero de pedidos: las mismas listas del Trello "ORDENES DE COMPRA". */
enum ColumnaPedido: string
{
    case OcRecibida = 'oc_recibida';
    case EnTramite = 'en_tramite';
    case ConTiempoEntrega = 'con_tiempo_entrega';
    case ChecarTema = 'checar_tema';
    case PorFacturar = 'por_facturar';
    case FacturadasPendientesEntregar = 'facturadas_pendientes_entregar';
    case EnviosEmbarcados = 'envios_embarcados';
    case EntregadosPorFacturar = 'entregados_por_facturar';
    case EntregadasCompletas = 'entregadas_completas';

    public function etiqueta(): string
    {
        return match ($this) {
            self::OcRecibida => 'OC recibida',
            self::EnTramite => 'En trámite / asignada',
            self::ConTiempoEntrega => 'Con tiempo de entrega',
            self::ChecarTema => 'Checar algún tema',
            self::PorFacturar => 'Por facturar',
            self::FacturadasPendientesEntregar => 'Facturadas, pendientes por entregar',
            self::EnviosEmbarcados => 'Envíos embarcados',
            self::EntregadosPorFacturar => 'Entregados por facturar (notas y cot.)',
            self::EntregadasCompletas => 'Entregadas y completas',
        };
    }
}
