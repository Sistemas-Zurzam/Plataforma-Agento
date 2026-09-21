<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incremento 3 del endurecimiento Asistencia -> Nómina. Un ciclo que ya se
 * calculó (calculado/reabierto/cerrado) queda marcado cuando algo en
 * Asistencia cambia después -- ver App\Modules\Nominas\Application\
 * NotificarCambioAsistenciaCiclo. Un ciclo 'abierto' que nunca se calculó no
 * necesita esta marca (no hay nada que invalidar); un ciclo 'pagado' nunca
 * la recibe (su boleta es inmutable -- el cambio se deriva a
 * PlanillaComplementaria en su lugar, vía una incidencia).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ciclos_remunerativos', function (Blueprint $table) {
            $table->boolean('requiere_recalculo')->default(false)->after('estado');
            $table->text('recalculo_motivo')->nullable()->after('requiere_recalculo');
            $table->timestamp('recalculo_detectado_at')->nullable()->after('recalculo_motivo');
        });
    }

    public function down(): void
    {
        Schema::table('ciclos_remunerativos', function (Blueprint $table) {
            $table->dropColumn(['requiere_recalculo', 'recalculo_motivo', 'recalculo_detectado_at']);
        });
    }
};
