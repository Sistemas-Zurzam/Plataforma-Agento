<?php

namespace App\Modules\Nominas\Infrastructure\Plame\Export;

/**
 * Serializador único de texto plano PLAME (Sección 8/9), compartido por los
 * 5 Generators — nunca se repite `implode('|', ...)` suelto en cada uno.
 *
 * Confirmado en el Anexo 3 (E7/E14/E15/E18/E20): "Los campos deben estar
 * separados por el carácter '|'". NO hay ninguna hoja del Anexo 3 que
 * indique encoding o salto de línea — SUNAT simplemente no lo documenta ahí.
 * ENCODING y LINE_ENDING de abajo son una elección explícita de Agento (no
 * un dato verificado en el Anexo), documentada en la entrega final: ISO-8859-1
 * y CRLF son la convención más citada para los antiguos importadores
 * Java/Swing del PDT en Windows. Si la importación real a PDT PLAME falla
 * por encoding/saltos de línea, este es el único lugar que hay que tocar.
 *
 * Palote final: cada línea termina en "|" además de separar los campos
 * entre sí (ej. "campo1|campo2|campo3|"), y un campo vacío se representa
 * como "||" sin espacio — igual que el separador, esto TAMPOCO aparece
 * escrito de forma literal en el texto de las 5 hojas del Anexo 3 que se
 * revisaron; se aplica por indicación explícita de auditoría (comportamiento
 * real conocido del importador PDT), pendiente de confirmar en la
 * importación real igual que encoding/EOL.
 */
final class PlameTxtSerializer
{
    public const SEPARADOR = '|';

    public const ENCODING = 'ISO-8859-1';

    public const LINE_ENDING = "\r\n";

    /**
     * @param  array<int, array<int, string>>  $filas
     */
    public static function serializar(array $filas): string
    {
        $lineas = array_map(
            fn (array $fila) => implode(self::SEPARADOR, $fila).self::SEPARADOR,
            $filas,
        );

        $texto = implode(self::LINE_ENDING, $lineas);
        if ($lineas !== []) {
            $texto .= self::LINE_ENDING;
        }

        return mb_convert_encoding($texto, self::ENCODING, 'UTF-8');
    }
}
