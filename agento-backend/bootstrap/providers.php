<?php

use App\Modules\Auditoria\AuditoriaServiceProvider;
use App\Modules\Reclutamiento\ReclutamientoServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    AuditoriaServiceProvider::class,
    ReclutamientoServiceProvider::class,
];
