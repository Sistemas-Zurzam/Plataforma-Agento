<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('liquidaciones_cese', function (Blueprint $table) {
            $table->json('alertas')->nullable()->after('neto_pagar');
            $table->foreignId('aprobado_por')->nullable()->after('calculado_at')->constrained('users')->nullOnDelete();
            $table->timestamp('aprobado_at')->nullable()->after('aprobado_por');
            $table->foreignId('pagado_por')->nullable()->after('aprobado_at')->constrained('users')->nullOnDelete();
            $table->timestamp('pagado_at')->nullable()->after('pagado_por');
            $table->string('referencia_pago')->nullable()->after('pagado_at');
            $table->foreignId('anulado_por')->nullable()->after('referencia_pago')->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_at')->nullable()->after('anulado_por');
            $table->string('motivo_anulacion', 255)->nullable()->after('anulado_at');
        });

        Schema::create('vacacion_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('colaborador_id')->constrained('colaboradores');
            $table->date('fecha');
            $table->string('tipo', 30); // devengo_inicial | goce | pago | ajuste
            $table->decimal('dias', 8, 4); // positivo suma saldo, negativo lo reduce
            $table->string('descripcion', 255);
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['colaborador_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacacion_movimientos');
        Schema::table('liquidaciones_cese', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aprobado_por');
            $table->dropConstrainedForeignId('pagado_por');
            $table->dropConstrainedForeignId('anulado_por');
            $table->dropColumn(['alertas', 'aprobado_at', 'pagado_at', 'referencia_pago', 'anulado_at', 'motivo_anulacion']);
        });
    }
};
