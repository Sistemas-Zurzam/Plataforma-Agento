<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Portal Cliente
    |--------------------------------------------------------------------------
    |
    | Apaga por completo el Portal Cliente mientras se termina de construir.
    | Con esto en false, cualquier ruta bajo /portal/* (ver middleware
    | EnsurePortalClienteHabilitado) responde 404, igual que una ruta
    | inexistente — no debe revelarse si el feature ya existe.
    |
    | Nunca leer env() fuera de este archivo: controladores, middleware y el
    | frontend siempre consultan config('portal_cliente.enabled') (o el
    | valor ya resuelto que /api/me expone como portal_cliente_habilitado),
    | para que esto siga funcionando igual con `php artisan config:cache`.
    |
    */

    'enabled' => env('PORTAL_CLIENTE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Rango máximo de fechas por consulta
    |--------------------------------------------------------------------------
    |
    | Ningún listado de solo lectura del portal acepta un rango ilimitado.
    | "calendario" es el tope estricto para datos día a día (calendario,
    | marcaciones, historial); "listado" es el tope más amplio para
    | registros dispersos (incidencias, horas extra, permisos, resumen).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Zona horaria del negocio
    |--------------------------------------------------------------------------
    |
    | La aplicación corre en UTC; las reglas del portal que dependen de "hoy"
    | (fecha de cese igual o posterior a hoy) usan la hora local del negocio.
    |
    */

    'zona_horaria_negocio' => env('AGENTO_ZONA_HORARIA_NEGOCIO', 'America/Lima'),

    /*
    |--------------------------------------------------------------------------
    | Solicitudes de cese
    |--------------------------------------------------------------------------
    |
    | Supuesto operativo: 1 a 5 evidencias PDF/JPG/PNG de hasta 10 MB cada
    | una (mismo tope que los documentos del legajo en Personas).
    |
    */

    'ceses' => [
        'max_evidencias' => 5,
        'max_kb' => env('PORTAL_CESE_EVIDENCIA_MAX_KB', 10240),
    ],

    'rango_maximo_dias' => [
        'calendario' => env('PORTAL_CLIENTE_RANGO_MAXIMO_CALENDARIO', 31),
        'listado' => env('PORTAL_CLIENTE_RANGO_MAXIMO_LISTADO', 92),
    ],

];
