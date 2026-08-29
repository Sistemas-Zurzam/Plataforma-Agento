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
            // codigo_plame confirmado contra Anexo 2 (Tabla 22) — solo para
            // instalaciones nuevas; en una BD existente lo carga la
            // migración 2026_08_27_000075 (corregida a 4 dígitos por la
            // 000083). Tabla 22 exige "Longitud 4" (Anexo 3, E18) — siempre
            // con cero inicial, nunca 3 dígitos.
            ['codigo' => 'SUELDO_BASICO', 'nombre' => 'Remuneración básica', 'tipo' => 'ingreso', 'codigo_plame' => '0121', 'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'afecta_afp' => true, 'afecta_essalud' => true, 'afecta_cts' => true, 'afecta_gratificacion' => true, 'afecta_vacaciones' => true],
            ['codigo' => 'HE_25', 'nombre' => 'Horas extra 25%', 'tipo' => 'ingreso', 'codigo_plame' => '0105', 'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'afecta_afp' => true, 'afecta_essalud' => true, 'afecta_cts' => true, 'afecta_gratificacion' => true, 'afecta_vacaciones' => true],
            ['codigo' => 'HE_35', 'nombre' => 'Horas extra 35%', 'tipo' => 'ingreso', 'codigo_plame' => '0106', 'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'afecta_afp' => true, 'afecta_essalud' => true, 'afecta_cts' => true, 'afecta_gratificacion' => true, 'afecta_vacaciones' => true],
            ['codigo' => 'HE_100', 'nombre' => 'Horas extra 100% (feriado/descanso)', 'tipo' => 'ingreso', 'codigo_plame' => '0107', 'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'afecta_afp' => true, 'afecta_essalud' => true, 'afecta_cts' => true, 'afecta_gratificacion' => true, 'afecta_vacaciones' => true],
            [
                'codigo' => 'REMUNERACION_VACACIONAL', 'nombre' => 'Remuneración vacacional', 'tipo' => 'ingreso', 'codigo_plame' => '0118',
                'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'afecta_afp' => true, 'afecta_essalud' => true, 'afecta_cts' => true, 'afecta_gratificacion' => true, 'afecta_vacaciones' => true,
            ],
            ['codigo' => 'ASIGNACION_FAMILIAR', 'nombre' => 'Asignación familiar', 'tipo' => 'ingreso', 'codigo_plame' => '0201', 'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'afecta_afp' => true, 'afecta_essalud' => true, 'afecta_cts' => true, 'afecta_gratificacion' => true, 'afecta_vacaciones' => true],
            ['codigo' => 'COMISION', 'nombre' => 'Comisión por ventas', 'tipo' => 'ingreso', 'codigo_plame' => '0103', 'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'afecta_afp' => true, 'afecta_essalud' => true, 'afecta_cts' => true, 'afecta_gratificacion' => true, 'afecta_vacaciones' => true],
            // Bonificación remunerativa real — distinta de BONO_NO_REMUNERATIVO;
            // nunca se asume "no remunerativa" por defecto (Sección 48).
            // Tabla 22 tiene ~14 códigos de bonificación específicos (301 a
            // 314) — requiere que un administrador elija manualmente.
            [
                'codigo' => 'BONIFICACION', 'nombre' => 'Bonificación', 'tipo' => 'ingreso', 'es_remunerativo_laboral' => true, 'afecta_renta_5ta' => true, 'afecta_afp' => true, 'afecta_essalud' => true, 'afecta_cts' => true, 'afecta_gratificacion' => true, 'afecta_vacaciones' => true,
                'sunat_motivo_estado' => 'SUNAT dispone de múltiples conceptos de bonificación (Tabla 22: 301 a 314, según motivo) — requiere que un administrador elija manualmente cuál corresponde a este concepto.',
            ],

            // Ingreso NO remunerativo (art. 19° inciso a, Ley CTS) — SÍ afecto a
            // renta de 5ta (base tributaria más amplia, art. 34° LIR). Riesgo de
            // reclasificación si se paga de forma sistemática — ver
            // alerta_recurrencia_meses. Corresponde a uno de los códigos
            // "Otros Conceptos" (1001-1020) de libre definición del empleador.
            [
                'codigo' => 'BONO_NO_REMUNERATIVO', 'nombre' => 'Bono / liberalidad no remunerativa', 'tipo' => 'ingreso', 'es_remunerativo_laboral' => false, 'afecta_renta_5ta' => true, 'alerta_recurrencia_meses' => 3,
                'sunat_motivo_estado' => 'Corresponde a uno de los códigos "1001 a 1020, Otros Conceptos" de Tabla 22, de libre definición por el empleador junto con su descripción — requiere configuración explícita, no es un código único determinable de antemano.',
            ],

            // Recibos por Honorarios (locadores) — motor completamente
            // separado de la planilla dependiente (CalcularReciboHonorarios).
            // No remunerativo laboral (no hay relación laboral) y no afecta
            // renta de 5ta (es renta de 4ta, un tributo distinto). Se
            // declaran vía E20 (.4ta), no vía Tabla 22.
            [
                'codigo' => 'HONORARIO_BRUTO', 'nombre' => 'Honorario bruto', 'tipo' => 'ingreso',
                'sunat_no_aplica' => true,
                'sunat_motivo_estado' => 'Los honorarios se declaran directamente en la estructura E20 (.4ta) como "Monto total del servicio", sin código de concepto remunerativo — no corresponde a Tabla 22.',
            ],
            [
                'codigo' => 'RETENCION_RENTA_4TA', 'nombre' => 'Retención de renta de 4ta categoría', 'tipo' => 'egreso',
                'sunat_no_aplica' => true,
                'sunat_motivo_estado' => 'La retención de renta de 4ta se declara mediante el indicador de retención de la estructura E20 (.4ta), no como código de concepto de Tabla 22.',
            ],

            // Egresos (descuentos al trabajador)
            // Adelanto de sueldo — SIEMPRE egreso, nunca un ingreso (Sección 47).
            ['codigo' => 'ADELANTO_SUELDO', 'nombre' => 'Adelanto de sueldo', 'tipo' => 'egreso', 'codigo_plame' => '0701'],
            ['codigo' => 'DESCUENTO_TARDANZA', 'nombre' => 'Descuento por tardanza', 'tipo' => 'egreso', 'codigo_plame' => '0704'],
            [
                'codigo' => 'DESCUENTO_FALTA', 'nombre' => 'Descuento por falta', 'tipo' => 'egreso',
                'sunat_motivo_estado' => 'Concepto operativo de descuento; requiere clasificación SUNAT explícita antes de declararse en PLAME.',
            ],
            // Descuentos operativos puntuales — registrables tanto para
            // planilla dependiente como para Recibos por Honorarios
            // (CicloRemunerativoService::registrarConcepto exige tipo=egreso
            // para locadores). Nunca afectan ninguna base de cálculo: al ser
            // 'egreso' quedan fuera de $ingresos en ambos motores, así que se
            // descuentan del neto sin tocar AFP/ONP/renta/CTS/gratificación.
            [
                'codigo' => 'DESCUENTO_ERROR_OPERATIVO', 'nombre' => 'Descuento por error operativo', 'tipo' => 'egreso',
                'sunat_motivo_estado' => 'Concepto operativo de descuento; requiere clasificación SUNAT explícita antes de declararse en PLAME.',
            ],
            [
                'codigo' => 'DESCUENTO_COMPRA_MERCADERIA', 'nombre' => 'Descuento por compra de mercadería', 'tipo' => 'egreso',
                'sunat_motivo_estado' => 'Concepto operativo de descuento; requiere clasificación SUNAT explícita antes de declararse en PLAME.',
            ],
            // V3 A9/A10 — sin codigo_plame a propósito, ver migración
            // 2026_08_28_000093 (clasificación SUNAT pendiente, fuera de esta fase).
            ['codigo' => 'DESCUENTO_HORAS_INCOMPLETAS', 'nombre' => 'Descuento por horas incompletas (HI)', 'tipo' => 'egreso'],
            ['codigo' => 'AFP_APORTE_OBLIGATORIO', 'nombre' => 'AFP — aporte obligatorio (10%)', 'tipo' => 'egreso', 'codigo_plame' => '0608'],
            ['codigo' => 'AFP_PRIMA_SEGURO', 'nombre' => 'AFP — prima de seguro', 'tipo' => 'egreso', 'codigo_plame' => '0606'],
            ['codigo' => 'AFP_COMISION', 'nombre' => 'AFP — comisión de la administradora', 'tipo' => 'egreso', 'codigo_plame' => '0601'],
            ['codigo' => 'ONP', 'nombre' => 'ONP (13%)', 'tipo' => 'egreso', 'codigo_plame' => '0607'],
            ['codigo' => 'RENTA_5TA', 'nombre' => 'Retención de renta de 5ta categoría', 'tipo' => 'egreso', 'codigo_plame' => '0605'],

            // Aportaciones (costo del empleador — no afecta el neto del trabajador)
            ['codigo' => 'ESSALUD', 'nombre' => 'EsSalud (9%, con piso legal sobre RMV)', 'tipo' => 'aportacion', 'codigo_plame' => '0804'],
            // Alternativa a ESSALUD para Micro Empresa inscrita en REMYPE
            // (Empresa.seguro_salud = 'sis') — monto fijo mensual, nunca
            // ambas a la vez para el mismo colaborador.
            ['codigo' => 'SIS_APORTACION', 'nombre' => 'SIS (monto fijo mensual)', 'tipo' => 'aportacion', 'codigo_plame' => '0811'],
            [
                'codigo' => 'CTS_PROVISION', 'nombre' => 'Provisión de CTS', 'tipo' => 'aportacion',
                'sunat_no_aplica' => true,
                'sunat_motivo_estado' => 'Es una provisión contable interna (1/6 del sueldo por mes), no el depósito real de CTS. El depósito real (mayo/noviembre) —que sí correspondería a Tabla 22 código 904— se calcula agregando estas provisiones en BeneficioSocialService, no en esta línea mensual.',
            ],
            [
                'codigo' => 'GRATIFICACION_LEGAL', 'nombre' => 'Provisión de gratificación legal (jul./dic.)', 'tipo' => 'aportacion',
                'sunat_no_aplica' => true,
                'sunat_motivo_estado' => 'Es una provisión contable interna, no el pago real de julio/diciembre. El pago real —Tabla 22 código 401 (completa) o 405 (proporcional/trunca)— se calcula agregando estas provisiones en BeneficioSocialService.',
            ],
            // No remunerativa ni pensionable (Ley 30334) — pero sí es un
            // concepto que compensa el EsSalud sobre la gratificación, no
            // afecta renta de 5ta del trabajador (lo paga el empleador).
            [
                'codigo' => 'BONIFICACION_EXTRAORDINARIA', 'nombre' => 'Bonificación extraordinaria sobre gratificación', 'tipo' => 'aportacion',
                'sunat_no_aplica' => true,
                'sunat_motivo_estado' => 'Es una provisión contable interna, mismo caso que GRATIFICACION_LEGAL. El pago real —Tabla 22 código 312 (completa) o 313 (proporcional/trunca)— se resuelve junto con la gratificación en BeneficioSocialService.',
            ],
            [
                'codigo' => 'VACACIONES_PROVISION', 'nombre' => 'Provisión de vacaciones', 'tipo' => 'aportacion',
                'sunat_no_aplica' => true,
                'sunat_motivo_estado' => 'Es una provisión contable interna, no el pago real de vacaciones tomadas. El pago real (días de vacaciones efectivamente gozados) ya se declara mediante el concepto REMUNERACION_VACACIONAL (Tabla 22: 0118), generado por CalcularBoletaColaborador — esta provisión sigue siendo solo la reserva mensual, no corresponde a Tabla 22.',
            ],
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
