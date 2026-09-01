<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Carga las clasificaciones oficiales 0301-0314 de la Tabla 22. */
    public function up(): void
    {
        $conceptoId = DB::table('conceptos_remuneracion')->where('codigo', 'BONIFICACION')->value('id');

        if (! $conceptoId) {
            return;
        }

        $clasificaciones = [
            '0301' => 'Bonificación por 25 y 30 años de servicios',
            '0302' => 'Bonificación por cierre de pliego',
            '0303' => 'Bonificación por producción, altura, turno, etc.',
            '0304' => 'Bonificación por riesgo de caja',
            '0305' => 'Bonificación por tiempo de servicios',
            '0306' => 'Bonificaciones regulares',
            '0307' => 'Bonificaciones CAFAE',
            '0308' => 'Compensación por trabajos en días de descanso y feriados',
            '0309' => 'Bonificación por turno nocturno 20% jornal básico',
            '0310' => 'Bonificación contacto directo con agua 20% jornal básico',
            '0311' => 'Bonificación unificada de construcción',
            '0312' => 'Bonificación extraordinaria temporal - Ley 29351',
            '0313' => 'Bonificación extraordinaria temporal proporcional - Ley 29351',
            '0314' => 'Bonificación especial por trabajador agrario Ley 31110 - BETA',
        ];

        foreach ($clasificaciones as $codigo => $nombre) {
            DB::table('concepto_definiciones_plame')->insertOrIgnore([
                'concepto_remuneracion_id' => $conceptoId,
                'nombre' => $nombre,
                'codigo_plame' => $codigo,
                'descripcion_sunat' => 'Clasificación oficial precargada (SUNAT, Tabla 22).',
                'activo' => true,
                'creado_por' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('concepto_definiciones_plame')
            ->where('descripcion_sunat', 'Clasificación oficial precargada (SUNAT, Tabla 22).')
            ->delete();
    }
};
