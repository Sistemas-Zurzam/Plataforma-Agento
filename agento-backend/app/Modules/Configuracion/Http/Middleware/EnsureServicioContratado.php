<?php

namespace App\Modules\Configuracion\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe la ruta a empresas que contrataron el servicio indicado
 * (planilla o reclutamiento). La empresa es la activa del usuario
 * autenticado, nunca un valor del request. Uso: ->middleware('servicio:reclutamiento')
 */
class EnsureServicioContratado
{
    private const NOMBRES = ['planilla' => 'planilla', 'reclutamiento' => 'reclutamiento y selección'];

    public function handle(Request $request, Closure $next, string $servicio): Response
    {
        $empresa = $request->user('api')?->empresa;

        if (! $empresa || ! $empresa->tieneServicio($servicio)) {
            return response()->json([
                'message' => 'La empresa activa no tiene contratado el servicio de '.(self::NOMBRES[$servicio] ?? $servicio).'.',
                'servicio_no_contratado' => $servicio,
            ], 403);
        }

        return $next($request);
    }
}
