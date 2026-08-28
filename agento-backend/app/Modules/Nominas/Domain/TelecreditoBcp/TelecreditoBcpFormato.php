<?php

namespace App\Modules\Nominas\Domain\TelecreditoBcp;

/**
 * Constantes de formato Telecrédito BCP — Planilla de Haberes (Sección 30
 * del encargo de preparación). Códigos fijos e inmutables del formato
 * binario de BCP, confirmados directo de "Estructura BCP planilla
 * Haberes.pdf" — nunca reutiliza sunat_mapeos ni afpnet_mapeos, y
 * deliberadamente NO es una tabla `telecredito_mapeos`: a diferencia de
 * los catálogos SUNAT/AFPnet (que requieren verificación progresiva y
 * estado administrable), estos 3-4 códigos jamás requieren configuración
 * por el usuario — mismo criterio que el "0601" fijo de PlameFilenameBuilder.
 */
final class TelecreditoBcpFormato
{
    /** Confirmado del PDF (página 3): "Tipo de documento del empleado: 1: DNI, 3: CE, 4: PAS". */
    private const DOCUMENTO = [
        'dni' => '1',
        'ce' => '3',
        'pasaporte' => '4',
    ];

    /** Confirmado del PDF (página 2, campo 6). */
    private const MONEDA = [
        'PEN' => '0001',
        'USD' => '1001',
    ];

    /**
     * Confirmado del PDF (página 2, campo 5): cabecera (cuenta de cargo)
     * solo acepta C/M — "ahorro" no aplica a una cuenta de cargo
     * empresarial.
     */
    private const TIPO_CUENTA_CARGO = [
        'corriente' => 'C',
        'maestra' => 'M',
    ];

    /**
     * Confirmado del PDF (página 3, campo 2): pago (detalle) sí acepta A.
     * "B" (Interbancaria) NUNCA se guarda como tipo_cuenta del colaborador
     * — es una decisión del formato Telecrédito cuando el banco no es BCP
     * (Sección 18 del encargo).
     */
    private const TIPO_CUENTA_ABONO_BCP = [
        'ahorro' => 'A',
        'corriente' => 'C',
        'maestra' => 'M',
    ];

    private const TIPO_CUENTA_ABONO_INTERBANCARIA = 'B';

    private const FLAG_IDC_OBLIGATORIO = '1';

    public static function codigoDocumento(string $tipoDocumento): ?string
    {
        return self::DOCUMENTO[$tipoDocumento] ?? null;
    }

    public static function codigoMoneda(string $moneda): ?string
    {
        return self::MONEDA[$moneda] ?? null;
    }

    public static function codigoTipoCuentaCargo(string $tipoCuenta): ?string
    {
        return self::TIPO_CUENTA_CARGO[$tipoCuenta] ?? null;
    }

    /**
     * @param  bool  $esBcp  Si la cuenta de abono pertenece a BCP (Sección
     *      17/19): si no, el tipo SIEMPRE es "B" (Interbancaria) sin
     *      importar el tipo_cuenta interno del colaborador.
     */
    public static function codigoTipoCuentaAbono(string $tipoCuenta, bool $esBcp): ?string
    {
        if (! $esBcp) {
            return self::TIPO_CUENTA_ABONO_INTERBANCARIA;
        }

        return self::TIPO_CUENTA_ABONO_BCP[$tipoCuenta] ?? null;
    }

    public static function flagIdc(): string
    {
        return self::FLAG_IDC_OBLIGATORIO;
    }
}
