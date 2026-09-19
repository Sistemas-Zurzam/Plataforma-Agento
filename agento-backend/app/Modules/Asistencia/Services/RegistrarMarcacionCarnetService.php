<?php

namespace App\Modules\Asistencia\Services;

use App\Models\User;
use App\Modules\Asistencia\Application\ProcesarAsistenciaDiaria;
use App\Modules\Asistencia\Models\AsistenciaMarcacion;
use App\Modules\Asistencia\Support\FechaOperativa;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Orquesta el escaneo: resuelve credencial → colaborador → guarda la
 * marcación cruda → dispara ProcesarAsistenciaDiaria (el motor real).
 *
 * REGLA ARQUITECTÓNICA: este servicio NO decide entrada/salida, tardanza,
 * jornada nocturna ni nada de eso — eso sigue siendo responsabilidad
 * exclusiva de ProcesarAsistenciaDiaria/ResolverJornadaDiaria. Acá solo se
 * interpreta el AsistenciaResultadoDiario ya calculado para elegir qué
 * mensaje mostrarle al vigilante.
 */
class RegistrarMarcacionCarnetService
{
    public function __construct(
        private readonly CarnetCredentialService $credenciales,
        private readonly ProcesarAsistenciaDiaria $procesador,
        private readonly AsistenciaPeriodoService $periodos,
        private readonly AsistenciaAuditoriaService $auditoria,
        private readonly FechaOperativa $fechaOperativa,
        private readonly NotificarEstadoMotorizadoZazuService $notificadorZazu,
    ) {}

    /**
     * @return array{resultado: string, colaborador: array, hora: string, mensaje: string, origen: string}
     *
     * @throws ValidationException carnet no reconocido/revocado, colaborador
     *                             inactivo, o período no editable — el frontend distingue el caso por
     *                             la clave del mensaje (`credencial`, `colaborador`, `fecha_desde`).
     */
    public function registrar(Empresa $empresa, User $usuarioVigilancia, string $codigo): array
    {
        $credencial = $this->credenciales->resolver($codigo);

        // Un carnet de OTRA empresa nunca llega hasta acá: resolver() ya
        // filtra por el EmpresaScope del modelo, así que "no existe" y
        // "existe pero es de otra empresa" son indistinguibles desde este
        // punto — exactamente lo que pide no revelar información entre
        // empresas.
        if (! $credencial) {
            throw ValidationException::withMessages(['credencial' => ['Carnet no reconocido.']]);
        }

        return $this->registrarParaColaborador(
            $empresa,
            $usuarioVigilancia,
            $credencial->colaborador,
            AsistenciaMarcacion::ORIGEN_CARNET_CODIGO_BARRAS,
            'Kiosco de Control de Acceso',
            ['credencial_id' => $credencial->id, 'usuario_vigilancia_id' => $usuarioVigilancia->id],
            'marcacion_carnet_registrada',
        );
    }

    /**
     * Registro manual desde el kiosco cuando el colaborador olvidó el
     * carnet: el vigilante lo busca por nombre y confirma visualmente su
     * identidad (foto en pantalla) ANTES de llegar acá — ver
     * ControlAccesoController::buscarColaboradores()/marcarManual() y el
     * flujo de confirmación en el frontend. Nunca es autoservicio (no hay
     * ningún código que el propio colaborador ingrese), por eso no pasa
     * por CarnetCredentialService — es un canal de registro distinto, no
     * un "carnet sin código".
     *
     * @return array{resultado: string, colaborador: array, hora: string, mensaje: string, origen: string}
     *
     * @throws ValidationException colaborador inactivo o período no editable.
     */
    public function registrarManual(Empresa $empresa, User $usuarioVigilancia, Colaborador $colaborador): array
    {
        return $this->registrarParaColaborador(
            $empresa,
            $usuarioVigilancia,
            $colaborador,
            AsistenciaMarcacion::ORIGEN_MANUAL_VIGILANCIA,
            'Kiosco de Control de Acceso (manual)',
            ['usuario_vigilancia_id' => $usuarioVigilancia->id, 'motivo' => 'Colaborador sin carnet — confirmado visualmente por vigilancia'],
            'marcacion_manual_vigilancia_registrada',
        );
    }

