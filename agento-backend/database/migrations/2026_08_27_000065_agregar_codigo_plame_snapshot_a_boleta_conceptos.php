<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * conceptos_remuneracion.codigo_plame ya existe pero es un único valor
 * "actual" — si algún día SUNAT reasigna un código (el propio Anexo 3 ya
 * documenta reasignaciones históricas: "concepto 617 incorporado en la
 * versión 2.3 del PDT PLAME", "Modificado por R.S. N.° 235-2013/SUNAT"),
 * una boleta ya calculada no debe cambiar de interpretación retroactivamente.
 *
 * Se resuelve con el MISMO patrón ya usado en esta tabla para tipo/
 * es_remunerativo_laboral/afecta_renta_5ta: una columna de snapshot
 * congelada en el momento del cálculo, no una tabla de vigencias nueva para
 * el catálogo — el catálogo (conceptos_remuneracion.codigo_plame) sigue
 * siendo un solo valor "vigente hoy", editable por un administrador; lo que
 * nunca cambia es lo que ya quedó snapshoteado en cada boleta_concepto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boleta_conceptos', function (Blueprint $table) {
            $table->string('codigo_plame_snapshot', 10)->nullable()->after('afecta_renta_5ta');
        });
    }

    public function down(): void
    {
        Schema::table('boleta_conceptos', function (Blueprint $table) {
            $table->dropColumn('codigo_plame_snapshot');
        });
    }
};
