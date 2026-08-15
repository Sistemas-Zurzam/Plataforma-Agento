<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('parametro_laboral_definiciones', function (Blueprint $table) {
            $table->string('grupo')->after('clave')->default('Valores Base');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parametro_laboral_definiciones', function (Blueprint $table) {
            $table->dropColumn('grupo');
        });
    }
};
