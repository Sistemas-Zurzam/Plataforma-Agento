<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origen de la versión de un beneficio: 'calculo' (Agento) o 'excel' (CTS
 * histórica cargada desde el Excel de RR.HH. cuando no hay data de los meses
 * anteriores en Agento). Una versión 'excel' no se recalcula por encima.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('beneficios_sociales', 'origen')) {
            Schema::table('beneficios_sociales', function (Blueprint $table) {
                $table->string('origen', 20)->default('calculo');
                $table->string('archivo_origen')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('beneficios_sociales', fn (Blueprint $t) => $t->dropColumn(['origen', 'archivo_origen']));
    }
};
