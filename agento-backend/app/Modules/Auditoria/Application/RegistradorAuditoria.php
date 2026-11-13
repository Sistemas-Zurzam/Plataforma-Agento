<?php

namespace App\Modules\Auditoria\Application;

use App\Models\User;
use App\Modules\Auditoria\Models\AuditoriaEvento;
use App\Modules\Auditoria\Support\AuditoriaContexto;
use App\Modules\Auditoria\Support\SanitizadorAuditoria;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Escribe eventos en la bitácora. La empresa siempre sale del usuario
 * autenticado, nunca de datos enviados por el cliente.
 */
class RegistradorAuditoria
{
    public function __construct(
        private readonly SanitizadorAuditoria $sanitizador,
        private readonly AuditoriaContexto $contexto,
    ) {}

    /**
     * Registra la operación HTTP ya resuelta (login, logout o API autenticada).
     */
    public function deRequest(Request $request, Response $response, ?User $usuario, float $inicio): void
    {
        $estado = $response->getStatusCode();
        $esAuth = $request->isMethod('POST') && $request->is('api/login', 'api/logout');

        if (! $esAuth && ! $this->debeRegistrar($request, $estado, $usuario)) {
            return;
        }

        if ($request->is('api/login')) {
            $this->registrarLogin($request, $estado, $usuario, $inicio);

            return;
        }

        $ruta = $this->rutaLogica($request);
        $payload = $request->isMethod('GET') ? $request->query() : $request->except(['_method']);

        $this->crear($usuario, [
            'tipo' => $request->is('api/logout') ? AuditoriaEvento::TIPO_AUTH : AuditoriaEvento::TIPO_REQUEST,
            'accion' => $request->is('api/logout') ? 'auth.logout' : $request->method().' '.$ruta,
            'modulo' => Str::before($ruta, '/') ?: null,
            'metodo' => $request->method(),
            'ruta' => Str::limit($request->path(), 250, ''),
            'status_code' => $estado,
            'payload' => $this->sanitizador->payload($this->conArchivos($request, $payload)),
            'cambios' => $this->contexto->cambios(),
            'duracion_ms' => (int) round((microtime(true) - $inicio) * 1000),
            ...$this->recurso($request),
        ], $request);
    }

    /**
     * Eventos de interfaz (clicks y navegación) enviados por el frontend.
     *
     * @param  list<array{tipo: string, etiqueta: string, ruta?: ?string, elemento?: ?string, contexto?: ?string, ocurrido_en?: ?string}>  $eventos
     */
    public function eventosUi(User $usuario, Request $request, array $eventos): int
    {
        foreach ($eventos as $evento) {
            $this->crear($usuario, [
                'tipo' => AuditoriaEvento::TIPO_UI,
                'accion' => ($evento['tipo'] === 'navegacion' ? 'navegacion: ' : 'click: ').$evento['etiqueta'],
                'modulo' => $this->moduloDesdeRutaUi($evento['ruta'] ?? null),
                'ruta' => $evento['ruta'] ?? null,
                'payload' => $this->sanitizador->payload(array_filter([
                    'elemento' => $evento['elemento'] ?? null,
                    'contexto' => $evento['contexto'] ?? null,
                    'ocurrido_en' => $evento['ocurrido_en'] ?? null,
                ], fn ($v) => $v !== null)),
            ], $request);
        }

        return count($eventos);
    }

    private function registrarLogin(Request $request, int $estado, ?User $usuario, float $inicio): void
    {
        $identificador = (string) $request->input('login');
        $exitoso = $estado === 200;

        // El guard no conoce al usuario en /login (JWTAuth::attempt no lo fija):
        // se resuelve por el identificador, y así un intento fallido también
        // queda asociado a la empresa de esa cuenta para su administrador.
        $objetivo = $usuario ?? User::query()
            ->where(filter_var($identificador, FILTER_VALIDATE_EMAIL) ? 'email' : 'username', $identificador)
            ->first();

        $this->crear($objetivo, [
            'tipo' => AuditoriaEvento::TIPO_AUTH,
            'accion' => $exitoso ? 'auth.login' : 'auth.login_fallido',
            'modulo' => 'auth',
            'metodo' => 'POST',
            'ruta' => 'api/login',
            'status_code' => $estado,
            'payload' => ['login' => Str::limit($identificador, 150, '')],
            'duracion_ms' => (int) round((microtime(true) - $inicio) * 1000),
        ], $request, $objetivo === null ? $identificador : null);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function crear(?User $usuario, array $datos, Request $request, ?string $nombreAlterno = null): void
    {
        AuditoriaEvento::create([
            'empresa_id' => $usuario?->empresa_id,
            'user_id' => $usuario?->id,
            'usuario_nombre' => $usuario?->name ?? $nombreAlterno,
            'usuario_rol' => $usuario?->currentRole()?->clave,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ...$datos,
        ]);
    }

    private function debeRegistrar(Request $request, int $estado, ?User $usuario): bool
    {
        if ($usuario === null || $request->isMethod('OPTIONS') || $request->isMethod('HEAD')) {
            return false;
        }

        foreach (config('auditoria.rutas_excluidas', []) as $patron) {
            if ($request->is('api/'.$patron)) {
                return false;
            }
        }

        if (! $request->isMethod('GET')) {
            return true;
        }

        if (config('auditoria.registrar_lecturas') || in_array($estado, [401, 403], true)) {
            return true;
        }

        return $estado < 400 && Str::contains(Str::lower($request->path()), config('auditoria.lecturas_sensibles', []));
    }

    private function rutaLogica(Request $request): string
    {
        $uri = $request->route()?->uri() ?? $request->path();

        return Str::after($uri, 'api/');
    }

    /**
     * @return array{recurso_tipo?: string, recurso_id?: string}
     */
    private function recurso(Request $request): array
    {
        foreach ($request->route()?->parameters() ?? [] as $parametro) {
            if ($parametro instanceof Model) {
                return ['recurso_tipo' => class_basename($parametro), 'recurso_id' => (string) $parametro->getKey()];
            }
        }

        foreach ($request->route()?->parameters() ?? [] as $nombre => $valor) {
            if (is_scalar($valor)) {
                return ['recurso_tipo' => (string) $nombre, 'recurso_id' => Str::limit((string) $valor, 60, '')];
            }
        }

        return [];
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function conArchivos(Request $request, array $payload): array
    {
        foreach ($request->allFiles() as $campo => $archivo) {
            $payload[$campo] = $archivo;
        }

        return $payload;
    }

    private function moduloDesdeRutaUi(?string $ruta): ?string
    {
        $segmento = Str::before(ltrim((string) $ruta, '/'), '/');

        return $segmento !== '' ? Str::limit(Str::lower($segmento), 60, '') : null;
    }
}
