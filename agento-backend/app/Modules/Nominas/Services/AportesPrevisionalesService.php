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
            ->get(['boleta_original_id', 'calculo_snapshot']);
        $adicionales = $complementarias->groupBy('boleta_original_id')->map(function ($detalles) {
            $lineas = $detalles->flatMap(fn ($d) => collect($d->calculo_snapshot['egresos'] ?? [])->whereIn('codigo', self::CODIGOS));
            $bases = $lineas->filter(fn ($l) => in_array($l['codigo'] ?? null, ['AFP_APORTE_OBLIGATORIO', 'ONP'], true))->sum(fn ($l) => (float) ($l['base_utilizada'] ?? 0));
            return ['base' => $bases, 'aporte' => $lineas->whereIn('codigo', ['AFP_APORTE_OBLIGATORIO', 'ONP'])->sum('monto'), 'prima' => $lineas->where('codigo', 'AFP_PRIMA_SEGURO')->sum('monto'), 'comision' => $lineas->where('codigo', 'AFP_COMISION')->sum('monto')];
        });

        $colaboradores = $boletas->map(function (Boleta $boleta) use ($empresa, $adicionales) {
            $porCodigo = $boleta->conceptos->keyBy(fn (BoletaConcepto $c) => $c->concepto->codigo);
            $esOnp = $boleta->colaborador->sistema_previsional === 'onp';
            $codigoPrincipal = $esOnp ? 'ONP' : 'AFP_APORTE_OBLIGATORIO';

            $aporteObligatorio = (float) ($porCodigo->get($codigoPrincipal)?->monto ?? 0);
            $primaSeguro = $esOnp ? null : (float) ($porCodigo->get('AFP_PRIMA_SEGURO')?->monto ?? 0);
            $comision = $esOnp ? null : (float) ($porCodigo->get('AFP_COMISION')?->monto ?? 0);
            $extra = $adicionales->get($boleta->id, ['base' => 0, 'aporte' => 0, 'prima' => 0, 'comision' => 0]);

            return [
                'colaborador_id' => $boleta->colaborador_id,
                'colaborador' => trim("{$boleta->colaborador->nombres} {$boleta->colaborador->apellidos}"),
                'cargo' => $boleta->colaborador->cargo,
                'empresa' => $empresa->nombre_comercial,
                'sistema_previsional' => $boleta->colaborador->sistema_previsional,
                'afp_nombre' => $boleta->colaborador->afp?->nombre,
                'remuneracion_asegurable' => (float) ($porCodigo->get($codigoPrincipal)?->base_utilizada ?? 0) + $extra['base'],
                'aporte_obligatorio' => $aporteObligatorio + $extra['aporte'],
                'prima_seguro' => $esOnp ? null : $primaSeguro + $extra['prima'],
                'comision' => $esOnp ? null : $comision + $extra['comision'],
                'total' => round($aporteObligatorio + $extra['aporte'] + ($primaSeguro ?? 0) + $extra['prima'] + ($comision ?? 0) + $extra['comision'], 2),
                'estado' => $boleta->estado,
            ];
        })->values();

        return ['colaboradores' => $colaboradores];
    }
}
