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
        Schema::table('empresa_user', function (Blueprint $table) {
            $table->dropColumn('role');
            $table->foreignId('role_id')->after('user_id')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('empresa_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->string('role')->default('colaborador');
        });
    }
};
