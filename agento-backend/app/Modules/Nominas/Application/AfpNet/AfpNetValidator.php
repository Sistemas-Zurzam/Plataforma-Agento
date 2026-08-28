<?php

namespace App\Modules\Nominas\Application\AfpNet;

use App\Modules\Nominas\Domain\AfpNet\AfpNetExportContext;
use App\Modules\Nominas\Domain\AfpNet\ResolverExcepcionAfpNet;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Personas\Models\Colaborador;

/**
 * AfpNetValidator — responde una sola pregunta por ciclo: ¿los datos que
 * REALMENTE participan alcanzan para construir la planilla AFPnet de este
 * período? Solo LEE y REPORTA, nunca calcula nómina/AFP/asistencia — todo
 * eso ya lo calculó CalcularBoletaColaborador y quedó snapshoteado en
 * boleta_conceptos.
 *
 * Completamente independiente de PLAME (Sección 29 del encargo): no
 * importa nada de App\Modules\Nominas\*\Plame, no comparte tablas
 * (afpnet_mapeos ≠ sunat_mapeos), no reutiliza ResolverSuspensionSunat.
 *
 * Sin Controller/Request acoplado (mismo criterio que PlameValidator): se
 * llama con `validar(CicloRemunerativo)` — el ciclo ya autorizado por el
 * caller (nunca recibe empresa_id). Lee el mismo AfpNetExportContext que
 * AfpNetExportService (Sección 7: nunca dos conjuntos de datos distintos).
 */
class AfpNetValidator
{
    private const GRUPO_IDENTIDAD = 'IDENTIDAD';

    private const GRUPO_PREVISIONAL = 'PREVISIONAL';

    private const GRUPO_REMUNERACION = 'REMUNERACION';

    private const GRUPO_EXCEPCION = 'EXCEPCION';

    /**
     * @return array{
     *   empresa_id: int, ciclo_id: int, periodo: array,
     *   listo: bool,
     *   resumen: array{trabajadores: int, bloqueantes: int, observaciones: int, indeterminados: int},
     *   hallazgos: array<int, array>,
     * }
     */
    public function validar(CicloRemunerativo $ciclo): array
    {
        $contexto = AfpNetCicloDatosLoader::cargar($ciclo);

        $hallazgos = [];
        foreach ($contexto->boletasAfp as $boleta) {
            $hallazgos = [...$hallazgos, ...$this->validarTrabajador($contexto, $boleta)];
        }

        return $this->ensamblarResultado($contexto, $hallazgos);
    }

