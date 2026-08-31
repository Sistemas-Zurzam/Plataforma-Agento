<?php

namespace App\Modules\Nominas\Domain\BbvaNetCash;

/**
 * Constantes de formato BBVA Net Cash — Haberes 4ta/5ta Categoría.
 * Confirmadas directo del VBA/celdas de los macros oficiales
 * (docs/bbva/reference/), NUNCA reutiliza TelecreditoBcpFormato — cada
 * integración bancaria aísla su propia decisión de formato (mismo
 * criterio que Telecrédito/AfpNet/Plame entre sí).
 *
 * Tipo de registro de CABECERA (único campo que distingue 4ta de 5ta en
 * todo el layout — confirmado comparando byte a byte el VBA de ambos
 * macros, ver Módulo1.CargarDatos: `TipoReg = "700"` en el macro 5ta,
 * `TipoReg = "800"` en el macro 4ta). El de DETALLE es "002" en ambos.
 */
final class BbvaNetCashFormato
{
    public const SUBTIPOS_VALIDOS = ['4', '5'];

    private const TIPO_REGISTRO_CABECERA = [
        '5' => '700',
        '4' => '800',
    ];

    public const TIPO_REGISTRO_DETALLE = '002';

    /**
     * Confirmado por la fórmula de validación de la hoja "Detalle"
     * (columna N/O): valores aceptados R, L, M, E, P. R exige documento de
     * 11 dígitos (RUC) y L exige 8 dígitos (DNI) — ambos confirmados por
     * fórmula. M/E/P no tienen chequeo de longitud en el macro; se
     * infieren como Menor/Extranjería/Pasaporte por convención bancaria
     * peruana, PENDIENTE DE HOMOLOGACIÓN el nombre exacto (no hay celda de
     * leyenda para esta columna en el macro).
     *
     * El mapeo con los 4 tipo_documento reales de Agento (dni/ce/
     * pasaporte/ruc — ver StoreColaboradorRequest) es directo: "ruc" en
     * Agento está reservado a tipo_trabajador=locador (Sección 4ta), que
     * es justo el caso que usa este exportador con subtipo=4 — coincide
     * con que "R" exija 11 dígitos.
     */
    private const DOCUMENTO = [
        'dni' => 'L',
        'ruc' => 'R',
        'ce' => 'E',
        'pasaporte' => 'P',
    ];

    /** Confirmado por la fórmula P6 de la hoja "Detalle": P=cuenta propia BBVA, I=interbancaria/CCI. */
    private const TIPO_ABONO_PROPIA = 'P';

    private const TIPO_ABONO_INTERBANCARIA = 'I';

    /**
     * "Tipo de Proceso" (celda C17, dropdown A/F/H) — CONFIRMADO contra un
     * archivo real generado por el macro 4ta (BBVAH4Cat.txt, homologación
     * byte a byte): el valor usado es "A", con Fecha y Hora de ejecución
     * en blanco (el macro solo exige un valor adicional cuando es F/Fecha
     * u H/Hora — con "A" ninguno de los dos aplica). Ya no es una decisión
     * de Agento sin respaldo: es el mismo valor que produce el macro
     * oficial para un envío normal.
     */
    public const TIPO_PROCESO = 'A';

    /**
     * "Validación de Pertenencia" (celda C32, dropdown S/N — S: BBVA valida
     * que el titular de la cuenta coincida con el documento informado).
     * Agento elige "S" por precisión (más seguro que "N"), no porque el
     * macro lo exija — decisión de Agento, documentada.
     */
    public const VALIDACION_PERTENENCIA = 'S';

    public static function tipoRegistroCabecera(string $subtipo): ?string
    {
        return self::TIPO_REGISTRO_CABECERA[$subtipo] ?? null;
    }

    public static function codigoDocumento(string $tipoDocumento): ?string
    {
        return self::DOCUMENTO[$tipoDocumento] ?? null;
    }

    public static function tipoAbono(bool $esBbva): string
    {
        return $esBbva ? self::TIPO_ABONO_PROPIA : self::TIPO_ABONO_INTERBANCARIA;
    }

    /**
     * Réplica exacta de `Utilitario.CompletarDigitoCtrl` del macro: si la
     * cuenta viene en 18 dígitos (sin los 2 dígitos de control), se
     * inserta "00" tras el 8vo dígito — confirmado como comportamiento
     * OFICIAL por la propia instrucción de la hoja "Cabecera" (celda B12):
     * "Si no conoce el DC, ingrese 00 en reemplazo". Si ya viene en 20 (u
     * otra longitud), se deja intacta — la longitud final la valida el
     * formatter, no esta función.
     */
    public static function completarDigitoControl(string $cuenta): string
    {
        if (strlen($cuenta) === 18) {
            return substr($cuenta, 0, 8).'00'.substr($cuenta, 8);
        }

        return $cuenta;
    }
}
