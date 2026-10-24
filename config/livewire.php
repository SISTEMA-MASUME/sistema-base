<?php

// Solo se sobreescribe 'temporary_file_upload'. Se repite el bloque completo porque Laravel
// reemplaza la llave entera al combinar la configuración del paquete.
return [
    'temporary_file_upload' => [
        'disk' => null,
        // Fotos de celular (evidencia de entrega): hasta 15 MB por archivo original.
        'rules' => ['required', 'file', 'max:20480'], // 20 MB: lo más grande que se acepta (Por cotizar)
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
