<?php

namespace App\Modules\Reclutamiento\Application;

use App\Modules\Reclutamiento\Infrastructure\CorreoReclutamiento;
use App\Modules\Reclutamiento\Models\AccesoCandidato;
use App\Modules\Reclutamiento\Models\CodigoVerificacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Identificación del candidato por correo verificado con código temporal.
 *
 * - La respuesta a "solicitar código" es siempre la misma: nunca revela si el
 *   correo existe o ya postuló.
 * - El código (6 dígitos) se guarda como HMAC, vence y tiene límite de
 *   intentos; el rate limiting por IP/correo está en las rutas.
 * - Verificar emite un token opaco de sesión (se guarda solo su hash) con
 *   vencimiento; el código de seguimiento de una postulación NO da acceso.
 */
class AccesoCandidatoService
{
    public const MENSAJE_GENERICO = 'Si el correo es válido, te enviamos un código de verificación.';

    public function __construct(private readonly CorreoReclutamiento $correo) {}

    public static function normalizarEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function solicitarCodigo(string $email, ?string $ip): void
    {
        $email = self::normalizarEmail($email);
        $emailHash = hash('sha256', $email);
        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $minutos = (int) config('reclutamiento.acceso.codigo_minutos_vigencia', 10);

        DB::transaction(function () use ($emailHash, $codigo, $minutos, $ip) {
            CodigoVerificacion::where('email_hash', $emailHash)->whereNull('consumido_at')->update(['consumido_at' => now()]);
            CodigoVerificacion::create([
                'email_hash' => $emailHash,
                'codigo_hash' => $this->hashCodigo($emailHash, $codigo),
                'expira_at' => now()->addMinutes($minutos),
                'ip' => $ip,
            ]);
        });

        $this->correo->enviar(
            $email,
            'Tu código de verificación',
            "Tu código para continuar en el portal de empleos es: {$codigo}\n\nVence en {$minutos} minutos. Si no lo solicitaste, ignora este mensaje.",
        );
    }

    /**
     * @return array{token: string, expira_at: string, email: string}
     */
    public function verificarCodigo(string $email, string $codigo): array
    {
        $email = self::normalizarEmail($email);
        $emailHash = hash('sha256', $email);
        $maxIntentos = (int) config('reclutamiento.acceso.codigo_max_intentos', 5);

        $resultado = DB::transaction(function () use ($emailHash, $codigo, $maxIntentos, $email) {
            $registro = CodigoVerificacion::where('email_hash', $emailHash)
                ->whereNull('consumido_at')
                ->where('expira_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $registro || $registro->intentos >= $maxIntentos) {
                return null;
            }

            if (! hash_equals($registro->codigo_hash, $this->hashCodigo($emailHash, $codigo))) {
                $registro->increment('intentos');
                if ($registro->intentos >= $maxIntentos) {
                    $registro->update(['consumido_at' => now()]);
                }

                return null;
            }

            $registro->update(['consumido_at' => now()]);
            $token = Str::random(64);
            $acceso = AccesoCandidato::create([
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'expira_at' => now()->addHours((int) config('reclutamiento.acceso.token_horas_vigencia', 12)),
            ]);

            return ['token' => $token, 'expira_at' => $acceso->expira_at->toIso8601String(), 'email' => $email];
        });

        if ($resultado === null) {
            throw ValidationException::withMessages(['codigo' => 'El código no es válido o ya venció. Solicita uno nuevo.']);
        }

        return $resultado;
    }

    public function resolver(?string $token): ?AccesoCandidato
    {
        if (blank($token)) {
            return null;
        }

        $acceso = AccesoCandidato::where('token_hash', hash('sha256', $token))
            ->whereNull('revocado_at')
            ->where('expira_at', '>', now())
            ->first();

        $acceso?->forceFill(['ultimo_uso_at' => now()])->save();

        return $acceso;
    }

    public function cerrarSesion(AccesoCandidato $acceso): void
    {
        $acceso->forceFill(['revocado_at' => now()])->save();
    }

    private function hashCodigo(string $emailHash, string $codigo): string
    {
        return hash_hmac('sha256', $emailHash.'|'.trim($codigo), (string) config('app.key'));
    }
}
