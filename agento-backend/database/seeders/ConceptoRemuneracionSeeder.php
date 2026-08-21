<?php

namespace Database\Seeders;

use App\Modules\Nominas\Models\ConceptoRemuneracion;
use Illuminate\Database\Seeder;

class ConceptoRemuneracionSeeder extends Seeder
{
    /**
     * Catálogo único de conceptos de boleta. es_remunerativo_laboral se
     * asigna usando como única fuente los 10 incisos cerrados del art. 19°
     * de la Ley de CTS (Sección 2.2 del encargo) — todo lo que no encaja
     * expresamente en esa lista se marca remunerativo por defecto (regla
     * general del art. 6° LPCL). afecta_renta_5ta es un flag SEPARADO,
     * nunca derivado del anterior.
     */
    public function run(): void
    {
        $conceptos = [
            // Ingresos remunerativos (regla general del art. 6° LPCL)
            ['codigo' => 'SUELDO_BASICO', 'nombre' => 'Remuneración básica', 'tipo' => 'ingreso', 'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'afecta_afp' => true, 'afecta_essalud' => true, 'afecta_cts' => true, 'afecta_gratificacion' => true, 'afecta_vacaciones' => true],
            ['codigo' => 'HE_25', 'nombre' => 'Horas extra 25%', 'tipo' => 'ingreso', 'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'afecta_afp' => true, 'afecta_essalud' => true, 'afecta_cts' => true, 'afecta_gratificacion' => true, 'afecta_vacaciones' => true],
            ['codigo' => 'HE_35', 'nombre' => 'Horas extra 35%', 'tipo' => 'ingreso', 'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'afecta_afp' => true, 'afecta_essalud' => true, 'afecta_cts' => true, 'afecta_gratificacion' => true, 'afecta_vacaciones' => true],
            ['codigo' => 'HE_100', 'nombre' => 'Horas extra 100% (feriado/descanso)', 'tipo' => 'ingreso', 'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'afecta_afp' => true, 'afecta_essalud' => true, 'afecta_cts' => true, 'afecta_gratificacion' => true, 'afecta_vacaciones' => true],
            ['codigo' => 'ASIGNACION_FAMILIAR', 'nombre' => 'Asignación familiar', 'tipo' => 'ingreso', 'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'afecta_afp' => true, 'afecta_essalud' => true, 'afecta_cts' => true, 'afecta_gratificacion' => true, 'afecta_vacaciones' => true],
            ['codigo' => 'COMISION', 'nombre' => 'Comisión por ventas', 'tipo' => 'ingreso', 'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'afecta_afp' => true, 'afecta_essalud' => true, 'afecta_cts' => true, 'afecta_gratificacion' => true, 'afecta_vacaciones' => true],
            // Bonificación remunerativa real — distinta de BONO_NO_REMUNERATIVO;
            // nunca se asume "no remunerativa" por defecto (Sección 48).
            ['codigo' => 'BONIFICACION', 'nombre' => 'Bonificación', 'tipo' => 'ingreso', 'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'afecta_afp' => true, 'afecta_essalud' => true, 'afecta_cts' => true, 'afecta_gratificacion' => true, 'afecta_vacaciones' => true],

            // Ingreso NO remunerativo (art. 19° inciso a, Ley CTS) — SÍ afecto a
            // renta de 5ta (base tributaria más amplia, art. 34° LIR). Riesgo de
            // reclasificación si se paga de forma sistemática — ver
            // alerta_recurrencia_meses.
            ['codigo' => 'BONO_NO_REMUNERATIVO', 'nombre' => 'Bono / liberalidad no remunerativa', 'tipo' => 'ingreso', 'es_remunerativo_laboral' => false, 'afecta_renta_5ta' => true, 'alerta_recurrencia_meses' => 3],

            // Recibos por Honorarios (locadores) — motor completamente
            // separado de la planilla dependiente (CalcularReciboHonorarios).
            // No remunerativo laboral (no hay relación laboral) y no afecta
            // renta de 5ta (es renta de 4ta, un tributo distinto).
            ['codigo' => 'HONORARIO_BRUTO', 'nombre' => 'Honorario bruto', 'tipo' => 'ingreso'],
            ['codigo' => 'RETENCION_RENTA_4TA', 'nombre' => 'Retención de renta de 4ta categoría', 'tipo' => 'egreso'],

            // Egresos (descuentos al trabajador)
            // Adelanto de sueldo — SIEMPRE egreso, nunca un ingreso (Sección 47).
            ['codigo' => 'ADELANTO_SUELDO', 'nombre' => 'Adelanto de sueldo', 'tipo' => 'egreso'],
            ['codigo' => 'DESCUENTO_TARDANZA', 'nombre' => 'Descuento por tardanza', 'tipo' => 'egreso'],
            ['codigo' => 'AFP_APORTE_OBLIGATORIO', 'nombre' => 'AFP — aporte obligatorio (10%)', 'tipo' => 'egreso'],
            ['codigo' => 'AFP_PRIMA_SEGURO', 'nombre' => 'AFP — prima de seguro', 'tipo' => 'egreso'],
            ['codigo' => 'AFP_COMISION', 'nombre' => 'AFP — comisión de la administradora', 'tipo' => 'egreso'],
            ['codigo' => 'ONP', 'nombre' => 'ONP (13%)', 'tipo' => 'egreso'],
            ['codigo' => 'RENTA_5TA', 'nombre' => 'Retención de renta de 5ta categoría', 'tipo' => 'egreso'],

            // Aportaciones (costo del empleador — no afecta el neto del trabajador)
            ['codigo' => 'ESSALUD', 'nombre' => 'EsSalud (9%, con piso legal sobre RMV)', 'tipo' => 'aportacion'],
            // Alternativa a ESSALUD para Micro Empresa inscrita en REMYPE
            // (Empresa.seguro_salud = 'sis') — monto fijo mensual, nunca
            // ambas a la vez para el mismo colaborador.
            ['codigo' => 'SIS_APORTACION', 'nombre' => 'SIS (monto fijo mensual)', 'tipo' => 'aportacion'],
            ['codigo' => 'CTS_PROVISION', 'nombre' => 'Provisión de CTS', 'tipo' => 'aportacion'],
            ['codigo' => 'GRATIFICACION_LEGAL', 'nombre' => 'Provisión de gratificación legal (jul./dic.)', 'tipo' => 'aportacion'],
            // No remunerativa ni pensionable (Ley 30334) — pero sí es un
            // concepto que compensa el EsSalud sobre la gratificación, no
            // afecta renta de 5ta del trabajador (lo paga el empleador).
            ['codigo' => 'BONIFICACION_EXTRAORDINARIA', 'nombre' => 'Bonificación extraordinaria sobre gratificación', 'tipo' => 'aportacion'],
            ['codigo' => 'VACACIONES_PROVISION', 'nombre' => 'Provisión de vacaciones', 'tipo' => 'aportacion'],
        ];

        foreach ($conceptos as $concepto) {
            ConceptoRemuneracion::query()->firstOrCreate(
                ['codigo' => $concepto['codigo']],
                array_merge([
                    'es_remunerativo_laboral' => false,
                    'afecta_renta_5ta' => false,
                    'afecta_afp' => false,
                    'afecta_essalud' => false,
                    'afecta_cts' => false,
                    'afecta_gratificacion' => false,
                    'afecta_vacaciones' => false,
                    'activo' => true,
                ], $concepto),
            );
        }
    }
}
