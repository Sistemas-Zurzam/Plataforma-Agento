<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Timezone operativo de Asistencia
    |--------------------------------------------------------------------------
    |
    | Fecha calendario que usan las reglas de negocio de Asistencia para
    | decidir "qué día es hoy" — cobertura, cierre de período, si una fecha
    | es futura. Deliberadamente independiente del timezone TÉCNICO de la
    | app (config('app.timezone'), UTC): base de datos, timestamps, colas y
    | JWT siguen operando en UTC sin cambios.
    |
    | Agento opera hoy exclusivamente en Perú. Si en el futuro pasa a ser
    | multi-país, esto debería resolverse por empresa en vez de una única
    | configuración global (backlog, no implementado en esta fase).
    |
    */

    'operational_timezone' => env('AGENTO_OPERATIONAL_TIMEZONE', 'America/Lima'),

];
