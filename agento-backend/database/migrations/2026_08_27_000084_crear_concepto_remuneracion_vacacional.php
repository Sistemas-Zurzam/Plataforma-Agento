<?php

use App\Modules\Nominas\Models\ConceptoRemuneracion;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * SUNAT distingue Remuneración Vacacional (Tabla 22: 0118) de
     * Remuneración/Jornal Básico (0121) — hasta ahora Agento solo tenía
     * VACACIONES_PROVISION (la reserva contable mensual, NO el pago real).
     * Este concepto nuevo representa el pago real de días de vacaciones
     * efectivamente tomados dentro de un período, generado por
     * CalcularBoletaColaborador al descomponer la línea de SUELDO_BASICO
     * (nunca sumado encima, para no duplicar sueldo — ver esa clase).
     *
     * Mismas banderas que SUELDO_BASICO porque es la MISMA remuneración
     * regular, solo separada contablemente por los días que corresponden a
     * vacaciones.
     */
    public function up(): void
    {
        ConceptoRemuneracion::firstOrCreate(
            ['codigo' => 'REMUNERACION_VACACIONAL'],
            [
                'nombre' => 'Remuneración vacacional',
                'tipo' => 'ingreso',
                'codigo_plame' => '0118',
                'es_remunerativo_laboral' => true,
                'afecta_renta_5ta' => true,
                'afecta_afp' => true,
                'afecta_essalud' => true,
                'afecta_cts' => true,
                'afecta_gratificacion' => true,
                'afecta_vacaciones' => true,
                'activo' => true,
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        ConceptoRemuneracion::where('codigo', 'REMUNERACION_VACACIONAL')->delete();
    }
};
