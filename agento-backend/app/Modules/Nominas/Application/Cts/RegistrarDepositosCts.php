<?php

namespace App\Modules\Nominas\Application\Cts;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Services\BeneficioSocialService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Registra en bloque el depósito de CTS de los colaboradores seleccionados
 * (un mismo abono bancario: fecha, entidad y N.º de operación). Cada depósito
 * pasa por BeneficioSocialService::registrarDeposito, que valida versión
 * vigente, importe y duplicados. Se deposita el saldo pendiente de cada uno.
 */
class RegistrarDepositosCts
{
    public function __construct(private readonly BeneficioSocialService $beneficios) {}

    /**
     * @param  array<int, int>  $detalleIds
     * @return array{registrados: int, omitidos: array<int, array{colaborador: string, motivo: string}>}
     */
    public function registrar(Empresa $empresa, BeneficioSocial $beneficio, array $detalleIds, string $fecha, string $entidad, string $operacion, int $usuarioId): array
    {
        if ((int) $beneficio->empresa_id !== (int) $empresa->id || ! str_starts_with($beneficio->tipo, 'cts_')) {
            throw new AuthorizationException('La CTS no pertenece a la empresa activa.');
        }

        $registrados = 0;
        $omitidos = [];
        $detalles = $beneficio->detalles()->whereIn('id', $detalleIds)->with(['colaborador', 'depositos'])->orderBy('id')->get();
        foreach ($detalles as $detalle) {
            $nombre = trim($detalle->colaborador?->nombres.' '.$detalle->colaborador?->apellidos);
            if (($detalle->detalle_calculo['estado'] ?? null) === 'observado') {
                $omitidos[] = ['colaborador' => $nombre, 'motivo' => 'Cálculo observado: resuelve sus alertas y recalcula antes de depositar.'];

                continue;
            }
            $pendiente = round((float) $detalle->neta - (float) $detalle->depositos->sum('importe'), 2);
            if ($pendiente <= 0) {
                $omitidos[] = ['colaborador' => $nombre, 'motivo' => 'Ya tiene registrado el depósito completo.'];

                continue;
            }

            try {
                $this->beneficios->registrarDeposito($empresa, $detalle, $usuarioId, [
                    'fecha_deposito' => $fecha, 'importe' => $pendiente, 'entidad' => $entidad,
                    // La referencia es única por depósito: el abono masivo se distingue por documento.
                    'referencia' => $operacion.'-'.($detalle->colaborador?->numero_documento ?: $detalle->id),
                ]);
                $registrados++;
            } catch (ValidationException $e) {
                $omitidos[] = ['colaborador' => $nombre, 'motivo' => collect($e->errors())->flatten()->first() ?? 'No se pudo registrar el depósito.'];
            }
        }

        return ['registrados' => $registrados, 'omitidos' => $omitidos];
    }
}