    private function registrarParaColaborador(
        Empresa $empresa,
        User $usuarioVigilancia,
        ?Colaborador $colaborador,
        string $origen,
        string $dispositivo,
        array $datosOrigen,
        string $accionAuditoria,
    ): array {
        if (! $colaborador || ! $colaborador->activo) {
            throw ValidationException::withMessages(['colaborador' => ['Colaborador inactivo.']]);
        }

        $ahora = $this->fechaOperativa->ahora();
        $this->periodos->asegurarRangoEditable($empresa->id, $ahora->toDateString(), $ahora->toDateString());

        // Fase 12 — dos pasos deliberadamente en transacciones SEPARADAS:
        // guardar la marcación cruda es la parte que NUNCA debe perderse
        // (es la evidencia de que el colaborador se presentó), mientras que
        // reprocesarla es una consecuencia que puede reintentarse después
        // (igual que ReprocesarAsistenciaRango). Si ambos pasos vivieran en
        // la misma transacción, una excepción inesperada en procesar()
        // (no la de "rotativo sin rol", que ya se maneja aparte) revertiría
        // también la marcación recién creada — se verificó exactamente
        // este caso con una prueba (ver ControlAccesoCarnetTest).
        $resultadoMarcacion = DB::transaction(function () use ($empresa, $usuarioVigilancia, $colaborador, $ahora, $origen, $dispositivo, $datosOrigen, $accionAuditoria) {
            // Bloquea al COLABORADOR (no la credencial: el flujo manual no
            // siempre tiene una) — serializa marcaciones casi simultáneas
            // de la MISMA persona sin importar el canal (carnet o manual),
            // el chequeo de duplicado de abajo más el INSERT no serían
            // atómicos frente a una carrera real sin este lock.
            Colaborador::query()->whereKey($colaborador->id)->lockForUpdate()->first();

            $ventanaSegundos = (int) config('asistencia.carnet_antirebote_segundos', 10);
            // El antirebote comparte ventana entre carnet y manual: son el
            // MISMO canal lógico (el kiosco) — un escaneo seguido de una
            // confirmación manual en segundos (o viceversa) debe tratarse
            // como el mismo evento, no como dos marcaciones.
            $marcacionReciente = AsistenciaMarcacion::query()
                ->where('empresa_id', $empresa->id)
                ->where('colaborador_id', $colaborador->id)
                ->whereIn('origen', [AsistenciaMarcacion::ORIGEN_CARNET_CODIGO_BARRAS, AsistenciaMarcacion::ORIGEN_MANUAL_VIGILANCIA])
                ->whereNull('anulada_at')
                ->where('marcado_at', '>=', $ahora->copy()->subSeconds($ventanaSegundos))
                ->exists();

            if ($marcacionReciente) {
                return null;
            }

            $marcacion = AsistenciaMarcacion::create([
                'empresa_id' => $empresa->id,
                'colaborador_id' => $colaborador->id,
                // person_id es NOT NULL y acá no hay biométrico — se usa el
                // DNI, exactamente el mismo valor que ya usa
                // AsistenciaDecisionService::editarDia() para marcaciones
                // manuales sin origen biométrico (origen 'manual_rrhh'), no
                // un ID inventado ni colaborador_id por conveniencia.
                'person_id' => $colaborador->numero_documento,
                'marcado_at' => $ahora,
                'origen' => $origen,
                'dispositivo' => $dispositivo,
                'datos_origen' => $datosOrigen,
            ]);

            $this->auditoria->registrar(
                $empresa->id, $usuarioVigilancia->id, $accionAuditoria, $marcacion,
                null, null, ['colaborador_id' => $colaborador->id],
            );

            return $marcacion;
        });

        if ($resultadoMarcacion === null) {
            return $this->respuesta('marcacion_duplicada', $colaborador, $ahora, $origen);
        }

        try {
            $tipoResultado = $this->procesarYDeterminarResultado($colaborador, $resultadoMarcacion);
        } catch (Throwable $e) {
            // La marcación YA quedó guardada (transacción de arriba, ya
            // comprometida) — un fallo acá no debe traducirse en "no se
            // registró" para el vigilante, ni perder el dato. Queda
            // pendiente de reproceso manual/posterior.
            Log::error('Fallo al reprocesar asistencia tras marcación de kiosco.', [
                'colaborador_id' => $colaborador->id,
                'marcacion_id' => $resultadoMarcacion->id,
                'origen' => $origen,
                'excepcion' => $e->getMessage(),
            ]);

            return $this->respuesta('marcacion_registrada', $colaborador, $ahora, $origen);
        }

        $this->notificarEstadoMotorizado($colaborador, $tipoResultado);

        return $this->respuesta($tipoResultado, $colaborador, $ahora, $origen);
    }

