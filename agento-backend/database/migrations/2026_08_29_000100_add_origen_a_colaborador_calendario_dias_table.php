<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase 4B — trazabilidad mínima de colaborador_calendario_dias: hoy no
     * hay forma de distinguir una fila generada automáticamente de una
     * decisión humana real, lo que hace inseguro invalidar calendario al
     * cambiar de horario (Fase 4C, todavía no implementada).
     *
     * Sin default a propósito: un default como 'manual' o 'automatico'
     * mentiría tanto para escrituras nuevas que alguien olvide clasificar
     * como para las filas históricas — NULL deliberado = "origen
     * desconocido, no asumir que es seguro invalidar" (ver docblock de
     * ColaboradorCalendarioDia::ORIGEN_* para el criterio completo).
     *
     * Sin índice nuevo: los índices existentes por (colaborador_id, fecha)
     * ya cubren las consultas actuales; agregar uno compuesto con `origen`
     * queda para cuando la Fase 4C realmente lo necesite, no antes.
     */
    public function up(): void
    {
        Schema::table('colaborador_calendario_dias', function (Blueprint $table) {
            $table->string('origen', 40)->nullable()->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('colaborador_calendario_dias', function (Blueprint $table) {
            $table->dropColumn('origen');
        });
    }
};
