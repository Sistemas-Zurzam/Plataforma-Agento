<?php

namespace App\Modules\Nominas\Infrastructure\AfpNet\Export;

/**
 * TXT AFPnet de longitud fija (Sección 23/24 del encargo) — SIN
 * separadores, cada línea con exactamente 141 posiciones:
 *
 *   1-5       secuencia            9(5)
 *   6-17      CUSPP                A(12)
 *   18        tipo documento       A(1)
 *   19-38     número documento     A(20)
 *   39-58     apellido paterno     A(20)
 *   59-78     apellido materno     A(20)
 *   79-98     nombres              A(20)
 *   99        relación laboral     A(1)
 *   100       inicio relación      A(1)
 *   101       cese                 A(1)
 *   102       excepción            A(1)
 *   103-111   remuneración         9(7).9(2) sin punto
 *   112-120   AV con fin           9(7).9(2) sin punto
 *   121-129   AV sin fin           9(7).9(2) sin punto
 *   130-138   AV empleador         9(7).9(2) sin punto
 *   139       tipo de trabajo      A(1)
 *   140-141   AFP                  A(2)
 *
 * Nunca reutiliza PlameTxtSerializer (formato completamente distinto).
 */
final class AfpNetTxtExporter
{
    private const LONGITUD_LINEA = 141;

    private const LONGITUD_DOCUMENTO = 20;

    private const LONGITUD_NOMBRE = 20;

    private const DIGITOS_ENTEROS_IMPORTE = 7;

    private const DIGITOS_DECIMALES_IMPORTE = 2;

    /**
     * @param  array<int, array<string, mixed>>  $filas  Filas ya construidas por AfpNetFilaBuilder.
     */
    public static function generar(array $filas): string
    {
        $lineas = array_map(fn (array $fila) => self::linea($fila), $filas);

        $texto = implode("\r\n", $lineas);
        if ($lineas !== []) {
            $texto .= "\r\n";
        }

        return mb_convert_encoding($texto, AfpNetTxtFormatter::ENCODING, 'UTF-8');
    }

    private static function linea(array $fila): string
    {
        $colaboradorId = null; // Solo para mensajes de error — no forma parte de la fila.

        $linea =
            AfpNetTxtFormatter::numeroEntero((int) $fila['secuencia'], 5, 'secuencia')
            .AfpNetTxtFormatter::texto((string) ($fila['cuspp'] ?? ''), 12, 'cuspp', $colaboradorId)
            .AfpNetTxtFormatter::texto((string) $fila['tipo_documento'], 1, 'tipo_documento', $colaboradorId)
            .AfpNetTxtFormatter::texto((string) $fila['numero_documento'], self::LONGITUD_DOCUMENTO, 'numero_documento', $colaboradorId)
            .AfpNetTxtFormatter::texto((string) $fila['apellido_paterno'], self::LONGITUD_NOMBRE, 'apellido_paterno', $colaboradorId)
            .AfpNetTxtFormatter::texto((string) $fila['apellido_materno'], self::LONGITUD_NOMBRE, 'apellido_materno', $colaboradorId)
            .AfpNetTxtFormatter::texto((string) $fila['nombres'], self::LONGITUD_NOMBRE, 'nombres', $colaboradorId)
            .AfpNetTxtFormatter::texto((string) $fila['relacion_laboral'], 1, 'relacion_laboral', $colaboradorId)
            .AfpNetTxtFormatter::texto((string) $fila['inicio_relacion_laboral'], 1, 'inicio_relacion_laboral', $colaboradorId)
            .AfpNetTxtFormatter::texto((string) $fila['cese_relacion_laboral'], 1, 'cese_relacion_laboral', $colaboradorId)
            .AfpNetTxtFormatter::texto((string) $fila['excepcion_aportar'], 1, 'excepcion_aportar', $colaboradorId)
            .AfpNetTxtFormatter::importe((string) $fila['remuneracion_asegurable'], self::DIGITOS_ENTEROS_IMPORTE, self::DIGITOS_DECIMALES_IMPORTE, 'remuneracion_asegurable', $colaboradorId)
            .AfpNetTxtFormatter::importe((string) $fila['aporte_voluntario_con_fin'], self::DIGITOS_ENTEROS_IMPORTE, self::DIGITOS_DECIMALES_IMPORTE, 'aporte_voluntario_con_fin', $colaboradorId)
            .AfpNetTxtFormatter::importe((string) $fila['aporte_voluntario_sin_fin'], self::DIGITOS_ENTEROS_IMPORTE, self::DIGITOS_DECIMALES_IMPORTE, 'aporte_voluntario_sin_fin', $colaboradorId)
            .AfpNetTxtFormatter::importe((string) $fila['aporte_voluntario_empleador'], self::DIGITOS_ENTEROS_IMPORTE, self::DIGITOS_DECIMALES_IMPORTE, 'aporte_voluntario_empleador', $colaboradorId)
            .AfpNetTxtFormatter::texto((string) $fila['tipo_trabajo'], 1, 'tipo_trabajo', $colaboradorId)
            .AfpNetTxtFormatter::texto((string) ($fila['afp'] ?? ''), 2, 'afp', $colaboradorId);

        // Resguardo final: la suma de longitudes fijas SIEMPRE debe dar
        // 141 — si no, hay un error de programación en el formatter, no
        // un dato de negocio (nunca se debe generar una línea corta/larga).
        if (mb_strlen($linea) !== self::LONGITUD_LINEA) {
            throw new \LogicException('Línea AFPnet TXT con longitud incorrecta: '.mb_strlen($linea).' (esperado '.self::LONGITUD_LINEA.').');
        }

        return $linea;
    }
}
