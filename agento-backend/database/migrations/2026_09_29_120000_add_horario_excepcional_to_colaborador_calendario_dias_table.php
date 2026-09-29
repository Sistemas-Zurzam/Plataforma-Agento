<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('colaborador_calendario_dias', function (Blueprint $table) {
            $table->foreignId('horario_excepcional_id')->nullable()->after('tipo')
                ->constrained('horarios')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('colaborador_calendario_dias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('horario_excepcional_id');
        });
    }
};
