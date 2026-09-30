<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ciclos_remunerativos', function (Blueprint $table) {
            $table->text('recalculo_motivo')->nullable()->change();
        });
    }

    public function down(): void
    {
        // No reducir el tipo: el contenido ya almacenado podría exceder VARCHAR.
    }
};
