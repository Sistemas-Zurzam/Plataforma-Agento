<?php

namespace App\Modules\Personas\Application;

use App\Models\User;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Personas\Infrastructure\AlmacenEvidenciasCese;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\SolicitudCese;
use App\Modules\Personas\Services\ColaboradorService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Solicitud de cese: gerencia solicita (Portal) → RR. HH. aprueba o
 * rechaza → RR. HH. ejecuta con el MISMO caso de uso de Personas
 * (ColaboradorService::cesar). Aprobar nunca cesa; solo la ejecución
 * exitosa marca ejecutada_at, dentro de la misma transacción del cese, así
 * un fallo o abandono deja la solicitud "aprobada · pendiente de ejecución".
 * Nunca crea solicitudes de reemplazo.
 */
class SolicitudCeseService
{
    public function __construct(
        private readonly AlmacenEvidenciasCese $almacen,
        private readonly ColaboradorService $colaboradores,
    ) {}

    /**
     * @param  array{fecha_cese_propuesta: string, motivo: string}  $datos
     * @param  array<int, UploadedFile>  $evidencias
     */
    public function crear(Empresa $empresa, Colaborador $colaborador, array $datos, array $evidencias, User $solicitante): SolicitudCese
    {
        $rutas = [];

        try {
            return DB::transaction(function () use ($empresa, $colaborador, $datos, $evidencias, $solicitante, &$rutas) {
                // Bloqueo de la ficha: dos envíos simultáneos para el mismo
                // colaborador se serializan y el segundo ve la solicitud abierta.
                $bloqueado = Colaborador::query()->where('empresa_id', $empresa->id)->whereKey($colaborador->id)->lockForUpdate()->firstOrFail();
                if (! $bloqueado->activo) {
                    throw ValidationException::withMessages(['colaborador_id' => ['El colaborador ya no está activo.']]);
                }
                $abierta = SolicitudCese::query()
                    ->where('empresa_id', $empresa->id)->where('colaborador_id', $colaborador->id)
                    ->where(fn ($q) => $q->where('estado', SolicitudCese::ESTADO_ENVIADA)
                        ->orWhere(fn ($q) => $q->where('estado', SolicitudCese::ESTADO_APROBADA)->whereNull('ejecutada_at')))
                    ->exists();
                if ($abierta) {
                    throw ValidationException::withMessages(['colaborador_id' => ['Ya existe una solicitud de cese en curso para este colaborador.']]);
                }

                $solicitud = SolicitudCese::create([
                    'empresa_id' => $empresa->id,
                    'colaborador_id' => $colaborador->id,
                    'solicitante_user_id' => $solicitante->id,
                    'origen' => 'portal',
                    'fecha_cese_propuesta' => $datos['fecha_cese_propuesta'],
                    'motivo' => $datos['motivo'],
                    'estado' => SolicitudCese::ESTADO_ENVIADA,
                ]);

                foreach ($evidencias as $indice => $archivo) {
                    $guardado = $this->almacen->guardar($solicitud, $archivo, "evidencias.{$indice}");
                    $rutas[] = $guardado['ruta'];
                    $solicitud->evidencias()->create([
                        'nombre_original' => mb_substr($archivo->getClientOriginalName(), 0, 255),
                        'ruta' => $guardado['ruta'],
                        'mime_type' => $guardado['mime'],
                        'tamano_bytes' => $archivo->getSize(),
                        'sha256' => $guardado['sha256'],
                    ]);
                }

                return $solicitud->load('evidencias');
            });
        } catch (\Throwable $e) {
            // La BD se revirtió: no deben quedar archivos huérfanos.
            foreach ($rutas as $ruta) {
                $this->almacen->eliminar($ruta);
            }
            throw $e;
        }
    }

    public function aprobar(Empresa $empresa, SolicitudCese $solicitud, ?string $fechaAprobada, ?string $motivoCambio, User $usuario): SolicitudCese
    {
        return DB::transaction(function () use ($empresa, $solicitud, $fechaAprobada, $motivoCambio, $usuario) {
            $solicitud = $this->bloquear($empresa, $solicitud, [SolicitudCese::ESTADO_ENVIADA]);
            $propuesta = $solicitud->fecha_cese_propuesta->toDateString();
            $cambiaFecha = $fechaAprobada !== null && $fechaAprobada !== $propuesta;
            if ($cambiaFecha && blank($motivoCambio)) {
                throw ValidationException::withMessages(['motivo_cambio_fecha' => ['Indica el motivo del cambio de fecha.']]);
            }

            $solicitud->update([
                'estado' => SolicitudCese::ESTADO_APROBADA,
                'fecha_cese_aprobada' => $fechaAprobada ?? $propuesta,
                'motivo_cambio_fecha' => $cambiaFecha ? $motivoCambio : null,
                'resuelto_por' => $usuario->id,
                'resuelto_at' => now(),
            ]);

            return $solicitud->fresh();
        });
    }

    public function rechazar(Empresa $empresa, SolicitudCese $solicitud, string $motivo, User $usuario): SolicitudCese
    {
        return DB::transaction(function () use ($empresa, $solicitud, $motivo, $usuario) {
            $solicitud = $this->bloquear($empresa, $solicitud, [SolicitudCese::ESTADO_ENVIADA]);
            $solicitud->update([
                'estado' => SolicitudCese::ESTADO_RECHAZADA,
                'motivo_rechazo' => $motivo,
                'resuelto_por' => $usuario->id,
                'resuelto_at' => now(),
            ]);

            return $solicitud->fresh();
        });
    }

    /**
     * Ejecuta el cese real con ColaboradorService::cesar() (revalida
     * elegibilidad: activo, no cesado, mismo bloqueo de fila). Si falla,
     * la transacción revierte y la solicitud sigue pendiente de ejecución.
     */
    public function ejecutar(Empresa $empresa, SolicitudCese $solicitud, string $fechaCese, string $motivoCese, User $usuario): SolicitudCese
    {
        return DB::transaction(function () use ($empresa, $solicitud, $fechaCese, $motivoCese, $usuario) {
            $solicitud = $this->bloquear($empresa, $solicitud, [SolicitudCese::ESTADO_APROBADA]);
            if ($solicitud->ejecutada_at) {
                throw new ConflictHttpException('El cese de esta solicitud ya fue ejecutado.');
            }

            $colaborador = Colaborador::query()->where('empresa_id', $empresa->id)->whereKey($solicitud->colaborador_id)->firstOrFail();
            $this->colaboradores->cesar($empresa, $colaborador, $fechaCese, $motivoCese);

            $solicitud->update([
                'fecha_cese_efectiva' => $fechaCese,
                'ejecutada_por' => $usuario->id,
                'ejecutada_at' => now(),
            ]);

            return $solicitud->fresh();
        });
    }

    /**
     * @param  array<int, string>  $estadosPermitidos
     */
    private function bloquear(Empresa $empresa, SolicitudCese $solicitud, array $estadosPermitidos): SolicitudCese
    {
        $bloqueada = SolicitudCese::query()->where('empresa_id', $empresa->id)->whereKey($solicitud->id)->lockForUpdate()->first();
        abort_unless($bloqueada, 404);
        if (! in_array($bloqueada->estado, $estadosPermitidos, true)) {
            throw new ConflictHttpException('La solicitud ya cambió de estado. Recarga la bandeja.');
        }

        return $bloqueada;
    }
}
