<?php

namespace App\Modules\Nominas\Infrastructure\Plame\Export;

use App\Modules\Nominas\Domain\Plame\PlameExportContext;
use App\Modules\Nominas\Domain\Plame\PlameExportException;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Personas\Models\Colaborador;

/**
 * Estructura E7 (.ps4) — Prestadores de Servicios con Rentas de 4ta
 * categoría (locadores). Solo colaboradores con régimen "Locación de
 * Servicios" del ciclo (Sección 34) — nunca trabajadores dependientes.
 *
 * Campos exactos (Anexo 3, hoja "E7-PS 4ta"), en este orden:
 *  1. Tipo de documento del prestador  (Texto, Tabla 3; si es RUC, tipo 06)
 *  2. Número de documento del prestador (Texto, máx 15; RUC máx 11)
 *  3. Apellido paterno                 (Texto, máx 40)
 *  4. Apellido materno                 (Texto, máx 40)
 *  5. Nombres                          (Texto, máx 40)
 *  6. Domiciliado                      (Texto, 1: 1=Domiciliado / 2=No domiciliado)
 *  7. Convenio para evitar doble tributación (Texto, 1, Tabla 25)
 *
 * Campo 7: Agento no registra convenios de doble tributación por
 * colaborador (no hay ningún dato funcional que lo respalde todavía) — se
 * usa el código oficial "0 = NINGUNO" de la Tabla 25, el valor correcto
 * para la inmensa mayoría de locadores peruanos. Documentado en la entrega
 * final como una decisión explícita, no una invención silenciosa; si algún
 * locador sí tuviera un convenio vigente, hoy Agento no lo puede declarar.
 */
final class Ps4Generator
{
    private const MAX_LONGITUD_NOMBRE = 40;

    private const CODIGO_SIN_CONVENIO = '0';

    /**
     * @return array<int, array<int, string>>
     */
    public function generar(PlameExportContext $contexto): array
    {
        return $contexto->boletasRh
            ->sortBy([
                fn (Boleta $a, Boleta $b) => $a->colaborador->tipo_documento <=> $b->colaborador->tipo_documento,
                fn (Boleta $a, Boleta $b) => $a->colaborador->numero_documento <=> $b->colaborador->numero_documento,
            ])
            ->map(fn (Boleta $boleta) => $this->registro($contexto, $boleta))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function registro(PlameExportContext $contexto, Boleta $boleta): array
    {
        /** @var Colaborador $colaborador */
        $colaborador = $boleta->colaborador;
        if (! $colaborador) {
            throw PlameExportException::campoRequeridoFaltante('colaborador', $boleta->colaborador_id);
        }

        $tipoDocumento = $contexto->mapeos->codigo('tipo_documento', $colaborador->tipo_documento);

        $apellidoPaterno = $this->truncarOFallar($colaborador->apellido_paterno, 'apellido_paterno', $colaborador->id);
        $apellidoMaterno = $colaborador->apellido_materno ?? '';
        if (strlen($apellidoMaterno) > self::MAX_LONGITUD_NOMBRE) {
            throw PlameExportException::valorFueraDeRango("apellido_materno (colaborador_id={$colaborador->id})", $apellidoMaterno, 'máximo 40 caracteres — Anexo 3, E7');
        }
        $nombres = $this->truncarOFallar($colaborador->nombres, 'nombres', $colaborador->id);

        if ($colaborador->domiciliado === null) {
            throw PlameExportException::campoRequeridoFaltante('domiciliado', $colaborador->id);
        }
        $domiciliado = $colaborador->domiciliado ? '1' : '2';

        return [
            $tipoDocumento,
            $colaborador->numero_documento,
            $apellidoPaterno,
            $apellidoMaterno,
            $nombres,
            $domiciliado,
            self::CODIGO_SIN_CONVENIO,
        ];
    }

    private function truncarOFallar(?string $valor, string $campo, int $colaboradorId): string
    {
        if (blank($valor)) {
            throw PlameExportException::campoRequeridoFaltante($campo, $colaboradorId);
        }

        if (strlen($valor) > self::MAX_LONGITUD_NOMBRE) {
            // No truncar silenciosamente (Sección 14) — un nombre de más de
            // 40 caracteres es un dato real que RR.HH. debe decidir cómo
            // abreviar, no algo que el exportador recorte a ciegas.
            throw PlameExportException::valorFueraDeRango("{$campo} (colaborador_id={$colaboradorId})", $valor, 'máximo 40 caracteres — Anexo 3, E7');
        }

        return $valor;
    }
}
