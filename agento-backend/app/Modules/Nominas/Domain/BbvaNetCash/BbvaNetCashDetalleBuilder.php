<?php

namespace App\Modules\Nominas\Domain\BbvaNetCash;

use App\Modules\Nominas\Infrastructure\BbvaNetCash\Export\BbvaNetCashTxtFormatter;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaDatosPago;
use App\Modules\Personas\Models\Colaborador;

/**
 * Estructura DETALLE — confirmada campo por campo cruzando el VBA
 * (Módulo2.GrabarDetalle) con las posiciones documentadas en la fila 4 de
 * la hoja "Detalle" de los macros oficiales. 233 caracteres exactos,
 * BYTE A BYTE idéntica en 4ta y 5ta (mismo VBA, mismas posiciones):
 *
 *   1-3     Tipo de registro       fijo "002"
 *   4       Tipo de documento      1, ver BbvaNetCashFormato::codigoDocumento()
 *   5-16    Número de documento    12, izquierda, espacios
 *   17      Tipo de abono          1, P=cuenta propia BBVA / I=interbancaria (CCI)
 *   18-37   Cuenta a abonar        20, ver completarDigitoControl()
 *   38-77   Nombre del beneficiario 40, izquierda, espacios, mayúsculas
 *   78-92   Importe a abonar       9(13).99 sin punto, 15 dígitos, derecha, ceros
 *   93-132  Referencia             40, izquierda, espacios
 *   133     Indicador de aviso     1, en blanco (Agento no tiene canal de aviso BBVA todavía)
 *   134-183 Medio de aviso         50, en blanco
 *   184-233 Filler                 50, en blanco
 *
 * Usa EXCLUSIVAMENTE boleta_datos_pago (snapshot congelado al cerrar el
 * ciclo) — nunca colaborador.numero_cuenta/cci directamente: los datos
 * actuales del colaborador no deben alterar una instrucción de pago ya
 * formalizada al cerrar el ciclo (mismo criterio que TelecreditoBcpPagoBuilder).
 */
final class BbvaNetCashDetalleBuilder
{
    private const LONGITUD_TOTAL = 233;

    public static function construir(Boleta $boleta, string $referencia): string
    {
        /** @var Colaborador $colaborador */
        $colaborador = $boleta->colaborador;
        if (! $colaborador) {
            throw BbvaNetCashExportException::campoRequeridoFaltante('colaborador', $boleta->colaborador_id);
        }

        /** @var BoletaDatosPago|null $datosPago */
        $datosPago = $boleta->datosPago;
        if (! $datosPago) {
            throw BbvaNetCashExportException::campoRequeridoFaltante('boleta_datos_pago (snapshot bancario)', $colaborador->id);
        }

        $esBbva = $datosPago->banco?->codigo === 'bbva';

        $codigoDocumento = BbvaNetCashFormato::codigoDocumento($colaborador->tipo_documento);
        if (blank($codigoDocumento)) {
            throw BbvaNetCashExportException::campoRequeridoFaltante("mapeo BBVA Net Cash de tipo_documento \"{$colaborador->tipo_documento}\"", $colaborador->id);
        }

        $cuentaAbono = $esBbva ? $datosPago->numero_cuenta_snapshot : $datosPago->cci_snapshot;
        if (blank($cuentaAbono)) {
            throw BbvaNetCashExportException::campoRequeridoFaltante($esBbva ? 'numero_cuenta_snapshot' : 'cci_snapshot', $colaborador->id);
        }

        if ((float) $boleta->neto_a_pagar <= 0) {
            throw BbvaNetCashExportException::campoRequeridoFaltante('neto_a_pagar > 0', $colaborador->id);
        }

        $nombre = mb_strtoupper(trim("{$colaborador->apellido_paterno} {$colaborador->apellido_materno} {$colaborador->nombres}"), 'UTF-8');

        $linea =
            BbvaNetCashTxtFormatter::codigoFijo(BbvaNetCashFormato::TIPO_REGISTRO_DETALLE, 3, 'tipo_registro')
            .BbvaNetCashTxtFormatter::codigoFijo($codigoDocumento, 1, 'tipo_documento')
            .BbvaNetCashTxtFormatter::izquierda($colaborador->numero_documento, 12, ' ')
            .BbvaNetCashTxtFormatter::codigoFijo(BbvaNetCashFormato::tipoAbono($esBbva), 1, 'tipo_abono')
            .BbvaNetCashTxtFormatter::izquierda(BbvaNetCashFormato::completarDigitoControl($cuentaAbono), 20, ' ')
            .BbvaNetCashTxtFormatter::izquierda($nombre, 40, ' ')
            .BbvaNetCashTxtFormatter::derecha(BbvaNetCashTxtFormatter::formatoImporte((string) $boleta->neto_a_pagar, 'importe_a_abonar', $colaborador->id), 15, '0')
            .BbvaNetCashTxtFormatter::izquierda($referencia, 40, ' ')
            .BbvaNetCashTxtFormatter::izquierda('', 1, ' ')
            .BbvaNetCashTxtFormatter::izquierda('', 50, ' ')
            .BbvaNetCashTxtFormatter::izquierda('', 50, ' ');

        if (mb_strlen($linea) !== self::LONGITUD_TOTAL) {
            throw BbvaNetCashExportException::longitudLineaIncorrecta('DETALLE', mb_strlen($linea), self::LONGITUD_TOTAL);
        }

        return $linea;
    }
}
