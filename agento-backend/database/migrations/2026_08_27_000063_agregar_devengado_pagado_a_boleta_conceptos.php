<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PLAME (estructura E18/.rem del Anexo 3 de SUNAT) exige columnas
 * separadas de "monto devengado" y "monto pagado/descontado" por
 * concepto — hoy boleta_conceptos.monto es un único valor. Se agregan
 * ambas sin tocar `monto` (sigue existiendo, sin cambiar su significado
 * actual, para no romper a sus consumidores ya existentes: BoletaResource,
 * BoletaConceptoResource, BoletaImprimibleModal, GestionRemuneraciones,
 * RegistrarConceptoModal, y las proyecciones de renta de 5ta/gratificación
 * en CalcularBoletaColaborador que sí necesitan "lo devengado").
 *
 * Backfill: el sistema nunca tuvo mecanismo de pago parcial — "lo
 * calculado" y "lo reflejado" siempre fueron el mismo valor en los datos
 * existentes. Por eso monto_devengado = monto_pagado_descontado = monto
 * para las filas ya calculadas; no es una suposición hacia adelante, es
 * literalmente el único valor disponible en el histórico.
 *
 * Quedan nullable a nivel de columna (no se usa ->change() porque el
 * proyecto no tiene doctrine/dbal instalado) — la garantía de que siempre
 * se completan ambas queda en BoletaService::calcularBoletaColaborador(),
 * el único punto de creación de boleta_conceptos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boleta_conceptos', function (Blueprint $table) {
            $table->decimal('monto_devengado', 12, 2)->nullable()->after('monto');
            $table->decimal('monto_pagado_descontado', 12, 2)->nullable()->after('monto_devengado');
        });

        DB::table('boleta_conceptos')->update([
            'monto_devengado' => DB::raw('monto'),
            'monto_pagado_descontado' => DB::raw('monto'),
        ]);
    }

    public function down(): void
    {
        Schema::table('boleta_conceptos', function (Blueprint $table) {
            $table->dropColumn(['monto_devengado', 'monto_pagado_descontado']);
        });
    }
};
