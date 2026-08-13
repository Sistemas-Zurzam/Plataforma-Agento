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
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('abreviatura', 10)->nullable()->after('nombre');
            $table->string('color', 7)->nullable()->after('direccion');
            $table->string('regimen_laboral')->nullable()->after('color');
            $table->boolean('inscrita_remype')->default(false)->after('regimen_laboral');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn(['abreviatura', 'color', 'regimen_laboral', 'inscrita_remype']);
        });
    }
};
