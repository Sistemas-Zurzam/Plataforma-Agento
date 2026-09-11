<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\BeneficioSocialHistorico;
use App\Modules\Nominas\Models\LiquidacionCese;
use App\Modules\Nominas\Models\NominaImportacionHistorica;
use App\Modules\Nominas\Models\NominaImportacionHistoricaDetalle;
use App\Modules\Nominas\Models\SaldoLaboralPendiente;
use App\Modules\Nominas\Models\SaldoVacacionalHistorico;
use App\Modules\Personas\Models\Colaborador;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Único punto de escritura autorizado para promover filas de
 * `nomina_importacion_historica_detalles` (estado_validacion=valido) a un
 * antecedente definitivo (BeneficioSocialHistorico / SaldoVacacionalHistorico /
 * SaldoLaboralPendiente). Ningún otro camino de código debe crear estos
 * antecedentes directamente — es lo que garantiza que
 * fechaCorteEsValida()/saldoEsCoherente()/diasPendientesEsValido()/
 * esConsistente() (métodos del modelo que hoy nadie invoca solos) siempre
 * se ejecuten antes de persistir.
 *
 * Todo o nada: si CUALQUIER fila marcada "valido" resulta inconsistente al
 * validarla de nuevo aquí (defensa en profundidad — ya debería haberse
 * detectado antes, al marcarla "valido"), o si algún colaborador referenciado
 * no es elegible, se aborta el lote COMPLETO (rollback) — nunca se aplica
 * una parte y se deja la otra para después.
 */
class AplicarImportacionHistoricaService
{
    private const TIPOS_BENEFICIO_SOCIAL = ['gratificacion_pagada', 'cts_depositada'];

    private const TIPOS_SALDO_VACACIONAL = ['saldo_vacacional'];

    private const TIPOS_SALDO_LABORAL = ['prestamo_pendiente', 'adelanto_pendiente', 'descuento_pendiente'];

