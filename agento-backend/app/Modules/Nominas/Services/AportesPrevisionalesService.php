<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaConcepto;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;

class AportesPrevisionalesService
{
    private const CODIGOS = ['AFP_APORTE_OBLIGATORIO', 'AFP_PRIMA_SEGURO', 'AFP_COMISION', 'ONP'];

    /**
     * Lee las líneas ya calculadas en boleta_conceptos (mismo motor que
     * Planilla Mensual — nunca recalcula) y las agrupa por colaborador para
     * saber cuánto corresponde transferirle a cada AFP y a la ONP en este
     * ciclo. ONP no tiene prima ni comisión: esos campos van en null en vez
     * de 0 para no insinuar un cobro que la AFP nunca hizo.
     */
    public function porCiclo(Empresa $empresa, CicloRemunerativo $ciclo): array
    {
        $boletas = Boleta::where('ciclo_id', $ciclo->id)
            ->where('es_version_vigente', true)
            // Los locadores de Recibos por Honorarios no aportan a AFP/ONP
            // (no son planilla dependiente) — aparecerían en S/ 0.00 sin
            // este filtro, ensuciando la tabla y los totales del ciclo.
            ->where('regimen_laboral_snapshot', '!=', 'Locacion de Servicios')
            ->with([
                'colaborador.afp',
                'conceptos' => fn ($query) => $query->whereHas(
                    'concepto',
                    fn ($q) => $q->whereIn('codigo', self::CODIGOS)
                )->with('concepto'),
            ])
            ->get();

        $complementarias = PlanillaComplementariaDetalle::whereHas('complementaria', fn ($q) => $q
            ->where('ciclo_id', $ciclo->id)->where('estado', 'pagada'))
            ->with('complementaria:id,pagado_at')
            ->get(['id', 'planilla_complementaria_id', 'boleta_original_id', 'calculo_snapshot']);
        $consolidados = $complementarias->groupBy('boleta_original_id')->map(fn ($detalles) => $detalles
            ->sortByDesc(fn ($detalle) => [$detalle->complementaria?->pagado_at?->timestamp ?? 0, $detalle->id])
            ->first());

        $colaboradores = $boletas->map(function (Boleta $boleta) use ($empresa, $consolidados) {
            $porCodigo = $boleta->conceptos->keyBy(fn (BoletaConcepto $c) => $c->concepto->codigo);
            $esOnp = $boleta->colaborador->sistema_previsional === 'onp';
            $codigoPrincipal = $esOnp ? 'ONP' : 'AFP_APORTE_OBLIGATORIO';

            $detalle = $consolidados->get($boleta->id);
            $snapshotPorCodigo = collect($detalle?->calculo_snapshot['egresos'] ?? [])->keyBy('codigo');
            $lineaPrincipal = $snapshotPorCodigo->get($codigoPrincipal);
            $aporteObligatorio = (float) ($lineaPrincipal['monto'] ?? $porCodigo->get($codigoPrincipal)?->monto ?? 0);
            $primaSeguro = $esOnp ? null : (float) (($snapshotPorCodigo->get('AFP_PRIMA_SEGURO')['monto'] ?? null) ?? $porCodigo->get('AFP_PRIMA_SEGURO')?->monto ?? 0);
            $comision = $esOnp ? null : (float) (($snapshotPorCodigo->get('AFP_COMISION')['monto'] ?? null) ?? $porCodigo->get('AFP_COMISION')?->monto ?? 0);
            $baseAsegurable = (float) ($lineaPrincipal['base_utilizada'] ?? $porCodigo->get($codigoPrincipal)?->base_utilizada ?? 0);

            return [
                'colaborador_id' => $boleta->colaborador_id,
                'colaborador' => trim("{$boleta->colaborador->nombres} {$boleta->colaborador->apellidos}"),
                'cargo' => $boleta->colaborador->cargo,
                'empresa' => $empresa->nombre_comercial,
                'sistema_previsional' => $boleta->colaborador->sistema_previsional,
                'afp_nombre' => $boleta->colaborador->afp?->nombre,
                'remuneracion_asegurable' => $baseAsegurable,
                'aporte_obligatorio' => $aporteObligatorio,
                'prima_seguro' => $primaSeguro,
                'comision' => $comision,
                'total' => round($aporteObligatorio + ($primaSeguro ?? 0) + ($comision ?? 0), 2),
                'estado' => $boleta->estado,
            ];
        })->values();

        return ['colaboradores' => $colaboradores];
    }
}
