<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->date('fecha_cese')->nullable()->after('fecha_fin_contrato');
            $table->string('motivo_cese')->nullable()->after('fecha_cese');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('colaboradores', function (Blueprint $table) {
            $table->dropColumn(['fecha_cese', 'motivo_cese', 'deleted_at']);
        });
    }
};
