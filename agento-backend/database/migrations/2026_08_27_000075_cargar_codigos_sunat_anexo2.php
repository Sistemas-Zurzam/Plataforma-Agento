<?php

use App\Modules\Nominas\Models\ConceptoRemuneracion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Carga códigos SUNAT confirmados contra el catálogo OFICIAL completo
     * (Anexo2_tablas_parametricas_12.09.25.xlsx — Tablas 3, 8, 11, 12, 13,
     * 16, 21, 22, 23, 25 y 33), cruzado con las estructuras de archivo del
     * Anexo 3. Reemplaza las notas de "evidencia parcial" que dejó la
     * migración 000074 (Tabla 22 AFP, Tabla 21 médico) ahora que sí existe
     * el catálogo completo para esos casos.
     *
     * No se ha añadido ninguna columna nueva para "fuente" (Anexo 2 vs
     * Anexo 3, versión): se decidió no crear ese campo por ahora (sería
     * el mismo valor repetido en cada fila de este lote) y en su lugar se
     * deja la cita de la fuente directamente en descripcion_sunat — no
     * hace falta más estructura para lo que hoy se necesita.
     *
     * WHERE ...IS NULL protege cualquier valor ya configurado manualmente
     * por un administrador.
     */
    public function up(): void
    {
        // ===== Tabla 33 — Régimen laboral =====
        // "General" = régimen privado general D.Leg. 728 (código 01).
        // Micro/Pequeña Empresa = D.Leg. 1086 (códigos 16/17).
        // "Locacion de Servicios" NO es un régimen laboral de trabajador
        // dependiente — Tabla 33 completa (T33) no contiene ningún código
        // para contratación civil/RH — se desactiva con esa explicación.
        $regimenes = [
            'General' => ['01', 'Privado General - Decreto Legislativo N.° 728 (Anexo 2, Tabla 33)'],
            'Micro Empresa' => ['16', 'Microempresa D. Leg. 1086, aplicable solo si inscrita en REMYPE (Anexo 2, Tabla 33)'],
            'Pequeña Empresa' => ['17', 'Pequeña Empresa D. Leg. 1086, aplicable solo si inscrita en REMYPE (Anexo 2, Tabla 33)'],
        ];
        foreach ($regimenes as $claveInterna => [$codigo, $descripcion]) {
            DB::table('sunat_mapeos')
                ->where('tipo', 'regimen_laboral')
                ->where('clave_interna', $claveInterna)
                ->whereNull('codigo_sunat')
                ->update(['codigo_sunat' => $codigo, 'descripcion_sunat' => $descripcion, 'updated_at' => now()]);
        }
        DB::table('sunat_mapeos')
            ->where('tipo', 'regimen_laboral')
            ->where('clave_interna', 'Locacion de Servicios')
            ->whereNull('codigo_sunat')
            ->update([
                'activo' => false,
                'descripcion_sunat' => 'No aplica a Tabla 33: la tabla completa (Anexo 2) no contiene ningún código de "régimen laboral" para contratación civil/locación de servicios — es una relación distinta a la laboral dependiente.',
                'updated_at' => now(),
            ]);

        // ===== Tabla 23 — Tipo de comprobante (RH) =====
        DB::table('sunat_mapeos')
            ->where('tipo', 'tipo_comprobante_rh')
            ->where('clave_interna', 'recibo_honorarios')
            ->whereNull('codigo_sunat')
            ->update([
                'codigo_sunat' => 'R',
                'descripcion_sunat' => 'Recibo por Honorarios (Anexo 2, Tabla 23). Tabla completa también incluye N=Nota de Crédito, D=Dieta, O=Otro comprobante — no cargados: Agento no emite/registra esos tipos todavía.',
                'updated_at' => now(),
            ]);

        // ===== Tabla 21 — Tipo de suspensión (actualiza la nota parcial de 000074) =====
        // vacaciones y falta_injustificada SÍ tienen correspondencia exacta
        // y sin ambigüedad — se cargan. medico/personal/capacitacion quedan
        // documentados como "modelo Agento insuficiente": Tabla 21
        // distingue situaciones que Agento hoy registra bajo un único tipo.
        DB::table('tipos_ausencia')
            ->where('codigo', 'vacaciones')
            ->whereNull('codigo_sunat_suspension')
            ->update([
                'codigo_sunat_suspension' => '23',
                'descripcion_sunat' => 'S.I. Descanso vacacional (Anexo 2, Tabla 21).',
                'updated_at' => now(),
            ]);

        DB::table('tipos_ausencia')
            ->where('codigo', 'falta_injustificada')
            ->whereNull('codigo_sunat_suspension')
            ->update([
                'codigo_sunat_suspension' => '07',
                'descripcion_sunat' => 'S.P. Falta no justificada (Anexo 2, Tabla 21).',
                'updated_at' => now(),
            ]);

        DB::table('tipos_ausencia')
            ->where('codigo', 'medico')
            ->whereNull('codigo_sunat_suspension')
            ->update([
                'descripcion_sunat' => 'MODELO AGENTO INSUFICIENTE: Tabla 21 distingue código 20 "S.I. Enfermedad o accidente (primeros 20 días, a cargo del empleador)" de código 21 "S.I. Incapacidad temporal (subsidiada por EsSalud, desde el día 21)" — un mismo descanso médico prolongado puede necesitar AMBOS códigos en tramos distintos de fecha. El tipo "medico" de Agento no distingue el día 20 como corte, así que no puede asignarse un único código sin arriesgar declarar mal el tramo subsidiado.',
                'updated_at' => now(),
            ]);

        DB::table('tipos_ausencia')
            ->where('codigo', 'personal')
            ->whereNull('codigo_sunat_suspension')
            ->update([
                'descripcion_sunat' => 'MODELO AGENTO INSUFICIENTE: Tabla 21 distingue código 05 "S.P. Permiso/licencia sin goce de haber" de código 26 "S.I. Licencia u otros motivos con goce de haber" — depende de si el permiso fue pagado o no. Agento registra "personal" con remunerada=NULL (varía caso a caso) y no captura ese dato por permiso individual.',
                'updated_at' => now(),
            ]);

        DB::table('tipos_ausencia')
            ->where('codigo', 'capacitacion')
            ->whereNull('codigo_sunat_suspension')
            ->update([
                'descripcion_sunat' => 'MODELO AGENTO INSUFICIENTE: Tabla 21 no tiene un código específico para "capacitación" — corresponde a 05 (sin goce) o 26 (con goce) según si se pagó o no, dato que Agento no distingue para este tipo.',
                'updated_at' => now(),
            ]);

        DB::table('tipos_ausencia')
            ->where('codigo', 'comision_servicio')
            ->whereNull('codigo_sunat_suspension')
            ->update([
                'descripcion_sunat' => 'PENDIENTE DE ACLARACIÓN FUNCIONAL: "comisión de servicio" (desplazar al colaborador a otra sede/labor) no equivale necesariamente a un día no laborado — si el colaborador sigue trabajando, no correspondería registrar suspensión alguna en Tabla 21. Requiere confirmar el uso real de este tipo en Agento antes de mapear.',
                'updated_at' => now(),
            ]);

        // ===== Tabla 22 — Conceptos de remuneración =====
        // Coincidencias exactas y sin ambigüedad contra la descripción
        // oficial de Tabla 22 (Anexo 2).
        $conceptosExactos = [
            'SUELDO_BASICO' => ['121', 'Remuneración o jornal básico'],
            'HE_25' => ['105', 'Trabajo en sobretiempo (horas extras) 25%'],
            'HE_35' => ['106', 'Trabajo en sobretiempo (horas extras) 35%'],
            'HE_100' => ['107', 'Trabajo en día feriado o día de descanso'],
            'ASIGNACION_FAMILIAR' => ['201', 'Asignación familiar'],
            'COMISION' => ['103', 'Comisiones o destajo (distinto de 104 "comisiones eventuales", no remunerativas)'],
            'ADELANTO_SUELDO' => ['701', 'Adelanto'],
            'DESCUENTO_TARDANZA' => ['704', 'Tardanzas'],
            'AFP_APORTE_OBLIGATORIO' => ['608', 'Sistema Privado de Pensiones - Aportación obligatoria'],
            'AFP_PRIMA_SEGURO' => ['606', 'Sistema Privado de Pensiones - Prima de seguro'],
            'AFP_COMISION' => ['601', 'Sistema Privado de Pensiones - Comisión porcentual'],
            'ONP' => ['607', 'Sistema Nacional de Pensiones - D.L. 19990'],
            'RENTA_5TA' => ['605', 'Renta quinta categoría retenciones'],
            'ESSALUD' => ['804', 'ESSALUD (Seguro Regular, CBSSP, Agrario/Acuicultor) - Trabajador'],
            'SIS_APORTACION' => ['811', 'Seguro Integral de Salud - SIS'],
        ];

        foreach ($conceptosExactos as $codigoInterno => [$codigoPlame, $descripcion]) {
            $concepto = ConceptoRemuneracion::where('codigo', $codigoInterno)->first();
            if (! $concepto || $concepto->codigo_plame !== null) {
                continue;
            }
            $concepto->update(['codigo_plame' => $codigoPlame]);
            $concepto->codigosPlameHistorial()->create([
                'codigo_plame' => $codigoPlame,
                'descripcion_sunat' => $descripcion.' (Anexo 2, Tabla 22).',
                'vigencia_desde' => now()->toDateString(),
            ]);
        }

        // Nota: AFP_APORTE_OBLIGATORIO/AFP_PRIMA_SEGURO/AFP_COMISION ya
        // quedaron resueltos en el bucle anterior ($conceptosExactos, con
        // 608/606/601) — eso reemplaza automáticamente la nota de
        // "evidencia parcial" que dejó la migración 000074 (0601/0606/
        // 0608/0609 sin distinguir), sin borrar ese historial previo.

        // Conceptos con clasificación funcional (sin código de Tabla 22 en
        // la línea mensual): no se sobrescribe si ya tienen codigo_plame.
        $notasFuncionales = [
            'BONIFICACION' => 'MODELO AGENTO INSUFICIENTE: Tabla 22 tiene ~14 códigos específicos de bonificación (301-314: por años de servicio, cierre de pliego, producción, riesgo de caja, turno nocturno, etc.) y uno genérico "306 Bonificaciones regulares" — Agento no distingue el motivo real de cada "Bonificación" registrada, por lo que no puede asignarse un único código sin riesgo de declarar mal el concepto.',
            'BONO_NO_REMUNERATIVO' => 'REQUIERE SELECCIÓN AD-HOC: Tabla 22 no tiene un código fijo para liberalidades genéricas — corresponde a uno de los códigos "1001 a 1020, OTROS CONCEPTOS" que el propio empleador define libremente junto con su descripción al declarar. No es un código único determinable de antemano.',
            'CTS_PROVISION' => 'NO APLICA a la línea mensual: es una provisión contable interna (1/6 del sueldo por mes), no el depósito real de CTS. El depósito real (mayo/noviembre) ya se calcula agregando estas provisiones en BeneficioSocialService — el código real de Tabla 22 (904, "Compensación por Tiempo de Servicios") correspondería a ESE evento agregado, no a esta línea mensual.',
            'GRATIFICACION_LEGAL' => 'NO APLICA a la línea mensual: es una provisión contable interna, no el pago real de julio/diciembre. El pago real ya se calcula agregando estas provisiones en BeneficioSocialService — correspondería a Tabla 22 código 401 ("Gratificaciones de Fiestas Patrias y Navidad") si es completa, o 405 ("Gratificaciones proporcional") si es trunca por cese — dato que solo se conoce al momento del pago real, no en la provisión mensual.',
            'BONIFICACION_EXTRAORDINARIA' => 'NO APLICA a la línea mensual, mismo caso que GRATIFICACION_LEGAL: el pago real correspondería a Tabla 22 código 312 ("Bonificación extraordinaria temporal Ley 29351/30334", completa) o 313 ("proporcional", trunca) — se resuelve junto con la gratificación en BeneficioSocialService, no en la provisión mensual.',
            'VACACIONES_PROVISION' => 'DATO AGENTO INCOMPLETO: es una provisión contable interna. A diferencia de CTS/Gratificación, Agento todavía no calcula un "evento de pago" agregado para vacaciones (BeneficioSocialService no lo incluye) — el pago real de vacaciones tomadas correspondería a Tabla 22 código 118 ("Remuneración vacacional"), 114 ("Vacaciones truncas") o 504 ("Indemnización por vacaciones no gozadas") según el caso, pero Agento no tiene hoy ese concepto separado de la provisión mensual.',
        ];

        foreach ($notasFuncionales as $codigoInterno => $descripcion) {
            $concepto = ConceptoRemuneracion::where('codigo', $codigoInterno)->first();
            if (! $concepto || $concepto->codigo_plame !== null) {
                continue;
            }
            if ($concepto->codigosPlameHistorial()->where('descripcion_sunat', $descripcion)->exists()) {
                continue;
            }
            $concepto->codigosPlameHistorial()->create([
                'codigo_plame' => null,
                'descripcion_sunat' => $descripcion,
                'vigencia_desde' => now()->toDateString(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data-fix: no se revierte automáticamente (podría borrar
        // correcciones manuales posteriores del administrador).
    }
};
