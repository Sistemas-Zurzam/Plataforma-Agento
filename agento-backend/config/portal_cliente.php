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

    'rango_maximo_dias' => [
        'calendario' => env('PORTAL_CLIENTE_RANGO_MAXIMO_CALENDARIO', 31),
        'listado' => env('PORTAL_CLIENTE_RANGO_MAXIMO_LISTADO', 92),
    ],

];
