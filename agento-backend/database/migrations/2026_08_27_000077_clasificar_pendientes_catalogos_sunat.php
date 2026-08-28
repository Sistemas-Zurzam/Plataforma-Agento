<?php

use App\Modules\Nominas\Models\ConceptoRemuneracion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Clasifica los 14 elementos que hasta ahora aparecían como
     * "pendiente" genérico en Catálogos SUNAT, en uno de los 4 estados
     * reales: configurado / requiere_configuración / bloqueado_por_modelo
     * / no_aplica. También separa, para las filas que ya tenían una nota
     * mezclada en descripcion_sunat (migraciones 000074/000075), la
     * explicación interna de Agento (ahora en motivo_estado) de la
     * descripción oficial SUNAT (descripcion_sunat) — nunca se reescribe
     * el historial ya creado en concepto_codigos_plame, solo el estado
     * VIVO de conceptos_remuneracion/tipos_ausencia/sunat_mapeos.
     *
     * WHERE ...IS NULL / ...= false protege cualquier clasificación que un
     * administrador ya haya corregido manualmente.
     */
    public function up(): void
    {
        // ===== sunat_mapeos =====

        // tipo_trabajador "trabajador": bloqueado por modelo (Empleado vs
        // Obrero, Tabla 8 códigos 21/20).
        DB::table('sunat_mapeos')
            ->where('tipo', 'tipo_trabajador')->where('clave_interna', 'trabajador')
            ->whereNull('codigo_sunat')->where('bloqueado_por_modelo', false)
            ->update([
                'bloqueado_por_modelo' => true,
                'motivo_estado' => "Agento utiliza \"trabajador\" de forma genérica, mientras SUNAT distingue Empleado (código 21) y Obrero (código 20) en la Tabla 8 — hace falta que Personas capture esa distinción antes de poder asignar un código único.",
                'updated_at' => now(),
            ]);

        // locador/practicante/Locacion de Servicios: limpia la explicación
        // que había quedado mezclada en descripcion_sunat, la traslada a
        // motivo_estado (que es lo que realmente es: razonamiento interno
        // de Agento, no una descripción oficial SUNAT).
        $noAplicaMapeos = [
            ['tipo_trabajador', 'locador'],
            ['tipo_trabajador', 'practicante'],
            ['regimen_laboral', 'Locacion de Servicios'],
        ];
        foreach ($noAplicaMapeos as [$tipo, $clave]) {
            $fila = DB::table('sunat_mapeos')->where('tipo', $tipo)->where('clave_interna', $clave)->first();
            if (! $fila || $fila->activo || $fila->motivo_estado) {
                continue;
            }
            DB::table('sunat_mapeos')
                ->where('id', $fila->id)
                ->update([
                    'motivo_estado' => $fila->descripcion_sunat,
                    'descripcion_sunat' => null,
                    'updated_at' => now(),
                ]);
        }

        // recibo_honorarios: descripcion_sunat quedó con comentario extra
        // sobre N/D/O — se deja solo la descripción oficial.
        DB::table('sunat_mapeos')
            ->where('tipo', 'tipo_comprobante_rh')->where('clave_interna', 'recibo_honorarios')
            ->where('descripcion_sunat', 'like', '%Tabla completa también incluye%')
            ->update([
                'descripcion_sunat' => 'Recibo por Honorarios (Anexo 2, Tabla 23).',
                'updated_at' => now(),
            ]);

        // ===== tipos_ausencia =====

        $bloqueadosPorModelo = ['medico', 'personal', 'capacitacion'];
        foreach ($bloqueadosPorModelo as $codigo) {
            $fila = DB::table('tipos_ausencia')->where('codigo', $codigo)->first();
            if (! $fila || $fila->sunat_bloqueado_por_modelo || filled($fila->codigo_sunat_suspension)) {
                continue;
            }
            DB::table('tipos_ausencia')
                ->where('id', $fila->id)
                ->update([
                    'sunat_bloqueado_por_modelo' => true,
                    'sunat_motivo_estado' => $fila->descripcion_sunat,
                    'descripcion_sunat' => null,
                    'updated_at' => now(),
                ]);
        }

        // comision_servicio: no representa necesariamente un día no
        // laborado (el colaborador sigue trabajando, solo cambia de
        // sede/labor) — no aplica a Tabla 21 tal como se usa hoy.
        DB::table('tipos_ausencia')
            ->where('codigo', 'comision_servicio')
            ->where('sunat_no_aplica', false)
            ->update([
                'sunat_no_aplica' => true,
                'sunat_motivo_estado' => 'Una "comisión de servicio" implica que el colaborador sigue trabajando (solo cambia de sede/labor), no una suspensión de la relación laboral — no corresponde declarar un día no laborado en Tabla 21 mientras Agento lo use con ese sentido. Si en el futuro se detectan casos donde sí implica ausencia real, debe revisarse esta clasificación.',
                'descripcion_sunat' => null,
                'updated_at' => now(),
            ]);

        // otro: catch-all — cada caso real necesita que RR.HH. determine
        // la causa antes de declararlo, no tiene una única equivalencia.
        DB::table('tipos_ausencia')
            ->where('codigo', 'otro')
            ->whereNull('sunat_motivo_estado')
            ->update([
                'sunat_motivo_estado' => 'Cada ausencia registrada como "otro" requiere que RR.HH. determine su causa real antes de poder declararla en un futuro .snl — no tiene una única equivalencia posible en la Tabla 21.',
                'updated_at' => now(),
            ]);

        // ===== conceptos_remuneracion =====

        // No aplican a Tabla 22 (honorarios van por E20; las provisiones
        // contables no son el pago real que se declara).
        $noAplicanConceptos = [
            'HONORARIO_BRUTO' => 'Los honorarios se declaran directamente en la estructura E20 (.4ta) como "Monto total del servicio", sin código de concepto remunerativo — no corresponde a Tabla 22.',
            'RETENCION_RENTA_4TA' => 'La retención de renta de 4ta se declara mediante el indicador de retención de la estructura E20 (.4ta), no como código de concepto de Tabla 22.',
            'CTS_PROVISION' => 'Es una provisión contable interna (1/6 del sueldo por mes), no el depósito real de CTS. El depósito real (mayo/noviembre) —que si correspondería a Tabla 22 código 904— se calcula agregando estas provisiones en BeneficioSocialService, no en esta línea mensual.',
            'GRATIFICACION_LEGAL' => 'Es una provisión contable interna, no el pago real de julio/diciembre. El pago real —que correspondería a Tabla 22 código 401 (completa) o 405 (proporcional/trunca)— se calcula agregando estas provisiones en BeneficioSocialService, no en esta línea mensual.',
            'BONIFICACION_EXTRAORDINARIA' => 'Es una provisión contable interna, mismo caso que GRATIFICACION_LEGAL. El pago real —que correspondería a Tabla 22 código 312 (completa) o 313 (proporcional/trunca)— se resuelve junto con la gratificación en BeneficioSocialService.',
            'VACACIONES_PROVISION' => 'Es una provisión contable interna, no el pago real de vacaciones tomadas. A diferencia de CTS/gratificación, Agento todavía no calcula un evento de pago agregado para vacaciones — cuando exista, correspondería a Tabla 22 código 118, 114 o 504 según el caso.',
        ];

        foreach ($noAplicanConceptos as $codigoInterno => $motivo) {
            $concepto = ConceptoRemuneracion::where('codigo', $codigoInterno)->first();
            if (! $concepto || $concepto->sunat_no_aplica || $concepto->codigo_plame !== null) {
                continue;
            }
            $concepto->update(['sunat_no_aplica' => true, 'sunat_motivo_estado' => $motivo]);
        }

        // Requieren configuración manual (un administrador debe elegir
        // entre varias opciones válidas — no es un dato que falte en
        // Agento, es una decisión de clasificación).
        $requierenConfiguracion = [
            'BONIFICACION' => 'SUNAT dispone de múltiples conceptos de bonificación (Tabla 22: 301 a 314, según motivo — años de servicio, cierre de pliego, producción, turno nocturno, etc.) — requiere que un administrador elija manualmente cuál corresponde a este concepto.',
            'BONO_NO_REMUNERATIVO' => 'Corresponde a uno de los códigos "1001 a 1020, Otros Conceptos" de Tabla 22, de libre definición por el empleador junto con su descripción — requiere que un administrador lo configure explícitamente, no es un código único determinable de antemano.',
        ];

        foreach ($requierenConfiguracion as $codigoInterno => $motivo) {
            $concepto = ConceptoRemuneracion::where('codigo', $codigoInterno)->first();
            if (! $concepto || $concepto->sunat_motivo_estado || $concepto->codigo_plame !== null) {
                continue;
            }
            $concepto->update(['sunat_motivo_estado' => $motivo]);
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
