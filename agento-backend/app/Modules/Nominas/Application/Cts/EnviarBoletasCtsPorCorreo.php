<?php

namespace App\Modules\Nominas\Application\Cts;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Jobs\EnviarBoletaCtsPorCorreoJob;
use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialEnvio;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Envío por correo de la boleta de CTS (hoja de liquidación del depósito) a
 * los colaboradores seleccionados. Solo después del depósito completo: la
 * hoja informa fecha, entidad y cuenta del abono. Se omiten quienes no tienen
 * correo válido y quienes ya la recibieron (salvo reenvío explícito). El
 * correo sale de la ficha, nunca del request.
 */
class EnviarBoletasCtsPorCorreo
{
    private const YA_RECIBIDA = 'Ya recibió su boleta de CTS en su correo.';

    /**
     * @param  array<int, int>  $detalleIds
     * @return array{enviables: array, omitidas: array}
     */
    public function clasificar(Empresa $empresa, BeneficioSocial $beneficio, array $detalleIds, bool $reenviar = false): array
    {
        $this->verificar($empresa, $beneficio);
        $detalles = $beneficio->detalles()->whereIn('id', $detalleIds)->with(['colaborador:id,nombres,apellidos,email,numero_documento', 'depositos'])->orderBy('id')->get();
        $ultimos = $this->ultimosEnvios($detalles->pluck('id')->all());

        $enviables = [];
        $omitidas = [];
        foreach ($detalles as $detalle) {
            $fila = ['detalle_id' => $detalle->id, 'colaborador' => trim($detalle->colaborador?->nombres.' '.$detalle->colaborador?->apellidos)];
            $email = trim((string) $detalle->colaborador?->email);
            $ultimo = $ultimos->get($detalle->id);
            $depositado = round((float) $detalle->depositos->sum('importe'), 2);

            $motivo = match (true) {
                $depositado <= 0 || $depositado + 0.005 < (float) $detalle->neta => 'Aún no se registra su depósito completo.',
                $email === '' => 'No tiene correo registrado en su ficha.',
                ! filter_var($email, FILTER_VALIDATE_EMAIL) => 'El correo de su ficha no es válido.',
                $ultimo && $ultimo->estado === 'pendiente' => 'Su boleta de CTS ya está en cola de envío.',
                $ultimo && $ultimo->estado === 'enviado' && $ultimo->email === $email && ! $reenviar => self::YA_RECIBIDA,
                default => null,
            };
            if ($motivo) {
                $omitidas[] = [...$fila, 'motivo' => $motivo, 'ya_recibida' => $motivo === self::YA_RECIBIDA];

                continue;
            }
            $enviables[] = [...$fila, 'email' => $email, 'ultimo_envio' => $ultimo ? self::presentarEnvio($ultimo) : null];
        }

        return ['enviables' => $enviables, 'omitidas' => $omitidas];
    }

    /**
     * @param  array<int, int>  $detalleIds
     * @return array{encolados: int, en_cola: int, omitidas: array}
     */
    public function enviar(Empresa $empresa, BeneficioSocial $beneficio, array $detalleIds, int $usuarioId, bool $reenviar = false): array
    {
        $clasificacion = $this->clasificar($empresa, $beneficio, $detalleIds, $reenviar);
        $detalles = $beneficio->detalles()->whereIn('id', array_column($clasificacion['enviables'], 'detalle_id'))->get()->keyBy('id');

        $envios = DB::transaction(function () use ($clasificacion, $detalles, $empresa, $usuarioId) {
            $creados = [];
            foreach ($clasificacion['enviables'] as $fila) {
                $detalle = $detalles[$fila['detalle_id']];
                if (BeneficioSocialEnvio::where('beneficio_social_detalle_id', $detalle->id)->where('estado', 'pendiente')->lockForUpdate()->exists()) {
                    continue;
                }
                $creados[] = BeneficioSocialEnvio::create([
                    'empresa_id' => $empresa->id, 'beneficio_social_detalle_id' => $detalle->id, 'colaborador_id' => $detalle->colaborador_id,
                    'email' => $fila['email'], 'estado' => 'pendiente', 'enviado_por' => $usuarioId,
                ]);
            }

            return $creados;
        });

        foreach ($envios as $envio) {
            EnviarBoletaCtsPorCorreoJob::dispatch($envio->id);
        }

        return ['encolados' => count($envios), 'en_cola' => count($clasificacion['enviables']) - count($envios), 'omitidas' => $clasificacion['omitidas']];
    }

    /**
     * @param  array<int, int>  $detalleIds
     * @return Collection<int, BeneficioSocialEnvio>
     */
    public function ultimosEnvios(array $detalleIds): Collection
    {
        return BeneficioSocialEnvio::whereIn('beneficio_social_detalle_id', $detalleIds)->orderBy('id')->get()->keyBy('beneficio_social_detalle_id');
    }

    public static function presentarEnvio(BeneficioSocialEnvio $envio): array
    {
        return ['estado' => $envio->estado, 'enviado_at' => $envio->enviado_at?->toDateTimeString(), 'error' => $envio->error];
    }

    private function verificar(Empresa $empresa, BeneficioSocial $beneficio): void
    {
        if ((int) $beneficio->empresa_id !== (int) $empresa->id || ! str_starts_with($beneficio->tipo, 'cts_')) {
            throw new AuthorizationException('La CTS no pertenece a la empresa activa.');
        }
    }
}
