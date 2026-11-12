<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * estado_deposito nació con 'pendiente_deposito' para todas las filas. Los
 * beneficios que ya estaban pagados antes del registro de depósitos no
 * tienen depósitos en la tabla nueva, y se mostraban como pendientes. Se
 * marcan como conciliados; los calculados sin pagar quedan pendientes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('beneficio_social_detalles')
            ->where('estado_deposito', 'pendiente_deposito')
            ->whereIn('beneficio_social_id', DB::table('beneficios_sociales')->where('estado', 'pagado')->select('id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('beneficio_social_depositos')
                ->whereColumn('beneficio_social_depositos.beneficio_social_detalle_id', 'beneficio_social_detalles.id'))
            ->update(['estado_deposito' => 'conciliado']);
    }

    public function down(): void
    {
        // Sin reversión: no se puede distinguir qué filas cambió up() de las
        // que se conciliaron después. La columna se elimina en la migración
        // anterior si se revierte por completo.
    }
};
