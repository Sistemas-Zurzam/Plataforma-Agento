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
        Schema::table('areas', function (Blueprint $table) {
            // MySQL's default utf8mb4_unicode_ci collation makes this
            // index case-insensitive, matching the app-level check in
            // StoreAreaRequest and guarding against races between them.
            $table->unique(['empresa_id', 'nombre']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('areas', function (Blueprint $table) {
            $table->dropUnique(['empresa_id', 'nombre']);
        });
    }
};
