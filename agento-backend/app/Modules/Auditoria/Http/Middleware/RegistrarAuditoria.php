<?php

namespace App\Modules\Auditoria\Http\Middleware;

use App\Models\User;
use App\Modules\Auditoria\Application\RegistradorAuditoria;
use App\Modules\Auditoria\Support\AuditoriaContexto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Registra en la bitácora cada operación de la API. Escribe en terminate()
 * (después de enviar la respuesta) y nunca debe romper la request: un fallo
 * de auditoría se reporta pero no afecta al usuario.
 */
class RegistrarAuditoria
{
    public function __construct(
        private readonly RegistradorAuditoria $registrador,
        private readonly AuditoriaContexto $contexto,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('auditoria_inicio', microtime(true));
        $this->contexto->activar();

        // Se resuelve antes de ejecutar la request: en /logout el token queda
        // invalidado y después ya no sería posible identificar al usuario.
        try {
            $usuario = $request->bearerToken() ? auth('api')->user() : null;
        } catch (Throwable) {
            $usuario = null;
        }
        $request->attributes->set('auditoria_usuario', $usuario);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $usuario = $request->attributes->get('auditoria_usuario');

            $this->registrador->deRequest(
                $request,
                $response,
                $usuario instanceof User ? $usuario : null,
                (float) $request->attributes->get('auditoria_inicio', microtime(true)),
            );
        } catch (Throwable $e) {
            report($e);
        } finally {
            $this->contexto->desactivar();
        }
    }
}
