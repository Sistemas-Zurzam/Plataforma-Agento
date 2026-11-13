<?php

namespace App\Modules\Nominas\Application\EnvioBoletas;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Jobs\EnviarBoletaPorCorreoJob;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaEnvio;
use App\Modules\Nominas\Models\CicloRemunerativo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Envío por correo de la boleta de pago a los colaboradores que RR.HH.
 * selecciona en la planilla. Un solo documento: la boleta oficial, después
 * del pago (la boleta deja constancia de un pago hecho, D.S. 001-98-TR).
 * Se omiten las boletas no pagadas, los locadores (recibo por honorarios) y
 * quienes no tienen correo válido en su ficha. El correo sale de la ficha,
 * nunca del request.
 */
class EnviarBoletasPorCorreo
{
    public const REGIMEN_LOCADOR = 'Locacion de Servicios';

    private const YA_RECIBIDA = 'Ya recibió esta boleta en su correo.';

    /**
     * Qué pasaría con cada boleta seleccionada, sin enviar nada.
     *
     * Quien ya recibió su boleta vigente, en el mismo correo, no la vuelve a
     * recibir salvo que RR.HH. pida reenviar ($reenviar): seleccionar a todos
     * solo envía a quienes faltan.
     *
     * @param  array<int, int>  $boletaIds
     * @return array{enviables: array, omitidas: array}
     */
    public function clasificar(Empresa $empresa, CicloRemunerativo $ciclo, array $boletaIds, bool $reenviar = false): array
    {
        $this->verificar($empresa, $ciclo);
        $boletas = Boleta::where('ciclo_id', $ciclo->id)->where('empresa_id', $empresa->id)
            ->where('es_version_vigente', true)->whereIn('id', $boletaIds)
            ->with('colaborador:id,nombres,apellidos,email,numero_documento')->orderBy('colaborador_id')->get();
        $ultimos = $this->ultimosEnvios($ciclo, $boletas->pluck('colaborador_id')->all());

        $enviables = [];
        $omitidas = [];
        foreach ($boletas as $boleta) {
            $fila = [
                'boleta_id' => $boleta->id,
                'colaborador' => trim($boleta->colaborador?->nombres.' '.$boleta->colaborador?->apellidos),
                'documento' => $boleta->colaborador?->numero_documento,
            ];
            $email = trim((string) $boleta->colaborador?->email);
            $ultimo = $ultimos->get($boleta->colaborador_id);
            $mismaBoleta = $ultimo && $ultimo->boleta_id === $boleta->id;

            $motivo = match (true) {
                $boleta->regimen_laboral_snapshot === self::REGIMEN_LOCADOR => 'Locación de servicios: no tiene boleta de pago.',
                $boleta->estado !== 'pagada' => 'La boleta aún no está pagada: se envía después del pago.',
                $email === '' => 'No tiene correo registrado en su ficha.',
                ! filter_var($email, FILTER_VALIDATE_EMAIL) => 'El correo de su ficha no es válido.',
                $mismaBoleta && $ultimo->estado === 'pendiente' => 'Su boleta ya está en cola de envío.',
                $mismaBoleta && $ultimo->estado === 'enviado' && $ultimo->email === $email && ! $reenviar => self::YA_RECIBIDA,
                default => null,
            };
            if ($motivo) {
                $omitidas[] = [...$fila, 'colaborador_id' => $boleta->colaborador_id, 'motivo' => $motivo, 'ya_recibida' => $motivo === self::YA_RECIBIDA];

                continue;
            }

            $enviables[] = [...$fila, 'email' => $email, 'ultimo_envio' => $ultimo ? self::presentarEnvio($ultimo, $boleta) : null];
        }

        return ['enviables' => $enviables, 'omitidas' => $omitidas];
    }

    /**
     * Registra un envío pendiente por boleta enviable y lo encola. Una boleta
     * que ya tiene un envío en cola no se vuelve a encolar.
     *
     * @param  array<int, int>  $boletaIds
     * @return array{encolados: int, en_cola: int, omitidas: array}
     */
    public function enviar(Empresa $empresa, CicloRemunerativo $ciclo, array $boletaIds, int $usuarioId, bool $reenviar = false): array
    {
        $clasificacion = $this->clasificar($empresa, $ciclo, $boletaIds, $reenviar);
        $boletas = Boleta::whereIn('id', array_column($clasificacion['enviables'], 'boleta_id'))->get()->keyBy('id');

        $envios = DB::transaction(function () use ($clasificacion, $boletas, $empresa, $ciclo, $usuarioId) {
            $creados = [];
            foreach ($clasificacion['enviables'] as $fila) {
                $boleta = $boletas[$fila['boleta_id']];
                $enCola = BoletaEnvio::where('boleta_id', $boleta->id)->where('tipo', BoletaEnvio::TIPO_BOLETA)
                    ->where('estado', 'pendiente')->lockForUpdate()->exists();
                if ($enCola) {
                    continue;
                }
                $creados[] = BoletaEnvio::create([
                    'empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id, 'colaborador_id' => $boleta->colaborador_id,
                    'boleta_id' => $boleta->id, 'boleta_version' => $boleta->version, 'tipo' => BoletaEnvio::TIPO_BOLETA,
                    'email' => $fila['email'], 'estado' => 'pendiente', 'enviado_por' => $usuarioId,
                ]);
            }

            return $creados;
        });

        foreach ($envios as $envio) {
            EnviarBoletaPorCorreoJob::dispatch($envio->id);
        }

        return [
            'encolados' => count($envios),
            'en_cola' => count($clasificacion['enviables']) - count($envios),
            'omitidas' => $clasificacion['omitidas'],
        ];
    }

    /**
     * Último envío de boleta por colaborador del ciclo.
     *
     * @param  array<int, int>  $colaboradorIds
     * @return Collection<int, BoletaEnvio>
     */
    public function ultimosEnvios(CicloRemunerativo $ciclo, array $colaboradorIds): Collection
    {
        return BoletaEnvio::where('ciclo_id', $ciclo->id)->where('tipo', BoletaEnvio::TIPO_BOLETA)
            ->whereIn('colaborador_id', $colaboradorIds)->orderBy('id')->get()->keyBy('colaborador_id');
    }

    /** Estado del último envío frente a la boleta vigente (para la tabla y el modal). */
    public static function presentarEnvio(BoletaEnvio $envio, Boleta $vigente): array
    {
        return [
            'estado' => $envio->estado,
            'enviado_at' => $envio->enviado_at?->toDateTimeString(),
            'error' => $envio->error,
            // La boleta se recalculó después de enviarla (ej. ciclo reabierto).
            'desactualizada' => $envio->boleta_id !== $vigente->id,
        ];
    }

    private function verificar(Empresa $empresa, CicloRemunerativo $ciclo): void
    {
        if ($ciclo->empresa_id !== $empresa->id) {
            throw new AuthorizationException('El ciclo no pertenece a la empresa.');
        }
    }
}
