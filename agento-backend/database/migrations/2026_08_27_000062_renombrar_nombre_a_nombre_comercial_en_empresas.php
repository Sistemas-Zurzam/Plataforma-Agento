<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "nombre" pasa a llamarse "nombre_comercial" para distinguirlo de la nueva
 * "razon_social" (dato legal, opcional — no toda empresa registrada en
 * Agento tiene RUC todavía, ver StoreEmpresaRequest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->renameColumn('nombre', 'nombre_comercial');
        });

        Schema::table('empresas', function (Blueprint $table) {
            $table->string('razon_social')->nullable()->after('nombre_comercial');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('razon_social');
        });

        Schema::table('empresas', function (Blueprint $table) {
            $table->renameColumn('nombre_comercial', 'nombre');
        });
    }
};
