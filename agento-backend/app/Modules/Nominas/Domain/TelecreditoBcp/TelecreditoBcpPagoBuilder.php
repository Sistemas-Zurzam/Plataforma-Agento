<?php

namespace App\Modules\Nominas\Domain\TelecreditoBcp;

use App\Modules\Nominas\Infrastructure\TelecreditoBcp\Export\TelecreditoBcpTxtFormatter;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaDatosPago;
use App\Modules\Personas\Models\Colaborador;

/**
 * Estructura PAGO — confirmada campo por campo del PDF (página 3), 195
 * caracteres exactos:
 *
 *   1-1     Tipo de registro          fijo "2"
 *   2-2     Tipo cuenta de abono      A(1) — A/C/M/B
 *   3-22    Número cuenta de abono    A(20), izquierda, espacios
 *   23-23   Tipo documento empleado   A(1)
 *   24-35   Número documento empleado A(12), izquierda, espacios
 *   36-38   Correlativo menor de edad A(3) — 3 espacios si adulto
 *   39-113  Nombre del trabajador     A(75), espacios
 *   114-153 Referencia beneficiario   A(40), espacios
 *   154-173 Referencia empresa       A(20), espacios
 *   174-177 Moneda del importe       A(4)
 *   178-194 Importe a abonar         9(14).99, derecha, ceros (17 caracteres)
 *   195-195 Flag validar IDC         fijo "S" (confirmado contra archivo
 *                                    histórico real aceptado por BCP —
 *                                    ver TelecreditoBcpFormato)
 *
 * Usa EXCLUSIVAMENTE boleta_datos_pago (Sección 17 del encargo) — nunca
 * colaborador.numero_cuenta/cci directamente: los datos actuales del
 * colaborador no deben alterar una instrucción de pago ya formalizada al
 * cerrar el ciclo.
 */
final class TelecreditoBcpPagoBuilder
{
    private const TIPO_REGISTRO = '2';

    public const LONGITUD_TOTAL = 195;

    private const LONGITUD_NOMBRE = 75;

    public static function construir(Boleta $boleta, string $referenciaBeneficiario, string $referenciaEmpresa): string
    {
        /** @var Colaborador $colaborador */
        $colaborador = $boleta->colaborador;
        if (! $colaborador) {
            throw TelecreditoBcpExportException::campoRequeridoFaltante('colaborador', $boleta->colaborador_id);
        }

        /** @var BoletaDatosPago|null $datosPago */
        $datosPago = $boleta->datosPago;
        if (! $datosPago) {
            throw TelecreditoBcpExportException::campoRequeridoFaltante('boleta_datos_pago (snapshot bancario)', $colaborador->id);
        }

        $esBcp = $datosPago->banco?->codigo === 'bcp';

        $tipoCuentaAbono = TelecreditoBcpFormato::codigoTipoCuentaAbono((string) $datosPago->tipo_cuenta_snapshot, $esBcp);
        if (blank($tipoCuentaAbono)) {
            throw TelecreditoBcpExportException::campoRequeridoFaltante('tipo_cuenta_abono', $colaborador->id);
        }

        $cuentaAbono = $esBcp ? $datosPago->numero_cuenta_snapshot : $datosPago->cci_snapshot;
        if (blank($cuentaAbono)) {
            throw TelecreditoBcpExportException::campoRequeridoFaltante($esBcp ? 'numero_cuenta_snapshot' : 'cci_snapshot', $colaborador->id);
        }

        $codigoDocumento = TelecreditoBcpFormato::codigoDocumento($colaborador->tipo_documento);
        if (blank($codigoDocumento)) {
            throw TelecreditoBcpExportException::campoRequeridoFaltante("mapeo Telecrédito de tipo_documento \"{$colaborador->tipo_documento}\"", $colaborador->id);
        }

        // Menores de edad — correlativo BCP no soportado todavía (Sección
        // 22/29): nunca "000", nunca se adivina. Resguardo final: el
        // Validator ya debió excluir esta fila antes de llegar acá.
        if ($colaborador->fecha_nacimiento && $colaborador->fecha_nacimiento->age < 18) {
            throw TelecreditoBcpExportException::campoRequeridoFaltante('correlativo BCP para menor de edad (caso no soportado)', $colaborador->id);
        }
        $correlativoMenor = '   '; // 3 espacios — adulto (único caso soportado).

        $nombre = trim("{$colaborador->apellido_paterno} {$colaborador->apellido_materno} {$colaborador->nombres}");

        $codigoMoneda = TelecreditoBcpFormato::codigoMoneda((string) $datosPago->moneda_snapshot);
        if (blank($codigoMoneda)) {
            throw TelecreditoBcpExportException::campoRequeridoFaltante('moneda_snapshot', $colaborador->id);
        }

        if ((float) $boleta->neto_a_pagar <= 0) {
            throw TelecreditoBcpExportException::campoRequeridoFaltante('neto_a_pagar > 0', $colaborador->id);
        }

        $linea =
            TelecreditoBcpTxtFormatter::codigoFijo(self::TIPO_REGISTRO, 1, 'tipo_registro')
            .TelecreditoBcpTxtFormatter::codigoFijo($tipoCuentaAbono, 1, 'tipo_cuenta_abono')
            .TelecreditoBcpTxtFormatter::textoIzquierda($cuentaAbono, 20, 'numero_cuenta_abono', $colaborador->id)
            .TelecreditoBcpTxtFormatter::codigoFijo($codigoDocumento, 1, 'tipo_documento')
            .TelecreditoBcpTxtFormatter::textoIzquierda($colaborador->numero_documento, 12, 'numero_documento', $colaborador->id)
            .$correlativoMenor
            .TelecreditoBcpTxtFormatter::textoIzquierda($nombre, self::LONGITUD_NOMBRE, 'nombre_trabajador', $colaborador->id)
            .TelecreditoBcpTxtFormatter::textoIzquierda($referenciaBeneficiario, 40, 'referencia_beneficiario')
            .TelecreditoBcpTxtFormatter::textoIzquierda($referenciaEmpresa, 20, 'referencia_empresa')
            .TelecreditoBcpTxtFormatter::codigoFijo($codigoMoneda, 4, 'moneda')
            .TelecreditoBcpTxtFormatter::importe((string) $boleta->neto_a_pagar, 14, 2, 'importe_a_abonar', $colaborador->id)
            .TelecreditoBcpTxtFormatter::codigoFijo(TelecreditoBcpFormato::flagIdc(), 1, 'flag_idc');

        // mb_strlen, NO strlen: acá la línea todavía está en UTF-8 (la
        // conversión a Windows-1252/1 byte por carácter ocurre recién al
        // final, en TelecreditoBcpTxtExporter) — un nombre con Ñ mide más
        // BYTES que CARACTERES en este punto del pipeline sin que eso sea
        // un error; lo que debe dar exacto acá son los 195 caracteres.
        if (mb_strlen($linea) !== self::LONGITUD_TOTAL) {
            throw TelecreditoBcpExportException::longitudLineaIncorrecta('PAGO', mb_strlen($linea), self::LONGITUD_TOTAL);
        }

        return $linea;
    }
}
