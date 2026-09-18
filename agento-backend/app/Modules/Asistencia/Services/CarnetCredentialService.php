<?php

namespace App\Modules\Asistencia\Services;

use App\Models\User;
use App\Modules\Asistencia\Models\CredencialAcceso;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Genera/resuelve/revoca la credencial del carnet — el módulo de asistencia
 * es dueño de esta credencial porque su único propósito es marcar
 * asistencia (a diferencia de una identidad de login), igual que
 * AsistenciaAuditoria vive acá y no en un módulo genérico de seguridad.
 *
 * "Solo una credencial activa por colaborador+tipo" se garantiza a nivel de
 * BASE DE DATOS (columna generada `colaborador_id_si_activa` + unique — ver
 * la migración de creación de la tabla), no solo con el `lockForUpdate()` de
 * abajo. El lock por sí solo NO alcanza para la primera generación de un
 * colaborador (no hay ninguna fila previa que bloquear), así que dos
 * requests concurrentes ahí sí podían crear dos credenciales activas antes
 * de agregar el constraint — verificado con dos procesos PHP reales contra
 * MySQL. `generar()` reintenta una vez si choca contra ese unique: la
 * segunda vuelta encuentra la fila que ganó la carrera (ahora si existe) y
 * la revoca normalmente en vez de fallar.
 */
class CarnetCredentialService
{
    public function __construct(private readonly AsistenciaAuditoriaService $auditoria) {}

