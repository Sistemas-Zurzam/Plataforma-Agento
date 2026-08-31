<?php

namespace App\Modules\Personas\Application;

use App\Modules\Personas\Models\Colaborador;

/**
 * Qué le falta a un colaborador para estar "completo" de cara a Nóminas:
 * datos previsionales (AFP/CUSPP, cuando el régimen no es Locación de
 * Servicios y el sistema previsional no es ONP) y los documentos de legajo
 * requeridos (mismo catálogo que DOCUMENTOS_REQUERIDOS en
 * FichaColaborador.jsx). Reutiliza exactamente la condición de
 * obligatoriedad de ColaboradorService::actualizarConfiguracionNomina() —
 * no reinterpreta la regla legal, solo la deja consultable sin depender de
 * un ciclo de nómina (a diferencia de PlameValidator).
 */
class EvaluarCompletitudColaborador
{
    private const DOCUMENTOS_REQUERIDOS = [
        'documento_identidad' => 'Copia de documento de identidad',
        'recibo_servicio' => 'Recibo de luz o agua',
        'contrato_firmado' => 'Contrato firmado',
    ];

    /**
     * @return array<int, array{clave: string, etiqueta: string, categoria: string}>
     */
    public function evaluar(Colaborador $colaborador): array
    {
        $faltantes = [];

        $esHonorarios = $colaborador->regimen_laboral === 'Locacion de Servicios';

        if (! $esHonorarios && $colaborador->sistema_previsional !== 'onp') {
            if (blank($colaborador->sistema_previsional)) {
                $faltantes[] = ['clave' => 'sistema_previsional', 'etiqueta' => 'Sistema previsional (ONP/AFP)', 'categoria' => 'previsional'];
            } else {
                if (! $colaborador->afp_id) {
                    $faltantes[] = ['clave' => 'afp_id', 'etiqueta' => 'AFP', 'categoria' => 'previsional'];
                }
                if (blank($colaborador->cuspp)) {
                    $faltantes[] = ['clave' => 'cuspp', 'etiqueta' => 'CUSPP', 'categoria' => 'previsional'];
                }
            }
        }

        $documentosCargados = $colaborador->documentos->pluck('tipo')->all();
        foreach (self::DOCUMENTOS_REQUERIDOS as $tipo => $etiqueta) {
            if (! in_array($tipo, $documentosCargados, true)) {
                $faltantes[] = ['clave' => $tipo, 'etiqueta' => $etiqueta, 'categoria' => 'documento'];
            }
        }

        return $faltantes;
    }
}
