<?php

namespace App\Modules\Nominas\Domain\Plame;

use App\Modules\Configuracion\Models\Empresa;

/**
 * PDT PLAME se declara por RUC — sin uno válido, ningún archivo (.jor, .snl,
 * .rem, .ps4, .4ta) puede generarse para la empresa. `empresas.ruc` sigue
 * siendo nullable (una empresa puede estar en alta antes de tener RUC), así
 * que esta regla es un gate que se evalúa antes de exportar, no una
 * restricción de base de datos.
 */
class RequisitoRucPlame
{
    public static function esValido(Empresa $empresa): bool
    {
        return self::motivoInvalidez($empresa) === null;
    }

    public static function motivoInvalidez(Empresa $empresa): ?string
    {
        if (blank($empresa->ruc)) {
            return 'La empresa no tiene RUC registrado.';
        }

        if (! preg_match('/^\d{11}$/', $empresa->ruc)) {
            return 'El RUC registrado no tiene el formato válido (11 dígitos numéricos).';
        }

        return null;
    }
}