    /**
     * @return array{credencial: CredencialAcceso, token: string} `token` es
     *                                                            el valor PLANO a imprimir en el código de barras — se devuelve
     *                                                            únicamente en este momento; después solo existe su hash.
     */
    public function generar(Empresa $empresa, Colaborador $colaborador, User $generadoPor): array
    {
        if ($colaborador->empresa_id !== $empresa->id) {
            throw ValidationException::withMessages(['colaborador' => ['El colaborador no pertenece a la empresa activa.']]);
        }

        $intentosMaximos = 3;

        for ($intento = 1; $intento <= $intentosMaximos; $intento++) {
            try {
                return DB::transaction(function () use ($empresa, $colaborador, $generadoPor) {
                    $anterior = CredencialAcceso::query()
                        ->where('colaborador_id', $colaborador->id)
                        ->where('tipo', CredencialAcceso::TIPO_CARNET_CODIGO_BARRAS)
                        ->where('estado', CredencialAcceso::ESTADO_ACTIVA)
                        ->lockForUpdate()
                        ->first();

                    if ($anterior) {
                        $this->revocarCredencial($anterior, $generadoPor, 'Reemplazada por una nueva generación.');
                    }

                    $token = $this->generarToken();

                    $credencial = CredencialAcceso::create([
                        'empresa_id' => $empresa->id,
                        'colaborador_id' => $colaborador->id,
                        'tipo' => CredencialAcceso::TIPO_CARNET_CODIGO_BARRAS,
                        'token_hash' => $this->hash($token),
                        'estado' => CredencialAcceso::ESTADO_ACTIVA,
                        'generado_at' => now(),
                        'generado_por' => $generadoPor->id,
                    ]);

                    $this->auditoria->registrar(
                        $empresa->id, $generadoPor->id, 'credencial_generada', $credencial,
                        null, null, ['colaborador_id' => $colaborador->id, 'tipo' => $credencial->tipo],
                    );

                    return ['credencial' => $credencial, 'token' => $token];
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($intento === $intentosMaximos || ! str_contains($e->getMessage(), 'colaborador_credenciales_una_activa_unique')) {
                    throw $e;
                }
                // Otro request ganó la carrera de la primera generación (no
                // había fila previa que lockForUpdate() pudiera bloquear) —
                // la próxima vuelta ya la encuentra y la revoca como
                // cualquier regeneración normal.
            } catch (QueryException $e) {
                // Verificado con dos procesos PHP reales simultáneos contra
                // MySQL: la carrera de la PRIMERA generación no siempre
                // termina en UniqueConstraintViolationException — el motor
                // puede resolverla como deadlock/lock-wait-timeout
                // (SQLSTATE 40001 / código 1213, o 1205) según el orden de
                // llegada de ambas transacciones. SQLite nunca produce este
                // modo de falla (no tiene el mismo modelo de locks), así
                // que esto solo se detectó probando MySQL real.
                if ($intento === $intentosMaximos || ! in_array($e->getCode(), ['40001', 'HY000'], true)) {
                    throw $e;
                }
                usleep(random_int(10_000, 50_000));
            }
        }
    }

    /**
     * Regenerar es generar de nuevo: generar() ya revoca la vigente dentro
     * de su propia transacción, así que no hace falta un método separado
     * que duplique esa lógica.
     *
     * @return array{credencial: CredencialAcceso, token: string}
     */
    public function regenerar(Empresa $empresa, Colaborador $colaborador, User $usuario): array
    {
        return $this->generar($empresa, $colaborador, $usuario);
    }

    /** Credencial vigente del colaborador, o null si nunca se generó o la vigente fue revocada — sin exponer el hash ni el token. */
    public function activaPara(Colaborador $colaborador): ?CredencialAcceso
    {
        return CredencialAcceso::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('tipo', CredencialAcceso::TIPO_CARNET_CODIGO_BARRAS)
            ->where('estado', CredencialAcceso::ESTADO_ACTIVA)
            ->first();
    }

    public function revocar(Empresa $empresa, CredencialAcceso $credencial, User $usuario, string $motivo): CredencialAcceso
    {
        if ($credencial->empresa_id !== $empresa->id) {
            throw ValidationException::withMessages(['credencial' => ['La credencial no pertenece a la empresa activa.']]);
        }

        return DB::transaction(fn () => $this->revocarCredencial($credencial, $usuario, $motivo));
    }

    /**
     * Resuelve el token PLANO leído por el lector — lo hashea y busca por
     * hash, nunca compara el valor crudo contra nada persistido. Devuelve
     * null tanto si el código no existe, pertenece a otra empresa (filtrado
     * por el EmpresaScope del modelo) o está revocado — el llamador decide
     * el mensaje; esta capa no distingue el motivo para no filtrar
     * información entre empresas.
     */
    public function resolver(string $tokenPlano): ?CredencialAcceso
    {
        return CredencialAcceso::query()
            ->where('token_hash', $this->hash($tokenPlano))
            ->where('estado', CredencialAcceso::ESTADO_ACTIVA)
            ->first();
    }

    private function revocarCredencial(CredencialAcceso $credencial, User $usuario, string $motivo): CredencialAcceso
    {
        $credencial->update([
            'estado' => CredencialAcceso::ESTADO_REVOCADA,
            'revocado_at' => now(),
            'revocado_por' => $usuario->id,
            'motivo_revocacion' => $motivo,
        ]);

        $this->auditoria->registrar($credencial->empresa_id, $usuario->id, 'credencial_revocada', $credencial, $motivo);

        return $credencial;
    }

    /**
     * 20 dígitos numéricos (antes: 32 caracteres hex de `bin2hex(random_bytes(16))`).
     * Un token hexadecimal de 32 caracteres necesita Code128 Subset B (~11
     * módulos/carácter, ~365 módulos totales) — verificado con jsbarcode que
     * eso exige ~91mm de ancho a una resolución mínima escaneable, más del
     * ancho físico completo de una tarjeta CR80 (54mm). 20 dígitos permiten
     * Code128 Subset C (2 dígitos por símbolo, ~11 módulos cada 2) → 145
     * módulos totales, ~36-37mm a un ancho de módulo cómodamente legible
     * (0.25-0.28mm) — cabe en el bloque inferior de las plantillas actuales
     * (ver CarnetBarcode.jsx).
     *
     * `random_int()` sobre cada dígito (no `random_bytes()` + módulo, que
     * introduce sesgo estadístico salvo que el rango del byte sea múltiplo
     * exacto de 10) usa el mismo CSPRNG del sistema operativo que
     * `random_bytes()`, generando cada dígito 0-9 con probabilidad
     * uniforme. Se concatena como string desde el inicio — nunca pasa por
     * un entero — así que los ceros a la izquierda se conservan siempre.
     * Espacio total: 10^20 combinaciones, muy por encima de cualquier
     * colisión realista frente al puñado de colaboradores activos por
     * empresa (el unique de `token_hash` en la migración es la garantía
     * real de no-colisión, esto solo hace que sea estadísticamente
     * improbable necesitar ese reintento).
     */
    private function generarToken(): string
    {
        $digitos = '';
        for ($i = 0; $i < 20; $i++) {
            $digitos .= random_int(0, 9);
        }

        return $digitos;
    }

    private function hash(string $tokenPlano): string
    {
        return hash('sha256', $tokenPlano);
    }
}