    public function aplicar(Empresa $empresa, NominaImportacionHistorica $importacion, int $usuarioId): NominaImportacionHistorica
    {
        if ($importacion->empresa_id !== $empresa->id) {
            throw new AuthorizationException('El lote de importación no pertenece a la empresa activa.');
        }

        return DB::transaction(function () use ($importacion, $usuarioId) {
            $importacion = NominaImportacionHistorica::whereKey($importacion->id)->lockForUpdate()->firstOrFail();

            if ($importacion->estado !== 'aprobado') {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede aplicar un lote en estado "aprobado".',
                ]);
            }

            $detalles = NominaImportacionHistoricaDetalle::where('importacion_id', $importacion->id)
                ->where('estado_validacion', 'valido')
                ->where('clasificacion', 'aplicable')
                ->get();

            if ($detalles->isEmpty()) {
                throw ValidationException::withMessages([
                    'detalles' => 'El lote no tiene ninguna fila "aplicable" y "valido" para aplicar.',
                ]);
            }

            // Primera pasada: resuelve y valida CADA fila sin escribir nada
            // todavía. Si una sola falla, se aborta antes de crear ningún
            // antecedente (todo o nada, ver docblock de la clase).
            $porAplicar = $detalles->map(fn (NominaImportacionHistoricaDetalle $detalle) => [
                'detalle' => $detalle,
                'resuelto' => $this->resolverYValidar($importacion, $detalle, $usuarioId),
            ]);

            foreach ($porAplicar as $item) {
                $item['resuelto']['modelo']->save();
                $item['detalle']->update(['estado_validacion' => 'aplicado']);
            }

            $importacion->update([
                'estado' => 'aplicado',
                'filas_aplicadas' => $porAplicar->count(),
                'aplicado_por' => $usuarioId,
                'aplicado_at' => now(),
            ]);

            return $importacion->fresh(['detalles']);
        });
    }

    /**
     * @return array{modelo: Model}
     */
    private function resolverYValidar(NominaImportacionHistorica $importacion, NominaImportacionHistoricaDetalle $detalle, int $usuarioId): array
    {
        if (! $detalle->colaborador_id) {
            throw ValidationException::withMessages([
                'colaborador_id' => "Fila {$detalle->hoja_nombre}#{$detalle->fila_numero}: no tiene un colaborador vinculado, no se puede aplicar.",
            ]);
        }

        $colaborador = Colaborador::withTrashed()->find($detalle->colaborador_id);
        if (! $colaborador) {
            throw ValidationException::withMessages([
                'colaborador_id' => "Fila {$detalle->hoja_nombre}#{$detalle->fila_numero}: el colaborador #{$detalle->colaborador_id} ya no existe.",
            ]);
        }

        $this->verificarColaboradorElegible($importacion, $detalle, $colaborador);

        $modelo = match (true) {
            in_array($detalle->tipo_antecedente, self::TIPOS_BENEFICIO_SOCIAL, true) => $this->construirBeneficioSocial($detalle, $usuarioId),
            in_array($detalle->tipo_antecedente, self::TIPOS_SALDO_VACACIONAL, true) => $this->construirSaldoVacacional($detalle, $usuarioId),
            in_array($detalle->tipo_antecedente, self::TIPOS_SALDO_LABORAL, true) => $this->construirSaldoLaboral($detalle, $usuarioId),
            default => throw ValidationException::withMessages([
                'tipo_antecedente' => "Fila {$detalle->hoja_nombre}#{$detalle->fila_numero}: el tipo de antecedente \"{$detalle->tipo_antecedente}\" todavía no tiene un aplicador implementado (pendiente de Incremento 2).",
            ]),
        };

        return ['modelo' => $modelo];
    }

    /**
     * Condiciones acordadas con el propietario para admitir antecedentes de
     * un colaborador YA CESADO — nunca vía VacacionMovimientoController (que
     * rechaza colaboradores inactivos y pertenece al flujo operativo actual,
     * no al histórico). Un colaborador activo solo se valida por las 3
     * primeras condiciones; las 2 siguientes solo aplican si está cesado.
     */
    private function verificarColaboradorElegible(NominaImportacionHistorica $importacion, NominaImportacionHistoricaDetalle $detalle, Colaborador $colaborador): void
    {
        $ubicacion = "Fila {$detalle->hoja_nombre}#{$detalle->fila_numero}";

        if ($colaborador->empresa_id !== $detalle->empresa_id) {
            throw ValidationException::withMessages([
                'colaborador_id' => "{$ubicacion}: el colaborador no pertenece a la empresa de este lote.",
            ]);
        }

        if (! $detalle->fecha_ingreso_vinculo || ! $colaborador->fecha_ingreso->isSameDay($detalle->fecha_ingreso_vinculo)) {
            throw ValidationException::withMessages([
                'fecha_ingreso_vinculo' => "{$ubicacion}: la fecha de ingreso del vínculo no coincide con la fecha de ingreso registrada del colaborador.",
            ]);
        }

        if (! $colaborador->fecha_cese) {
            return; // Colaborador activo: nada más que verificar.
        }

        $fechaCorteAntecedente = $detalle->fecha_corte ?? $importacion->fecha_corte;
        if ($fechaCorteAntecedente && Carbon::parse($fechaCorteAntecedente)->gt($colaborador->fecha_cese)) {
            throw ValidationException::withMessages([
                'fecha_corte' => "{$ubicacion}: la fecha de corte del antecedente es posterior a la fecha de cese del colaborador.",
            ]);
        }

        $liquidacionYaPagada = LiquidacionCese::where('colaborador_id', $colaborador->id)
            ->where('es_version_vigente', true)->where('estado', 'pagada')->exists();
        if ($liquidacionYaPagada && ! $importacion->autoriza_cesados_con_liquidacion_pagada) {
            throw ValidationException::withMessages([
                'colaborador_id' => "{$ubicacion}: este colaborador ya tiene una liquidación de cese pagada. Marca el lote como \"autoriza_cesados_con_liquidacion_pagada\" si de verdad corresponde una migración histórica sobre un cese ya cerrado.",
            ]);
        }
    }

    /**
     * `$detalle->mes` es, confirmado contra el Excel real y las
     * validaciones de `ImportarAntecedentesHistoricosService`, el mes en que
     * la fila se calculó/pagó (no un mes dentro de un periodo más amplio):
     * la gratificación solo se calcula en julio o diciembre
     * (`confirmarGratificacionPagada()` exige `mes` ∈ {7,12}) y la CTS solo
     * en mayo o noviembre (`confirmarCtsDepositada()` usa el mismo criterio
     * al validar la fecha confirmada). `mes` determina directamente el
     * tipo: gratificación de julio (cubre ene-jun) o CTS de mayo (cubre
     * nov(año-1)-abr) para el primero de los dos valores válidos de cada
     * una; gratificación de diciembre (cubre jul-dic) o CTS de noviembre
     * (cubre may-oct) para el segundo.
     */
    private function construirBeneficioSocial(NominaImportacionHistoricaDetalle $detalle, int $usuarioId): BeneficioSocialHistorico
    {
        if (! $detalle->anio || ! $detalle->mes) {
            throw ValidationException::withMessages([
                'anio' => "Fila {$detalle->hoja_nombre}#{$detalle->fila_numero}: faltan año/mes para derivar el periodo del beneficio.",
            ]);
        }
        if (! $detalle->importe || (float) $detalle->importe <= 0) {
            throw ValidationException::withMessages([
                'importe' => "Fila {$detalle->hoja_nombre}#{$detalle->fila_numero}: el importe del beneficio debe ser mayor a cero.",
            ]);
        }

        $esGratificacion = $detalle->tipo_antecedente === 'gratificacion_pagada';
        // Los valores válidos de `mes` no son simétricos: gratificación solo
        // vale 7 o 12 (julio es la "primera mitad"), CTS solo vale 5 o 11
        // (mayo es la "primera mitad") — no se puede usar el mismo corte
        // "<= 6" para ambos.
        $esPrimerSemestre = $esGratificacion ? (int) $detalle->mes === 7 : (int) $detalle->mes <= 6;
        $tipo = $esGratificacion
            ? ($esPrimerSemestre ? 'gratificacion_julio' : 'gratificacion_diciembre')
            : ($esPrimerSemestre ? 'cts_mayo' : 'cts_noviembre');

        $modelo = new BeneficioSocialHistorico([
            'empresa_id' => $detalle->empresa_id,
            'colaborador_id' => $detalle->colaborador_id,
            'importacion_detalle_id' => $detalle->id,
            'tipo' => $tipo,
            'anio' => $detalle->anio,
            'periodo' => $esPrimerSemestre ? "{$detalle->anio}-S1" : "{$detalle->anio}-S2",
            'fecha_periodo_inicio' => $detalle->fecha_periodo_inicio ?? $detalle->fecha_corte,
            'fecha_periodo_fin' => $detalle->fecha_periodo_fin ?? $detalle->fecha_corte,
            // La fecha/referencia CONFIRMADAS (confirmarCtsDepositada()/
            // confirmarGratificacionPagada() — únicas dos formas de llegar
            // aquí, dado que el clasificador automático nunca produce
            // clasificacion=aplicable) prevalecen sobre los campos genéricos
            // del detalle, que el Excel real no trae con certeza.
            'fecha_pago_deposito' => $detalle->fecha_pago_confirmada ?? $detalle->fecha_pago_deposito,
            'referencia_externa' => $detalle->referencia_pago_confirmada,
            'importe_bruto' => $detalle->importe,
            'importe_pagado' => $detalle->importe,
            'estado' => $esGratificacion ? 'pagado' : 'depositado',
            'version' => 1,
            'es_version_vigente' => true,
            'fecha_ingreso_vinculo' => $detalle->fecha_ingreso_vinculo,
            'fecha_fin_vinculo' => $detalle->fecha_fin_vinculo,
            'fecha_corte' => $detalle->fecha_corte,
            'origen' => 'excel_historico',
            'aprobado_por' => $usuarioId,
            'aprobado_at' => now(),
        ]);

        if (! $modelo->esConsistente()) {
            throw ValidationException::withMessages([
                'estado' => "Fila {$detalle->hoja_nombre}#{$detalle->fila_numero}: el beneficio resultante es inconsistente (estado vs. importe pagado).",
            ]);
        }

        return $modelo;
    }

    private function construirSaldoVacacional(NominaImportacionHistoricaDetalle $detalle, int $usuarioId): SaldoVacacionalHistorico
    {
        if ($detalle->dias_cantidad === null || ! $detalle->fecha_corte) {
            throw ValidationException::withMessages([
                'dias_cantidad' => "Fila {$detalle->hoja_nombre}#{$detalle->fila_numero}: faltan días pendientes o fecha de corte del saldo vacacional.",
            ]);
        }

        $modelo = new SaldoVacacionalHistorico([
            'empresa_id' => $detalle->empresa_id,
            'colaborador_id' => $detalle->colaborador_id,
            'importacion_detalle_id' => $detalle->id,
            'fecha_ingreso_vinculo' => $detalle->fecha_ingreso_vinculo,
            'fecha_fin_vinculo' => $detalle->fecha_fin_vinculo,
            'fecha_corte' => $detalle->fecha_corte,
            'dias_pendientes' => $detalle->dias_cantidad,
            'estado' => 'aprobado',
            'origen' => 'excel_historico',
            'aprobado_por' => $usuarioId,
            'aprobado_at' => now(),
        ]);

        if (! $modelo->fechaCorteEsValida()) {
            throw ValidationException::withMessages([
                'fecha_corte' => "Fila {$detalle->hoja_nombre}#{$detalle->fila_numero}: la fecha de corte es anterior a la fecha de ingreso del vínculo.",
            ]);
        }
        if (! $modelo->diasPendientesEsValido()) {
            throw ValidationException::withMessages([
                'dias_cantidad' => "Fila {$detalle->hoja_nombre}#{$detalle->fila_numero}: los días pendientes no pueden ser negativos.",
            ]);
        }

        return $modelo;
    }

    private function construirSaldoLaboral(NominaImportacionHistoricaDetalle $detalle, int $usuarioId): SaldoLaboralPendiente
    {
        if (! $detalle->importe || (float) $detalle->importe <= 0 || ! $detalle->fecha_corte) {
            throw ValidationException::withMessages([
                'importe' => "Fila {$detalle->hoja_nombre}#{$detalle->fila_numero}: falta el importe original o la fecha de corte del saldo pendiente.",
            ]);
        }

        $tipo = str_replace('_pendiente', '', $detalle->tipo_antecedente);

        $modelo = new SaldoLaboralPendiente([
            'empresa_id' => $detalle->empresa_id,
            'colaborador_id' => $detalle->colaborador_id,
            'importacion_detalle_id' => $detalle->id,
            'fecha_ingreso_vinculo' => $detalle->fecha_ingreso_vinculo,
            'fecha_fin_vinculo' => $detalle->fecha_fin_vinculo,
            'tipo' => $tipo,
            'descripcion' => $detalle->nombre_concepto_original ?? $detalle->codigo_concepto_original ?? 'Antecedente importado del histórico',
            'importe_original' => $detalle->importe,
            'importe_aplicado' => 0,
            'fecha_corte' => $detalle->fecha_corte,
            'estado' => 'aprobado',
            'origen' => 'excel_historico',
            'aprobado_por' => $usuarioId,
            'aprobado_at' => now(),
        ]);

        if (! $modelo->saldoEsCoherente()) {
            throw ValidationException::withMessages([
                'importe' => "Fila {$detalle->hoja_nombre}#{$detalle->fila_numero}: el saldo resultante es incoherente.",
            ]);
        }

        return $modelo;
    }
}
