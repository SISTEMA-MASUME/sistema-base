<?php

return [
    'iva' => 0.16,

    // Último folio ya usado al arrancar. Pruebas: 0. Producción: el valor de CONFIG!B2 del REGISTRO.
    'folio_inicial' => [
        'cotizacion' => (int) env('FOLIO_INICIAL_COTIZACION', 0),
    ],

    'empresa' => [
        'nombre' => env('EMPRESA_NOMBRE', 'MASUME INDUSTRIAL'),
        'giro' => 'Ferretería, herramienta y refacciones industriales',
        'ciudad' => 'Ciudad, Estado',
        'web' => 'ejemplo.com',
        'correo' => 'ventas@ejemplo.com',
        'telefono' => '33 0000 0000',
    ],

    'cotizacion' => [
        'vigencia_dias' => 15,
        'condiciones_entrega' => '1 a 3 días hábiles',
        'max_partidas' => 150,
        // Textos fijos al pie del PDF (bloques con título y viñetas)
        'notas_pie' => [
            'NOTAS' => [
                'Precios sujetos a cambio sin previo aviso y a disponibilidad del proveedor.',
                'Tiempo de entrega 1 a 3 días hábiles, excepto partidas señaladas en naranja.',
            ],
            'ENTREGA A DOMICILIO' => [
                'Día siguiente en pedidos de $500 o más dentro de ZMG, y de $1,000 o más fuera de ZMG.',
                'Pedidos menores se programan en la siguiente ruta disponible.',
            ],
        ],
    ],

    // Cuentas de correo de salida (Hostinger SMTP). Cada usuario elige una en la pantalla Usuarios.
    // La clave del arreglo es el valor de users.cuenta_correo y el mailer se llama "smtp_<clave>"
    // (definido en config/mail.php). Para agregar otra cuenta: entrada aquí + mailer en mail.php + variables .env.
    'cuentas_correo' => [
        'ventas' => [
            'usuario' => env('CORREO_VENTAS_USUARIO'),
            'clave' => env('CORREO_VENTAS_CLAVE'),
        ],
        'ventas2' => [
            'usuario' => env('CORREO_VENTAS2_USUARIO'),
            'clave' => env('CORREO_VENTAS2_CLAVE'),
        ],
    ],
];
