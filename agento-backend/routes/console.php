<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Selección y Reclutamiento. vencer-candidatos solo anonimiza si además
// RECLUTAMIENTO_RETENCION_ELIMINAR=true; si no, queda como ejecución de prueba.
Schedule::command('reclutamiento:cerrar-intentos-vencidos')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('reclutamiento:vencer-candidatos --ejecutar')->dailyAt('03:00')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