    /**
     * Efecto colateral hacia un sistema externo (plataforma_zazu) — nunca
     * debe poder romper ni retrasar la respuesta al vigilante, por eso va
     * después de que el resultado ya está determinado y con su propio
     * try/catch, igual que el reproceso de arriba.
     */
    private function notificarEstadoMotorizado(Colaborador $colaborador, string $tipoResultado): void
    {
        $estado = match ($tipoResultado) {
            'entrada_registrada' => NotificarEstadoMotorizadoZazuService::ESTADO_DISPONIBLE,
            'salida_registrada' => NotificarEstadoMotorizadoZazuService::ESTADO_DESCANSO,
            default => null,
        };

        if ($estado === null) {
            return;
        }

        try {
            $this->notificadorZazu->notificar($colaborador, $estado);
        } catch (Throwable $e) {
            Log::error('Fallo al notificar estado de motorizado a Zazu.', [
                'colaborador_id' => $colaborador->id,
                'estado' => $estado,
                'excepcion' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reprocesa HOY y AYER (no solo hoy): un escaneo cerca de medianoche
     * puede pertenecer a la jornada de hoy o cerrar una jornada nocturna
     * que empezó ayer, y ProcesarAsistenciaDiaria::procesar() es idempotente
     * — reprocesar dos fechas es barato y evita duplicar acá la lógica de
     * resolución de jornada que ya vive en ResolverJornadaDiaria.
     */
    private function procesarYDeterminarResultado(Colaborador $colaborador, AsistenciaMarcacion $marcacion): string
    {
        $marcacion->refresh();
        $hoy = $marcacion->marcado_at->copy()->startOfDay();
        $ayer = $hoy->copy()->subDay();

        foreach ([$ayer, $hoy] as $fecha) {
            try {
                $resultado = $this->procesador->procesar($colaborador, $fecha);
            } catch (HttpExceptionInterface) {
                // Horario rotativo sin rol declarado para esa fecha, o
                // período protegido (chequeo defensivo redundante con
                // asegurarRangoEditable de arriba) — la marcación cruda ya
                // quedó guardada; queda pendiente de reproceso posterior,
                // igual que hace ReprocesarAsistenciaRango con este mismo
                // tipo de excepción.
                continue;
            }

            if ($resultado->salida_at?->eq($marcacion->marcado_at)) {
                return 'salida_registrada';
            }
            if ($resultado->entrada_at?->eq($marcacion->marcado_at)) {
                return 'entrada_registrada';
            }
        }

        return 'marcacion_registrada';
    }

    private function respuesta(string $resultado, Colaborador $colaborador, Carbon $hora, string $origen): array
    {
        return [
            'resultado' => $resultado,
            'colaborador' => [
                'nombre_mostrable' => trim("{$colaborador->nombres} {$colaborador->apellidos}"),
            ],
            'hora' => $hora->format('H:i'),
            'mensaje' => match ($resultado) {
                'entrada_registrada' => 'Entrada registrada',
                'salida_registrada' => 'Salida registrada',
                'marcacion_duplicada' => 'Marcación ya registrada',
                default => 'Marcación registrada correctamente',
            },
            'origen' => $origen,
        ];
    }
}
