<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trazabilidad de aprobación y pago (Sección 65/66): "pagada" nunca debe
     * ser un badge sin respaldo — referencia_pago es obligatoria al marcar
     * el pago (ej. número de operación bancaria o constancia), y queda quién
     * y cuándo aprobó/pagó cada boleta.
     */
    public function up(): void
    {
        Schema::table('boletas', function (Blueprint $table) {
            $table->foreignId('aprobado_por')->nullable()->after('motivo_recalculo')->constrained('users')->nullOnDelete();
            $table->timestamp('aprobado_at')->nullable()->after('aprobado_por');
            $table->foreignId('pagado_por')->nullable()->after('aprobado_at')->constrained('users')->nullOnDelete();
            $table->timestamp('pagado_at')->nullable()->after('pagado_por');
            $table->string('referencia_pago')->nullable()->after('pagado_at');
        });
    }

    public function down(): void
    {
        Schema::table('boletas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aprobado_por');
            $table->dropColumn('aprobado_at');
            $table->dropConstrainedForeignId('pagado_por');
            $table->dropColumn(['pagado_at', 'referencia_pago']);
        });
    }
};
