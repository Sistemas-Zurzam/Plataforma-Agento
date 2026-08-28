<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Actualiza SOLO el texto explicativo (motivo_estado) de medico/
     * personal/capacitacion — siguen "bloqueado por modelo" en Catálogos
     * SUNAT porque estructuralmente NUNCA pueden tener un único código fijo
     * a nivel de catálogo (Tabla 21 varía según el permiso concreto: rango
     * de días para médico, con/sin goce para personal/capacitación) — pero
     * ahora Asistencia SÍ captura el dato necesario (fecha_inicio/fecha_fin
     * ya existían; con_goce ahora se pide para personal/capacitacion) y
     * existe un resolver reutilizable (ResolverSuspensionSunat) que calcula
     * el código correcto por permiso individual. No se cambia el estado
     * (bloqueado_por_modelo) porque seguiría siendo engañoso mostrar un
     * único "configurado" en la fila de catálogo — se actualiza únicamente
     * la explicación para que quede claro que el bloqueo funcional ya fue
     * resuelto a nivel de datos.
     */
    public function up(): void
    {
        $ahora = now();

        DB::table('tipos_ausencia')->where('codigo', 'medico')->update([
            'sunat_motivo_estado' => 'Tabla 21 distingue código 20 (primeros 20 días) de 21 (subsidiado EsSalud desde el día 21) — Asistencia ya registra fecha_inicio/fecha_fin del permiso, y ResolverSuspensionSunat calcula el tramo correcto por permiso individual. Este catálogo sigue sin un único código fijo porque, por naturaleza, un mismo permiso puede necesitar ambos.',
            'updated_at' => $ahora,
        ]);

        DB::table('tipos_ausencia')->whereIn('codigo', ['personal', 'capacitacion'])->update([
            'sunat_motivo_estado' => 'Tabla 21 distingue código 05 (sin goce) de 26 (con goce) — Asistencia ya captura con_goce por permiso individual, y ResolverSuspensionSunat resuelve el código correcto. Este catálogo sigue sin un único código fijo porque, por naturaleza, varía caso a caso.',
            'updated_at' => $ahora,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Solo actualiza texto explicativo — no se revierte.
    }
};
