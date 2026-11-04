<?php

namespace App\Modules\Reclutamiento\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reclutamiento\Application\AlcanceSeleccion;
use Illuminate\Http\Request;

abstract class SeleccionController extends Controller
{
    /**
     * Alcance del usuario autenticado (empresa del JWT, permisos y áreas).
     * Se guarda en el request para que los Resources decidan qué datos
     * sensibles exponer.
     */
    protected function alcance(Request $request): AlcanceSeleccion
    {
        // Un FormRequest es una instancia distinta del request base que
        // reciben los Resources: se guarda en el request de la aplicación.
        $base = app('request');
        if (! $base->attributes->has('alcance_seleccion')) {
            $base->attributes->set('alcance_seleccion', AlcanceSeleccion::de($request->user('api')));
        }
        $request->attributes->set('alcance_seleccion', $base->attributes->get('alcance_seleccion'));

        return $base->attributes->get('alcance_seleccion');
    }

    protected function porPagina(Request $request, int $defecto = 15): int
    {
        return max(1, min((int) $request->input('per_page', $defecto), 100));
    }
}
