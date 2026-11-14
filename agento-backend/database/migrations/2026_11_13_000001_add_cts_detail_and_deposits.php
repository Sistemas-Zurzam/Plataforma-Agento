<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-ejecutable: en MySQL los ALTER no son transaccionales, así que si una
 * corrida falló a medias (p. ej. "Duplicate column 'detalle_calculo'") volver
 * a correr migrate completa solo lo que falta.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach ([['cts_clasificacion', fn (Blueprint $t) => $t->string('cts_clasificacion', 32)->nullable()],
            ['cts_sustento_referencia', fn (Blueprint $t) => $t->string('cts_sustento_referencia', 255)->nullable()],
            ['cts_dias_acumulados_anuales', fn (Blueprint $t) => $t->unsignedSmallInteger('cts_dias_acumulados_anuales')->nullable()]] as [$columna, $definir]) {
            if (! Schema::hasColumn('asistencia_permisos', $columna)) Schema::table('asistencia_permisos', $definir);
        }
        if (! Schema::hasColumn('beneficio_social_detalles', 'detalle_calculo')) {
            Schema::table('beneficio_social_detalles', fn (Blueprint $t) => $t->json('detalle_calculo')->nullable());
        }
        if (! Schema::hasColumn('beneficio_social_detalles', 'estado_deposito')) {
            Schema::table('beneficio_social_detalles', fn (Blueprint $t) => $t->string('estado_deposito', 30)->default('pendiente_deposito'));
        }
        if (! Schema::hasTable('beneficio_social_depositos')) {
            Schema::create('beneficio_social_depositos',function(Blueprint $table){
                $table->id();$table->foreignId('beneficio_social_detalle_id')->constrained('beneficio_social_detalles')->restrictOnDelete();
                $table->date('fecha_deposito');$table->decimal('importe',12,2)->unsigned();$table->string('entidad',160);$table->string('referencia',160);$table->string('constancia')->nullable();$table->char('idempotencia',64)->unique();$table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();$table->timestamps();
                $table->index(['beneficio_social_detalle_id','fecha_deposito'],'cts_deposito_detalle_fecha_idx');
            });
        }
    }
    public function down(): void { Schema::dropIfExists('beneficio_social_depositos'); Schema::table('beneficio_social_detalles',fn(Blueprint $t)=>$t->dropColumn(['detalle_calculo','estado_deposito'])); Schema::table('asistencia_permisos',fn(Blueprint $t)=>$t->dropColumn(['cts_clasificacion','cts_sustento_referencia','cts_dias_acumulados_anuales'])); }
};
