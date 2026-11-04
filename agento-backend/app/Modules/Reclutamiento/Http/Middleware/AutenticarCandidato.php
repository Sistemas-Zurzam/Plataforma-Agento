<?php

namespace App\Modules\Reclutamiento\Http\Middleware;

use App\Modules\Reclutamiento\Application\AccesoCandidatoService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sesión del candidato en el portal público: token opaco emitido tras
 * verificar el correo (cabecera X-Candidato-Token). No usa JWT ni da acceso
 * a ninguna ruta administrativa.
 */
class AutenticarCandidato
{
    public function __construct(private readonly AccesoCandidatoService $accesos) {}

    public function handle(Request $request, Closure $next): Response
    {
        $acceso = $this->accesos->resolver($request->header('X-Candidato-Token'));

        if (! $acceso) {
            return response()->json(['message' => 'Verifica tu correo para continuar.'], 401);
        }

        $request->attributes->set('acceso_candidato', $acceso);

        return $next($request);
    }
}
