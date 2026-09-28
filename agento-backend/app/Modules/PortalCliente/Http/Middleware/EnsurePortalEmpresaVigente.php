<?php

namespace App\Modules\PortalCliente\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defensa explícita y centralizada para TODO /api/portal/* — no depende
 * solo de que EnsurePermission reciba un currentRole() nulo (que ya cubre
 * "sin fila en empresa_user" como efecto colateral) ni solo de leer
 * $user->empresa (una relación puede resolver un registro aunque ya no
 * represente acceso vigente). Verifica, en este orden:
 *
 *   1. El usuario tiene una empresa activa configurada (users.empresa_id).
 *   2. Esa empresa sigue activa (empresas.activa) — nadie revisaba esto en
 *      cada request, solo al momento de activarla.
 *   3. Sigue existiendo la relación en empresa_user, o es administrador
 *      global — User::tieneAccesoA() es el mismo punto único de
 *      verificación que ya usa el resto del código (EmpresaService, etc.).
 *
 * Los tres casos responden 403 (autenticado, pero sin autorización vigente
 * sobre esa empresa) — mismo criterio que EmpresaService::activarParaUsuario()
 * y EnsurePermission, nunca un 404 que sugeriría "recurso inexistente".
 */
class EnsurePortalEmpresaVigente
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user('api');
        $empresa = $usuario->empresa;

        abort_if(! $empresa, 403, 'No tienes una empresa activa configurada.');
        abort_if(! $empresa->activa, 403, 'La empresa activa ya no está disponible.');
        abort_unless($usuario->tieneAccesoA($empresa), 403, 'Ya no tienes acceso a la empresa activa.');

        return $next($request);
    }
}
