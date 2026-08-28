<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * V3 Fase 6A — mitigación del bloqueante PLAME: CalcularBoletaColaborador
     * ya deja de agregar la línea DESCUENTO_HORAS_INCOMPLETAS a egresos
     * cuando su monto es 0 (de ahí en adelante nunca más se persiste una
     * fila inerte), pero las boletas YA calculadas antes de este fix pueden
     * tener una fila S/0.00 de ese concepto — esa fila sigue bloqueando el
     * .rem de PLAME exactamente igual, así que también hay que limpiarla.
     *
     * Seguridad verificada antes de escribir este DELETE:
     * - boleta_conceptos.boleta_id es `cascadeOnDelete` hacia `boletas` (la
     *   FK apunta HACIA AFUERA); ninguna otra tabla tiene una FK que apunte
     *   HACIA una fila de boleta_conceptos — no hay auditoría ni snapshot
     *   externo que referencie el id de esta fila puntual.
     * - BeneficioSocialService (CTS/gratificación) filtra por una lista
     *   explícita de códigos (whereIn('codigo', $rango['codigos'])) que
     *   nunca incluye DESCUENTO_HORAS_INCOMPLETAS — no hay cálculo derivado
     *   que dependa de esta fila.
     * - boletas.total_egresos/neto_a_pagar son columnas YA persistidas al
     *   momento del cálculo (no se recalculan sumando boleta_conceptos en
     *   tiempo de lectura) — eliminar una fila de monto 0 no cambia ningún
     *   total ya guardado, ni siquiera en una boleta cerrada/pagada.
     *
     * Filtro deliberadamente estricto: exige monto=0 Y (monto_devengado
     * nulo o 0) Y (monto_pagado_descontado nulo o 0) — si CUALQUIERA de los
     * tres campos monetarios tiene un valor distinto de cero, la fila NO se
     * toca (podría ser un HI real que sí debe seguir bloqueando el .rem
     * hasta homologar su código PLAME, tal como pide V3 Fase 6A).
     */
    public function up(): void
    {
        $conceptoId = DB::table('conceptos_remuneracion')
            ->where('codigo', 'DESCUENTO_HORAS_INCOMPLETAS')
            ->value('id');

        if (! $conceptoId) {
            return;
        }

        DB::table('boleta_conceptos')
            ->where('concepto_id', $conceptoId)
            ->where('tipo', 'egreso')
            ->where('monto', 0)
            ->where(function ($query) {
                $query->whereNull('monto_devengado')->orWhere('monto_devengado', 0);
            })
            ->where(function ($query) {
                $query->whereNull('monto_pagado_descontado')->orWhere('monto_pagado_descontado', 0);
            })
            ->delete();
    }

    /**
     * No reversible — son filas inertes (monto=0) ya identificadas y
     * eliminadas; no hay un respaldo del dato original que restaurar, y no
     * habría ningún efecto que revertir (la fila no aportaba nada al
     * cálculo, a la auditoría ni a ningún total ya persistido).
     */
    public function down(): void {}
};