    /**
     * @return array<int, array>
     */
    private function validarTrabajador(AfpNetExportContext $contexto, Boleta $boleta): array
    {
        /** @var Colaborador $colaborador */
        $colaborador = $boleta->colaborador;
        if (! $colaborador) {
            return [];
        }

        $hallazgos = [];

        // --- Identidad / documento ---
        if (blank($colaborador->apellido_paterno)) {
            $hallazgos[] = $this->hallazgo('AFPNET_APELLIDO_FALTANTE', 'error', self::GRUPO_IDENTIDAD, $colaborador, 'Falta el apellido paterno.', 'Completar apellido paterno en la ficha del colaborador.');
        }
        if (blank($colaborador->nombres)) {
            $hallazgos[] = $this->hallazgo('AFPNET_NOMBRES_FALTANTE', 'error', self::GRUPO_IDENTIDAD, $colaborador, 'Faltan los nombres.', 'Completar nombres en la ficha del colaborador.');
        }

        $codigoDocumento = $contexto->mapeos->codigoDocumento($colaborador->tipo_documento);
        if (blank($codigoDocumento)) {
            $hallazgos[] = $this->hallazgo('AFPNET_DOCUMENTO_SIN_MAPEO', 'error', self::GRUPO_IDENTIDAD, $colaborador, "El tipo de documento \"{$colaborador->tipo_documento}\" no tiene código AFPnet configurado.", 'Configurar el mapeo AFPnet de este tipo de documento (fuera del alcance actual: solo dni/ce/pasaporte).');
        }

        // --- CUSPP: opcional, pero A(12) exacto cuando está informado (Sección 4/21) ---
        if (filled($colaborador->cuspp) && mb_strlen($colaborador->cuspp) !== 12) {
            $hallazgos[] = $this->hallazgo('AFPNET_CUSPP_FORMATO_INVALIDO', 'error', self::GRUPO_IDENTIDAD, $colaborador, "El CUSPP \"{$colaborador->cuspp}\" no tiene 12 caracteres (formato A(12) de la guía AFPnet).", 'Corregir el CUSPP en la ficha del colaborador o dejarlo vacío si no se conoce.');
        }

        // --- AFP: opcional, pero si está informada su mapeo debe existir (Sección 6/22) ---
        $condicion = $contexto->condicionVigente($colaborador->id);
        $claveAfp = $condicion?->sistema_previsional ?? $colaborador->sistema_previsional;
        if ($claveAfp && ! $contexto->mapeos->tieneMapeoAfp($claveAfp)) {
            $hallazgos[] = $this->hallazgo('AFPNET_AFP_SIN_MAPEO', 'error', self::GRUPO_PREVISIONAL, $colaborador, "La AFP \"{$claveAfp}\" no tiene código AFPnet configurado.", 'Configurar el mapeo AFPnet de esta AFP.');
        }

        // --- Remuneración asegurable (Sección 8/23) — ERROR ESTRUCTURAL:
        // sin esto no se puede construir ninguna fila.
        $lineaAfp = $boleta->conceptos->first(fn ($c) => $c->concepto?->codigo === 'AFP_APORTE_OBLIGATORIO');
        if (! $lineaAfp || $lineaAfp->base_utilizada === null) {
            $hallazgos[] = $this->hallazgo('AFPNET_REMUNERACION_ASEGURABLE_FALTANTE', 'error', self::GRUPO_REMUNERACION, $colaborador, 'No se pudo determinar la remuneración asegurable (falta la línea AFP_APORTE_OBLIGATORIO en la boleta).', 'Recalcular la boleta de este colaborador.', entidad: 'boleta', entidadId: $boleta->id);

            return $hallazgos;
        }

        $remuneracionAsegurable = (float) $lineaAfp->base_utilizada;

        // --- Excepción de aportar (Sección 12/13/23) — CASO AFPnet NO
        // SOPORTADO: el resolver ya resuelve '', L, U u O por sí mismo
        // (cobertura consolidada + regla segura de O); solo llega acá como
        // error cuando de verdad no se puede construir una fila correcta
        // (ventana laboral incoherente o pagador_subsidio faltante),
        // nunca por J/I/P en abstracto.
        $excepcion = ResolverExcepcionAfpNet::resolver($colaborador, $contexto->ciclo, $remuneracionAsegurable, $contexto->permisosDe($colaborador->id));
        if (! $excepcion['determinado']) {
            $hallazgos[] = $this->hallazgo('AFPNET_EXCEPCION_NO_DETERMINABLE', 'error', self::GRUPO_EXCEPCION, $colaborador, $excepcion['motivo'], $excepcion['accion'], entidad: 'boleta', entidadId: $boleta->id);
        }

        return $hallazgos;
    }

    private function ensamblarResultado(AfpNetExportContext $contexto, array $hallazgos): array
    {
        $bloqueantes = collect($hallazgos)->where('severity', 'error')->count();
        $observaciones = collect($hallazgos)->where('severity', 'warning')->count();
        $indeterminados = collect($hallazgos)->where('code', 'AFPNET_EXCEPCION_NO_DETERMINABLE')->count();

        return [
            'empresa_id' => $contexto->empresa->id,
            'ciclo_id' => $contexto->ciclo->id,
            'periodo' => [
                'fecha_inicio' => $contexto->ciclo->fecha_inicio->toDateString(),
                'fecha_fin' => $contexto->ciclo->fecha_fin->toDateString(),
                'ciclo_estado' => $contexto->ciclo->estado,
            ],
            'listo' => $bloqueantes === 0,
            'resumen' => [
                'trabajadores' => $contexto->boletasAfp->count(),
                'bloqueantes' => $bloqueantes,
                'observaciones' => $observaciones,
                'indeterminados' => $indeterminados,
            ],
            'hallazgos' => $hallazgos,
        ];
    }

    private function hallazgo(string $code, string $severity, string $group, Colaborador $colaborador, string $message, string $action, string $entidad = 'colaborador', ?int $entidadId = null): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'group' => $group,
            'entidad' => $entidad,
            'entidad_id' => $entidadId ?? $colaborador->id,
            'colaborador_id' => $colaborador->id,
            'colaborador_nombre' => trim("{$colaborador->nombres} {$colaborador->apellidos}"),
            'message' => $message,
            'action' => $action,
        ];
    }
}
