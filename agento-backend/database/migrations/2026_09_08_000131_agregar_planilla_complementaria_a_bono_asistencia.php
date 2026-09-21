<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un Bono de Asistencia se genera y aplica DENTRO del ciclo normal (antes de
 * pagarlo) casi siempre — pero Livex también necesita poder generarlo sobre
 * un ciclo YA PAGADO (ver BonoAsistenciaService::aplicar()). En ese caso no
 * hay forma de que colaborador_conceptos_periodo llegue a una boleta ya
 * pagada (nadie vuelve a calcular esa boleta), así que aplicar() crea una
 * Planilla Complementaria dedicada y usa agregarConcepto() — el mismo
 * mecanismo que ya usa el resto del sistema para ajustes sobre boletas ya
 * pagadas. Estas dos columnas registran esa ruta:
 * - bono_asistencia_lotes.planilla_complementaria_id: la complementaria que
 *   aplicar() creó para este lote (null si ningún colaborador del lote tenía
 *   la boleta ya pagada).
 * - bono_asistencia_lote_detalles.planilla_complementaria_detalle_id: qué
 *   línea de esa complementaria le corresponde a CADA colaborador — mismo
 *   propósito de idempotencia que colaborador_concepto_periodo_id (evita
 *   aplicar el bono dos veces si aplicar() se llama más de una vez), solo
 *   que para la ruta de boleta ya pagada en vez de la ruta normal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bono_asistencia_lotes', function (Blueprint $table) {
            $table->foreignId('planilla_complementaria_id')->nullable()
                ->after('concepto_definicion_id')
                ->constrained('planillas_complementarias')->nullOnDelete();
        });

        Schema::table('bono_asistencia_lote_detalles', function (Blueprint $table) {
            $table->foreignId('planilla_complementaria_detalle_id')->nullable()
                ->after('colaborador_concepto_periodo_id');
            // Nombre de FK explícito y corto: el autogenerado supera los 64
            // caracteres que permite MySQL (mismo problema ya corregido en
            // 2026_09_08_000129_crear_bono_asistencia_lote_detalles.php).
            $table->foreign('planilla_complementaria_detalle_id', 'bono_asist_detalle_pcd_id_fk')
                ->references('id')->on('planilla_complementaria_detalles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bono_asistencia_lote_detalles', function (Blueprint $table) {
            $table->dropForeign('bono_asist_detalle_pcd_id_fk');
            $table->dropColumn('planilla_complementaria_detalle_id');
        });

        Schema::table('bono_asistencia_lotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('planilla_complementaria_id');
        });
    }
};
