<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Servicios que Agento presta a cada empresa: planilla (nómina, asistencia,
 * personas) y/o reclutamiento (selección). Habilitan las secciones del
 * Portal de Empresa y los módulos internos correspondientes.
 *
 * Valores iniciales sin cambiar el comportamiento actual: planilla queda
 * activa para todas; reclutamiento solo para las empresas que ya tienen
 * solicitudes de personal registradas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->boolean('servicio_planilla')->default(true)->after('bono_asistencia_habilitado');
            $table->boolean('servicio_reclutamiento')->default(false)->after('servicio_planilla');
        });

        $conSeleccion = DB::table('seleccion_solicitudes')->distinct()->pluck('empresa_id');
        if ($conSeleccion->isNotEmpty()) {
            DB::table('empresas')->whereIn('id', $conSeleccion)->update(['servicio_reclutamiento' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn(['servicio_planilla', 'servicio_reclutamiento']);
        });
    }
};
