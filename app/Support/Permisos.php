<?php

namespace App\Support;

/**
 * Pantallas de finanzas que el admin le puede abrir a cada usuario (Usuarios → palomitas). Por defecto
 * nadie más que el admin las ve. Se agregan conforme se publica cada fase del módulo de finanzas.
 */
final class Permisos
{
    public const LISTA = [
        'tablero' => 'Dashboard y gráficas del negocio',
        'ventas' => 'Ventas: facturas, pagos de clientes y estatus del portal',
        'cobranza' => 'Cobranza: quién debe, vencidas y a quién llamar',
        'nomina' => 'Nómina y comisiones: sueldos, comisiones, préstamos y reparto',
        'gastos' => 'Gastos: captura y consulta de gastos',
        'finanzas' => 'Mis finanzas: saldos, tarjetas y patrimonio',
    ];
}
