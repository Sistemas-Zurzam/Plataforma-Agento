<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incremento 2 — `filas_otra_empresa` cuenta filas del Excel que pertenecen
 * a otra empresa del grupo (el lector las descarta antes de persistirlas
 * como detalle, para no guardar nombres/documentos de otra empresa dentro
 * de este lote — ver AntecedenteHistoricoXlsxReader). `filas_ignoradas`
 * cuenta las filas cuya clasificacion=no_aplicable (estado_validacion=ignorado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nomina_importaciones_historicas', function (Blueprint $table) {
            $table->unsignedInteger('filas_otra_empresa')->default(0)->after('filas_aplicadas');
            $table->unsignedInteger('filas_ignoradas')->default(0)->after('filas_otra_empresa');
        });
    }

    public function down(): void
    {
        Schema::table('nomina_importaciones_historicas', function (Blueprint $table) {
            $table->dropColumn(['filas_otra_empresa', 'filas_ignoradas']);
        });
    }
};
