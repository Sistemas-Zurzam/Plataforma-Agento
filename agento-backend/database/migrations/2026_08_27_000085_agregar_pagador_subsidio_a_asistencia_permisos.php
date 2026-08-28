<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Propiedad mínima requerida por AFPnet (excepción de aportar "U" —
 * subsidio pagado directamente por EsSalud): ResolverSuspensionSunat solo
 * sabe que un tramo de descanso médico cae en el código 21 (subsidiado),
 * pero eso NO demuestra quién realizó materialmente el pago al trabajador
 * — es un hecho de negocio distinto que hoy no se registra en ningún
 * lugar. Se guarda el HECHO (quién pagó), nunca el código AFPnet derivado.
 *
 * Nullable y solo relevante para permisos tipo "medico" — RR.HH. lo deja
 * vacío cuando no lo sabe (AfpNet\ResolverExcepcionAfpNet no arriesga "U"
 * sin este dato confirmado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asistencia_permisos', function (Blueprint $table) {
            $table->string('pagador_subsidio')->nullable()->after('con_goce'); // empleador | essalud_directo
        });
    }

    public function down(): void
    {
        Schema::table('asistencia_permisos', function (Blueprint $table) {
            $table->dropColumn('pagador_subsidio');
        });
    }
};
