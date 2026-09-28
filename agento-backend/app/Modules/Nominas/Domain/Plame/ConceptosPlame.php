<?php

namespace App\Modules\Nominas\Domain\Plame;

/**
 * Listas de conceptos compartidas entre PlameValidator y RemGenerator — un
 * solo lugar, nunca dos copias que puedan divergir (la exclusión de .rem
 * debe ser IDÉNTICA en lo que el validador aprueba y en lo que el
 * generador realmente omite).
 */
final class ConceptosPlame
{
    /**
     * Provisiones contables internas y honorarios (E20, no E18) — nunca se
     * declaran en .rem así tengan codigo_plame_snapshot por error de datos
     * viejos (Sección 28/33 del encargo PLAME exportadores).
     */
    public const NO_EXPORTABLES_REM = [
        'HONORARIO_BRUTO', 'RETENCION_RENTA_4TA',
        'CTS_PROVISION', 'GRATIFICACION_LEGAL', 'BONIFICACION_EXTRAORDINARIA', 'VACACIONES_PROVISION',
        // ONP (0607) permanece administrado por SUNAT. Los aportes de salud
        // se exportan según la línea calculada en la boleta: ESSALUD=0804 o
        // SIS_APORTACION=0811; nunca se generan ambos para el mismo cálculo.
        'ONP',
    ];

    /** Requieren una ConceptoDefinicionPlame concreta, no basta el código genérico. */
    public const CON_DEFINICION = ['BONIFICACION', 'BONO_NO_REMUNERATIVO'];

    /**
     * Códigos Tabla 22 que son encabezados de sección o aportaciones que
     * administra directamente SUNAT (nunca un concepto individual que un
     * empleador declare por trabajador) — Anexo 3, hoja E18, observación
     * del campo 3: "No incluir códigos ...". Si un mapeo terminara
     * apuntando a uno de estos por error, es un dato de catálogo mal
     * configurado, no algo que el Generator deba aceptar en silencio.
     */
    public const CODIGOS_EXCLUIDOS_REM = [
        '0100', '0200', '0300', '0400', '0500', '0600', '0603', '0604', '0607', '0610', '0612', '0616',
        '0800', '0802', '0806', '0808',
    ];
}
