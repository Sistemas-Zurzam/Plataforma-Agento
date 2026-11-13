<?php

return [
    /*
    | Registrar también las lecturas (GET). Por defecto solo se registran las
    | operaciones que modifican datos, los accesos denegados (401/403) y las
    | lecturas sensibles (exportaciones/descargas). La navegación y los clicks
    | de la interfaz llegan aparte desde el frontend (tipo "ui").
    */
    'registrar_lecturas' => env('AUDITORIA_REGISTRAR_LECTURAS', false),

    // Fragmentos de URL cuya lectura (GET) siempre se registra.
    'lecturas_sensibles' => ['export', 'excel', 'descargar', 'pdf', 'reporte', 'boleta', 'plame', 'telecredito', 'documentos'],

    // Rutas (sin el prefijo "api/") que nunca se registran: ruido o auto-referencia.
    'rutas_excluidas' => ['auditoria/*', 'refresh', 'me'],

    // Claves cuyo valor jamás se almacena (comparación por fragmento, sin mayúsculas).
    'claves_sensibles' => ['password', 'contrasena', 'token', 'secret', 'authorization', 'api_key', 'apikey', 'remember', 'codigo_verificacion', 'otp', 'cvv'],

    // Tope de tamaño (bytes) del JSON de payload y cantidad de cambios de modelo por request.
    'max_payload_bytes' => 16384,
    'max_cambios_por_request' => 50,
];
