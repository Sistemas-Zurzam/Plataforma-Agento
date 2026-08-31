<?php

namespace App\Modules\Nominas\Domain\BbvaNetCash;

use App\Modules\Configuracion\Models\EmpresaCuentaBancaria;
use App\Modules\Nominas\Infrastructure\BbvaNetCash\Export\BbvaNetCashTxtFormatter;

/**
 * Estructura CABECERA — confirmada campo por campo cruzando el VBA
 * (Módulo1.cargarCabecera) con las posiciones documentadas en la propia
 * hoja "Cabecera" (columna D, celdas D10/D14/D17/D21/D25/D29/D32) de los
 * macros oficiales. 151 caracteres exactos, idéntica en 4ta y 5ta salvo el
 * tipo de registro (ver BbvaNetCashFormato::tipoRegistroCabecera):
 *
 *   1-3     Tipo de registro         fijo "700" (5ta) / "800" (4ta)
 *   4-23    Cuenta de cargo          20, oficina(4)+DC(2)+cuenta — ver completarDigitoControl()
 *   24-26   Moneda                   A(3) — PEN/USD
 *   27-41   Importe a cargar (total) 9(13).99 sin punto, 15 dígitos, derecha, ceros
 *   42      Tipo de proceso          fijo "A" — CONFIRMADO byte a byte contra un archivo
 *                                    real generado por el macro (BBVAH4Cat.txt): con "A"
 *                                    ni Fecha ni Hora de ejecución se completan.
 *   43-50   Fecha de proceso         8, en blanco (solo se exige si Tipo de proceso = "F")
 *   51      Horario de ejecución     1, en blanco (solo se exige si Tipo de proceso = "H")
 *   52-76   Referencia               25, izquierda, espacios
 *   77-82   Cantidad de registros    6, derecha, ceros
 *   83      Validación de pertenencia fijo "S" (coincide con el ejemplo real del macro)
 *   84-98   Valor de control         15, en blanco (el macro nunca lo calcula)
 *   99-101  Indicador de proceso     3, en blanco
 *   102-131 Descripción              30, en blanco
 *   132-151 Filler                   20, en blanco
 *
 * No existe TRAILER — confirmado: `Grabar()` solo escribe 1 línea de
 * cabecera (fila 1 de la hoja BBVAHABE/BBVAH4Cat) + N líneas de detalle.
 *
 * HOMOLOGACIÓN: esta cabecera fue comparada byte a byte contra
 * BBVAH4Cat.txt (archivo real generado por el macro oficial 4ta) usando
 * los mismos datos de entrada — coincide exactamente, incluyendo el
 * cálculo del importe total a partir de los detalles.
 */
final class BbvaNetCashHeaderBuilder
{
    private const LONGITUD_TOTAL = 151;

    public static function construir(
        EmpresaCuentaBancaria $cuentaCargo,
        string $subtipo,
        int $cantidadAbonos,
        string $montoTotal,
        string $referencia,
    ): string {
        $tipoRegistro = BbvaNetCashFormato::tipoRegistroCabecera($subtipo);
        if ($tipoRegistro === null) {
            throw BbvaNetCashExportException::formatoInvalido('subtipo', $subtipo);
        }

        $cuentaCargoCompleta = BbvaNetCashFormato::completarDigitoControl($cuentaCargo->numero_cuenta);

        $linea =
            BbvaNetCashTxtFormatter::codigoFijo($tipoRegistro, 3, 'tipo_registro')
            .BbvaNetCashTxtFormatter::izquierda($cuentaCargoCompleta, 20, ' ')
            .BbvaNetCashTxtFormatter::codigoFijo($cuentaCargo->moneda, 3, 'moneda_cargo')
            .BbvaNetCashTxtFormatter::derecha(BbvaNetCashTxtFormatter::formatoImporte($montoTotal, 'importe_a_cargar'), 15, '0')
            .BbvaNetCashTxtFormatter::codigoFijo(BbvaNetCashFormato::TIPO_PROCESO, 1, 'tipo_proceso')
            .BbvaNetCashTxtFormatter::izquierda('', 8, ' ')
            .BbvaNetCashTxtFormatter::izquierda('', 1, ' ')
            .BbvaNetCashTxtFormatter::izquierda($referencia, 25, ' ')
            .BbvaNetCashTxtFormatter::derecha((string) $cantidadAbonos, 6, '0')
            .BbvaNetCashTxtFormatter::codigoFijo(BbvaNetCashFormato::VALIDACION_PERTENENCIA, 1, 'validacion_pertenencia')
            .BbvaNetCashTxtFormatter::derecha('', 15, '0')
            .BbvaNetCashTxtFormatter::derecha('', 3, '0')
            .BbvaNetCashTxtFormatter::izquierda('', 30, ' ')
            .BbvaNetCashTxtFormatter::izquierda('', 20, ' ');

        if (mb_strlen($linea) !== self::LONGITUD_TOTAL) {
            throw BbvaNetCashExportException::longitudLineaIncorrecta('CABECERA', mb_strlen($linea), self::LONGITUD_TOTAL);
        }

        return $linea;
    }
}
