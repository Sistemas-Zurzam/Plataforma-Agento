<?php

namespace App\Modules\Nominas\Application\Plame;

use App\Modules\Asistencia\Domain\Plame\ResolverSuspensionSunat;
use App\Modules\Asistencia\Models\AsistenciaPermiso;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Domain\Plame\ConceptosPlame;
use App\Modules\Nominas\Domain\Plame\RequisitoRucPlame;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\SunatMapeo;
use App\Modules\Nominas\Services\SunatCatalogoService;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorCondicionLaboral;
use Illuminate\Support\Collection;

/**
 * PlameValidator — responde una sola pregunta por período: ¿los datos que
 * REALMENTE participan en este ciclo alcanzan para construir cada archivo
 * SUNAT? NO calcula nómina, asistencia, AFP, sueldo, horas extra ni
 * vacaciones — todo eso ya lo calculó BoletaService/CalcularBoletaColaborador
 * y quedó snapshoteado en boleta_conceptos; acá solo se LEE, VALIDA y
 * REPORTA. Un mapping SUNAT pendiente que no se usó en el período NUNCA
 * bloquea — solo bloquea cuando el dato faltante realmente participa.
 *
 * Sin Controller/Request acoplado a propósito (Sección 53): se instancia
 * con `app(PlameValidator::class)` o inyección normal y se llama con
 * `validar(Empresa, CicloRemunerativo)`, para poder probarse aparte.
 */
class PlameValidator
{
    private const GRUPO_GENERAL = 'GENERAL';

    private const GRUPO_PLANILLA = 'PLANILLA';

    private const GRUPO_JORNADA = 'JORNADA';

    private const GRUPO_SUSPENSIONES = 'SUSPENSIONES';

    private const GRUPO_REMUNERACIONES = 'REMUNERACIONES';

    private const GRUPO_RH = 'RH';

    /**
     * Un hallazgo GENERAL (ej. RUC de empresa faltante) bloquea los 5
     * archivos a la vez — se etiqueta con todos para que el desglose
     * por archivo (Sección 47) también lo refleje.
     */
    private const TODOS_LOS_ARCHIVOS = ['jor', 'snl', 'rem', 'ps4', 'cuarta'];

    /**
     * @return array{
     *   empresa_id: int, ciclo_id: int, periodo: array,
     *   listo: bool,
     *   resumen: array{trabajadores: int, rh: int, bloqueantes: int, observaciones: int},
     *   archivos: array<string, array{estado: string, registros: int, bloqueantes: int, observaciones: int}>,
     *   hallazgos: array<int, array>,
     * }
     */
    public function validar(CicloRemunerativo $ciclo): array
    {
        $empresa = $ciclo->empresa;
        $hallazgos = [];

        $hallazgos = [...$hallazgos, ...$this->validarEmpresaYCiclo($empresa, $ciclo)];

        $boletasPlanilla = PlameCicloDatosLoader::boletasPlanilla($ciclo);
        $boletasRh = PlameCicloDatosLoader::boletasRh($ciclo);

        // Mapeos genéricos cargados de una sola vez (Sección 50: nunca N
        // queries por colaborador) — indexados por tipo → clave_interna.
        $mapeos = SunatMapeo::whereIn('tipo', ['tipo_documento', 'tipo_trabajador', 'regimen_laboral', 'tipo_comprobante_rh'])
            ->get()
            ->groupBy('tipo')
            ->map(fn (Collection $g) => $g->keyBy('clave_interna'));

        // Historial contractual/previsional de TODOS los colaboradores del
        // ciclo en una sola consulta, resuelto luego en memoria por fecha —
        // evita 1 query por colaborador.
        $colaboradorIds = $boletasPlanilla->pluck('colaborador_id')->merge($boletasRh->pluck('colaborador_id'))->unique();
        $condicionesPorColaborador = PlameCicloDatosLoader::condicionesPorColaborador($colaboradorIds);

        $hallazgos = [...$hallazgos, ...$this->validarPlanilla($boletasPlanilla, $ciclo, $mapeos, $condicionesPorColaborador)];
        $hallazgos = [...$hallazgos, ...$this->validarJor($boletasPlanilla)];
        $hallazgos = [...$hallazgos, ...$this->validarSnl($boletasPlanilla, $ciclo, $mapeos)];
        $hallazgos = [...$hallazgos, ...$this->validarRem($boletasPlanilla)];
        $hallazgos = [...$hallazgos, ...$this->validarRh($boletasRh, $mapeos)];

        return $this->ensamblarResultado($empresa, $ciclo, $boletasPlanilla, $boletasRh, $hallazgos);
    }

    // ===================== GENERAL =====================

    private function validarEmpresaYCiclo(Empresa $empresa, CicloRemunerativo $ciclo): array
    {
        $hallazgos = [];

        $motivoRuc = RequisitoRucPlame::motivoInvalidez($empresa);
        if ($motivoRuc) {
            $hallazgos[] = $this->hallazgo('PLAME_EMPRESA_RUC_INVALIDO', 'error', self::TODOS_LOS_ARCHIVOS, self::GRUPO_GENERAL, $motivoRuc, 'Completar/corregir el RUC de la empresa en Configuraciones → Empresas.');
        }

        // Distinción Sección 42/43: el validador es informativo y puede
        // correr sobre cualquier estado — pero un ciclo que todavía no está
        // "pagado" (el estado final e inmutable, ver
        // CicloRemunerativoService::reabrir()) es una previsualización, no
        // la fuente oficial para un PLAME definitivo. Se documenta como
        // observación, nunca bloqueante: no inventa una regla nueva de
        // Nóminas, solo advierte.
        if ($ciclo->estado !== 'pagado') {
            $hallazgos[] = $this->hallazgo(
                'PLAME_CICLO_NO_PAGADO', 'warning', self::TODOS_LOS_ARCHIVOS, self::GRUPO_GENERAL,
                "El ciclo está en estado \"{$ciclo->estado}\", no \"pagado\" — esta validación es preliminar. La exportación definitiva de PLAME debería esperar a que el ciclo quede pagado (estado final e inmutable).",
                'Continuar preparando datos; recién exportar en definitiva cuando el ciclo esté pagado.',
            );
        }

        return $hallazgos;
    }

    // ===================== PLANILLA (identidad/contractual/previsional) =====================

    private function validarPlanilla(Collection $boletas, CicloRemunerativo $ciclo, Collection $mapeos, Collection $condiciones): array
    {
        $hallazgos = [];
        $fechaResolucion = $ciclo->fecha_fin->toDateString();

        foreach ($boletas as $boleta) {
            /** @var Colaborador $colaborador */
            $colaborador = $boleta->colaborador;
            if (! $colaborador) {
                continue;
            }

            $condicion = $this->condicionVigenteEn($condiciones->get($colaborador->id) ?? collect(), $fechaResolucion);

            // Identidad — Agento no tiene historial de apellidos/documento
            // (no cambian legítimamente en el tiempo como sí lo hace el
            // régimen), se lee la ficha actual del colaborador.
            if (blank($colaborador->apellido_paterno)) {
                $hallazgos[] = $this->hallazgoColaborador('PLAME_TRABAJADOR_APELLIDO_FALTANTE', 'error', ['jor', 'rem'], self::GRUPO_PLANILLA, $colaborador, 'Falta el apellido paterno — requerido por SUNAT (E4/E5/E18).', 'Completar apellido paterno en la ficha del colaborador.');
            }

            $mapeoDocumento = $mapeos->get('tipo_documento')?->get($colaborador->tipo_documento);
            if (! $this->mapeoConfigurado($mapeoDocumento)) {
                $hallazgos[] = $this->hallazgoColaborador('PLAME_TRABAJADOR_DOCUMENTO_SIN_MAPEO', 'error', ['jor', 'rem'], self::GRUPO_PLANILLA, $colaborador, "El tipo de documento \"{$colaborador->tipo_documento}\" no tiene código SUNAT configurado (Catálogos SUNAT → Tipos de Documento).", 'Configurar el mapeo SUNAT de este tipo de documento.');
            }

            // Empleado/Obrero — solo aplica si tipo_trabajador=trabajador
            // (tipo_trabajador no tiene historial: es inmutable desde la
            // creación, se lee tal cual).
            if ($colaborador->tipo_trabajador === 'trabajador') {
                $categoria = $condicion?->categoria_trabajador;
                if (blank($categoria)) {
                    $hallazgos[] = $this->hallazgoColaborador('PLAME_TRABAJADOR_CATEGORIA_FALTANTE', 'error', ['rem'], self::GRUPO_PLANILLA, $colaborador, 'No tiene categoría laboral (Empleado/Obrero) registrada — requerida por SUNAT (Tabla 8).', 'Completar la categoría laboral en la ficha del colaborador.');
                } else {
                    $mapeoCategoria = $mapeos->get('tipo_trabajador')?->get($categoria);
                    if (! $this->mapeoConfigurado($mapeoCategoria)) {
                        $hallazgos[] = $this->hallazgoColaborador('PLAME_TRABAJADOR_CATEGORIA_SIN_MAPEO', 'error', ['rem'], self::GRUPO_PLANILLA, $colaborador, "La categoría \"{$categoria}\" no tiene código SUNAT configurado.", 'Revisar Catálogos SUNAT → Tipos de Trabajador.');
                    }
                }
            }

            // Régimen — Locación de Servicios nunca llega acá (ya se separó
            // en validarRh), así que siempre corresponde validar Tabla 33.
            $regimen = $condicion?->regimen_laboral ?? $colaborador->regimen_laboral;
            if (blank($regimen)) {
                $hallazgos[] = $this->hallazgoColaborador('PLAME_TRABAJADOR_REGIMEN_FALTANTE', 'error', ['rem'], self::GRUPO_PLANILLA, $colaborador, 'No tiene régimen laboral registrado — requerido por SUNAT (Tabla 33).', 'Completar el régimen laboral en la ficha del colaborador.');
            } else {
                $mapeoRegimen = $mapeos->get('regimen_laboral')?->get($regimen);
                if (! $this->mapeoConfigurado($mapeoRegimen)) {
                    $hallazgos[] = $this->hallazgoColaborador('PLAME_TRABAJADOR_REGIMEN_SIN_MAPEO', 'error', ['rem'], self::GRUPO_PLANILLA, $colaborador, "El régimen \"{$regimen}\" no tiene código SUNAT configurado (Tabla 33).", 'Revisar Catálogos SUNAT → Regímenes.');
                }
            }

            // Previsional — ONP no exige AFP/CUSPP; AFP sí exige AFP+CUSPP,
            // nunca se exige lo mismo para ambos casos (Sección 15).
            $sistemaPrevisional = $condicion?->sistema_previsional ?? $colaborador->sistema_previsional;
            if ($sistemaPrevisional && $sistemaPrevisional !== 'onp') {
                $afpId = $condicion?->afp_id ?? $colaborador->afp_id;
                if (! $afpId || blank($colaborador->cuspp)) {
                    $hallazgos[] = $this->hallazgoColaborador('PLAME_TRABAJADOR_PREVISIONAL_INCOMPLETO', 'error', ['rem'], self::GRUPO_PLANILLA, $colaborador, 'Está afiliado a una AFP pero falta la AFP y/o el CUSPP.', 'Completar la configuración previsional del colaborador.');
                }
            }
        }

        return $hallazgos;
    }

    /**
     * Condición vigente en la fecha dada — mismo criterio que
     * ColaboradorCondicionLaboral::orderByDesc('vigencia_desde') pero
     * resuelto en memoria (la colección ya viene ordenada desc, se busca la
     * primera con vigencia_desde <= $fecha).
     */
    private function condicionVigenteEn(Collection $historial, string $fecha): ?ColaboradorCondicionLaboral
    {
        return $historial->first(fn (ColaboradorCondicionLaboral $c) => $c->vigencia_desde->toDateString() <= $fecha);
    }

    /**
     * Reutiliza el mismo cálculo de estado de Catálogos SUNAT
     * (SunatCatalogoService::calcularEstado) — nunca se reimplementa el
     * criterio de "configurado" acá.
     */
    private function mapeoConfigurado(?SunatMapeo $mapeo): bool
    {
        if (! $mapeo) {
            return false;
        }

        return SunatCatalogoService::calcularEstado(! $mapeo->activo, filled($mapeo->codigo_sunat), $mapeo->bloqueado_por_modelo) === 'configurado';
    }

    // ===================== .jor =====================

    private function validarJor(Collection $boletas): array
    {
        $hallazgos = [];

        foreach ($boletas as $boleta) {
            if (! $boleta->asistencia_procesada) {
                $hallazgos[] = $this->hallazgoColaborador(
                    'PLAME_JOR_ASISTENCIA_SIN_PROCESAR', 'error', ['jor'], self::GRUPO_JORNADA, $boleta->colaborador,
                    'La asistencia del período no fue procesada — no se pueden determinar horas ordinarias/extra reales.',
                    'Procesar la asistencia del período en Gestión de Asistencias.',
                );
            }
            // Horas ordinarias/extra: el dato real lo consolida
            // AsistenciaOperacionService::horasConsolidadasPorColaborador()
            // (reutilizable por el futuro generador .jor, Sección 18) — acá
            // basta con `asistencia_procesada`, que ya garantiza que esa
            // consolidación es obtenible; llamarla aquí además sería una
            // consulta redundante por colaborador (Sección 50).
        }

        return $hallazgos;
    }

    // ===================== .snl =====================

    private function validarSnl(Collection $boletasPlanilla, CicloRemunerativo $ciclo, Collection $mapeos): array
    {
        $colaboradorIds = $boletasPlanilla->pluck('colaborador_id');
        if ($colaboradorIds->isEmpty()) {
            return [];
        }

        $permisos = AsistenciaPermiso::whereIn('colaborador_id', $colaboradorIds)
            ->where('estado', 'aprobado')
            ->whereDate('fecha_inicio', '<=', $ciclo->fecha_fin)
            ->whereDate('fecha_fin', '>=', $ciclo->fecha_inicio)
            ->with('tipoAusencia', 'colaborador')
            ->get();

        $hallazgos = [];

        // tipo_documento del colaborador — SOLO se valida para quienes
        // REALMENTE tienen un permiso en el período (Sección 20/41: un
        // mapping sin uso nunca bloquea). .jor/.rem ya lo validan en
        // validarPlanilla() sobre TODA la planilla; acá se repite acotado a
        // .snl porque no todo colaborador de la planilla aparece en .snl.
        $colaboradoresConPermiso = $permisos->pluck('colaborador')->unique('id');
        foreach ($colaboradoresConPermiso as $colaborador) {
            $mapeoDocumento = $mapeos->get('tipo_documento')?->get($colaborador->tipo_documento);
            if (! $this->mapeoConfigurado($mapeoDocumento)) {
                $hallazgos[] = $this->hallazgoColaborador(
                    'PLAME_TRABAJADOR_DOCUMENTO_SIN_MAPEO', 'error', ['snl'], self::GRUPO_SUSPENSIONES, $colaborador,
                    "El tipo de documento \"{$colaborador->tipo_documento}\" no tiene código SUNAT configurado (Catálogos SUNAT → Tipos de Documento).",
                    'Configurar el mapeo SUNAT de este tipo de documento.',
                );
            }
        }

        // Ninguna ausencia real en el período — .snl no aplica, NUNCA un
        // error por mappings del catálogo que no se usaron (Sección 20/41).
        foreach ($permisos as $permiso) {
            foreach (ResolverSuspensionSunat::resolver($permiso) as $tramo) {
                if ($tramo['codigo_sunat'] === null) {
                    $codigo = $permiso->tipo === 'otro' ? 'PLAME_SNL_OTRO_SIN_CLASIFICAR' : 'PLAME_SNL_SUSPENSION_SIN_CLASIFICAR';
                    $mensaje = $permiso->tipo === 'otro'
                        ? 'Existe una ausencia clasificada como "otro" sin equivalencia SUNAT definida.'
                        : ($tramo['motivo'] ?? 'No se pudo determinar el código SUNAT de esta suspensión.');

                    $hallazgos[] = $this->hallazgoColaborador(
                        $codigo, 'error', ['snl'], self::GRUPO_SUSPENSIONES, $permiso->colaborador, $mensaje,
                        'Reclasificar la causa real de esta ausencia o completar el dato funcional que falta (ver Catálogos SUNAT → Suspensiones).',
                        entidad: 'asistencia_permiso', entidadId: $permiso->id,
                    );
                }
                // codigo_sunat resuelto (aunque el permiso produzca varios
                // tramos, ej. médico que cruza el día 20 — Sección 22): no
                // es un error, cada tramo se cuenta como un registro .snl
                // listo más adelante en ensamblarResultado().
            }
        }

        return $hallazgos;
    }

    // ===================== .rem =====================

    private function validarRem(Collection $boletas): array
    {
        $hallazgos = [];

        foreach ($boletas as $boleta) {
            foreach ($boleta->conceptos as $lineaConcepto) {
                $codigoInterno = $lineaConcepto->concepto?->codigo;

                if (in_array($codigoInterno, ConceptosPlame::NO_EXPORTABLES_REM, true)) {
                    // Provisiones/honorarios — nunca se declaran en .rem así
                    // tengan snapshot por error de datos viejos (Sección 33).
                    continue;
                }

                if ($lineaConcepto->monto_devengado === null || $lineaConcepto->monto_pagado_descontado === null) {
                    $hallazgos[] = $this->hallazgoColaborador('PLAME_REM_MONTO_INCOMPLETO', 'error', ['rem'], self::GRUPO_REMUNERACIONES, $boleta->colaborador, "El concepto \"{$codigoInterno}\" no tiene monto devengado/pagado-descontado registrado.", 'Recalcular la boleta.', entidad: 'boleta_concepto', entidadId: $lineaConcepto->id);

                    continue;
                }

                // Prioridad absoluta al snapshot histórico (Sección 26) —
                // nunca se consulta conceptos_remuneracion.codigo_plame acá.
                $codigo = $lineaConcepto->codigo_plame_snapshot;

                if (blank($codigo)) {
                    if (in_array($codigoInterno, ConceptosPlame::CON_DEFINICION, true)) {
                        $hallazgos[] = $this->hallazgoColaborador(
                            'PLAME_REM_CONCEPTO_SIN_MAPEO', 'error', ['rem'], self::GRUPO_REMUNERACIONES, $boleta->colaborador,
                            "Se usó \"{$codigoInterno}\" en este período pero no tiene una definición PLAME concreta asociada.",
                            'Configurar definición PLAME', entidad: 'boleta_concepto', entidadId: $lineaConcepto->id,
                        );
                    } else {
                        $hallazgos[] = $this->hallazgoColaborador(
                            'PLAME_REM_CONCEPTO_SIN_CODIGO', 'error', ['rem'], self::GRUPO_REMUNERACIONES, $boleta->colaborador,
                            "El concepto \"{$codigoInterno}\" no tiene código PLAME configurado.",
                            'Configurar el código PLAME de este concepto en Catálogos SUNAT → Conceptos PLAME.', entidad: 'boleta_concepto', entidadId: $lineaConcepto->id,
                        );
                    }

                    continue;
                }

                if (! preg_match('/^\d{4}$/', $codigo)) {
                    // Formato canónico Tabla 22 (Sección 27) — nunca se
                    // acepta silenciosamente un código de 3 dígitos.
                    $hallazgos[] = $this->hallazgoColaborador(
                        'PLAME_REM_CODIGO_FORMATO_INVALIDO', 'error', ['rem'], self::GRUPO_REMUNERACIONES, $boleta->colaborador,
                        "El código PLAME \"{$codigo}\" del concepto \"{$codigoInterno}\" no tiene el formato canónico de 4 dígitos.",
                        'Corregir el formato del código PLAME (debe ser de 4 dígitos, ej. 0121).', entidad: 'boleta_concepto', entidadId: $lineaConcepto->id,
                    );
                }
            }
        }

        return $hallazgos;
    }

    // ===================== RH (.ps4 / .4ta) =====================

    private function validarRh(Collection $boletasRh, Collection $mapeos): array
    {
        $hallazgos = [];

        foreach ($boletasRh as $boleta) {
            $colaborador = $boleta->colaborador;
            if (! $colaborador) {
                continue;
            }

            if (blank($colaborador->apellido_paterno)) {
                $hallazgos[] = $this->hallazgoColaborador('PLAME_TRABAJADOR_APELLIDO_FALTANTE', 'error', ['ps4', 'cuarta'], self::GRUPO_RH, $colaborador, 'Falta el apellido paterno del locador — requerido por SUNAT (E7/E20).', 'Completar apellido paterno en la ficha del colaborador.');
            }

            // RUC solo es válido para locadores por diseño (Sección 36) —
            // acá SIEMPRE es un locador (boletasRh ya filtró por régimen), así
            // que solo se valida que el tipo usado tenga mapeo, sin repetir
            // la regla de "no permitir RUC a dependientes" (eso ya lo aplica
            // StoreColaboradorRequest al guardar el dato).
            $mapeoDocumento = $mapeos->get('tipo_documento')?->get($colaborador->tipo_documento);
            if (! $this->mapeoConfigurado($mapeoDocumento)) {
                $hallazgos[] = $this->hallazgoColaborador('PLAME_RH_DOCUMENTO_SIN_MAPEO', 'error', ['ps4', 'cuarta'], self::GRUPO_RH, $colaborador, "El tipo de documento \"{$colaborador->tipo_documento}\" no tiene código SUNAT configurado.", 'Configurar el mapeo en Catálogos SUNAT → Tipos de Documento.');
            }

            $comprobante = $boleta->comprobanteRh;
            if (! $comprobante) {
                $hallazgos[] = $this->hallazgoColaborador('PLAME_RH_COMPROBANTE_FALTANTE', 'error', ['cuarta'], self::GRUPO_RH, $colaborador, 'No se registró el comprobante de honorarios de este período.', 'Registrar el comprobante RH en la boleta correspondiente.', entidad: 'boleta', entidadId: $boleta->id);

                continue;
            }

            $camposFaltantes = collect([
                'tipo_comprobante' => $comprobante->tipo_comprobante,
                'serie' => $comprobante->serie,
                'numero' => $comprobante->numero,
                'fecha_emision' => $comprobante->fecha_emision,
                'fecha_pago' => $comprobante->fecha_pago,
            ])->filter(fn ($v) => blank($v))->keys();

            if ($camposFaltantes->isNotEmpty()) {
                $hallazgos[] = $this->hallazgoColaborador('PLAME_RH_COMPROBANTE_INCOMPLETO', 'error', ['cuarta'], self::GRUPO_RH, $colaborador, 'Faltan datos del comprobante: '.$camposFaltantes->implode(', ').'.', 'Completar el comprobante de honorarios.', entidad: 'boleta_comprobante_rh', entidadId: $comprobante->id);
            }

            if (filled($comprobante->tipo_comprobante)) {
                $mapeoComprobante = $mapeos->get('tipo_comprobante_rh')?->first(fn (SunatMapeo $m) => $m->codigo_sunat === $comprobante->tipo_comprobante);
                if (! $mapeoComprobante) {
                    $hallazgos[] = $this->hallazgoColaborador('PLAME_RH_COMPROBANTE_TIPO_SIN_MAPEO', 'error', ['cuarta'], self::GRUPO_RH, $colaborador, "El tipo de comprobante \"{$comprobante->tipo_comprobante}\" no corresponde a ningún mapeo configurado.", 'Revisar Catálogos SUNAT → Comprobantes RH.', entidad: 'boleta_comprobante_rh', entidadId: $comprobante->id);
                }
            }

            if (in_array($comprobante->indicador_retencion_regimen_pensionario, ['1', '2'], true) && $comprobante->importe_aporte_regimen_pensionario === null) {
                $hallazgos[] = $this->hallazgoColaborador('PLAME_RH_COMPROBANTE_INCOMPLETO', 'error', ['cuarta'], self::GRUPO_RH, $colaborador, 'Indica retención de régimen pensionario pero falta el importe del aporte.', 'Completar el importe del aporte previsional.', entidad: 'boleta_comprobante_rh', entidadId: $comprobante->id);
            }

            // Monto total del servicio — se reutiliza el mismo criterio que
            // BoletaService::montoTotalServicioRh() (Sección 37): suma del
            // concepto HONORARIO_BRUTO ya calculado, nunca se recalcula.
            $montoServicio = (float) $boleta->conceptos
                ->filter(fn ($c) => $c->concepto?->codigo === 'HONORARIO_BRUTO')
                ->sum('monto');
            if ($montoServicio <= 0) {
                $hallazgos[] = $this->hallazgoColaborador('PLAME_RH_MONTO_SERVICIO_INDETERMINADO', 'error', ['cuarta'], self::GRUPO_RH, $colaborador, 'No se pudo determinar el monto total del servicio (concepto HONORARIO_BRUTO ausente o en cero).', 'Revisar el cálculo de la boleta de honorarios.', entidad: 'boleta', entidadId: $boleta->id);
            }
        }

        return $hallazgos;
    }

    // ===================== Ensamblado =====================

    private function ensamblarResultado(Empresa $empresa, CicloRemunerativo $ciclo, Collection $boletasPlanilla, Collection $boletasRh, array $hallazgos): array
    {
        $snlRegistros = $this->contarRegistrosSnl($boletasPlanilla, $ciclo);

        $archivos = [
            'jor' => $this->resumenArchivo($hallazgos, 'jor', $boletasPlanilla->count()),
            'snl' => $this->resumenArchivo($hallazgos, 'snl', $snlRegistros),
            'rem' => $this->resumenArchivo($hallazgos, 'rem', $boletasPlanilla->count()),
            'ps4' => $this->resumenArchivo($hallazgos, 'ps4', $boletasRh->count()),
            'cuarta' => $this->resumenArchivo($hallazgos, 'cuarta', $boletasRh->count()),
        ];

        $bloqueantes = collect($hallazgos)->where('severity', 'error')->count();
        $observaciones = collect($hallazgos)->where('severity', 'warning')->count();

        return [
            'empresa_id' => $empresa->id,
            'ciclo_id' => $ciclo->id,
            'periodo' => [
                'fecha_inicio' => $ciclo->fecha_inicio->toDateString(),
                'fecha_fin' => $ciclo->fecha_fin->toDateString(),
                'ciclo_estado' => $ciclo->estado,
            ],
            'listo' => $bloqueantes === 0,
            'resumen' => [
                'trabajadores' => $boletasPlanilla->count(),
                'rh' => $boletasRh->count(),
                'bloqueantes' => $bloqueantes,
                'observaciones' => $observaciones,
            ],
            'archivos' => $archivos,
            'hallazgos' => $hallazgos,
        ];
    }

    /**
     * .snl solo cuenta permisos/tramos reales del período — nunca el
     * catálogo completo de tipos_ausencia (Sección 20).
     */
    private function contarRegistrosSnl(Collection $boletasPlanilla, CicloRemunerativo $ciclo): int
    {
        $colaboradorIds = $boletasPlanilla->pluck('colaborador_id');
        if ($colaboradorIds->isEmpty()) {
            return 0;
        }

        return AsistenciaPermiso::whereIn('colaborador_id', $colaboradorIds)
            ->where('estado', 'aprobado')
            ->whereDate('fecha_inicio', '<=', $ciclo->fecha_fin)
            ->whereDate('fecha_fin', '>=', $ciclo->fecha_inicio)
            ->count();
    }

    /**
     * @return array{estado: string, registros: int, bloqueantes: int, observaciones: int}
     */
    private function resumenArchivo(array $hallazgos, string $archivo, int $registros): array
    {
        $delArchivo = collect($hallazgos)->filter(fn ($h) => in_array($archivo, $h['files'], true));
        $bloqueantes = $delArchivo->where('severity', 'error')->count();
        $observaciones = $delArchivo->where('severity', 'warning')->count();

        $estado = match (true) {
            $registros === 0 => 'no_aplica',
            $bloqueantes > 0 => 'bloqueado',
            $observaciones > 0 => 'observado',
            default => 'listo',
        };

        return ['estado' => $estado, 'registros' => $registros, 'bloqueantes' => $bloqueantes, 'observaciones' => $observaciones];
    }

    /**
     * @param  array<int, string>  $archivos
     */
    private function hallazgo(string $code, string $severity, array $archivos, string $group, string $message, string $action): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'files' => $archivos,
            'group' => $group,
            'entidad' => 'empresa',
            'entidad_id' => null,
            'colaborador_id' => null,
            'message' => $message,
            'action' => $action,
        ];
    }

    /**
     * @param  array<int, string>  $archivos
     */
    private function hallazgoColaborador(string $code, string $severity, array $archivos, string $group, ?Colaborador $colaborador, string $message, string $action, string $entidad = 'colaborador', ?int $entidadId = null): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'files' => $archivos,
            'group' => $group,
            'entidad' => $entidad,
            'entidad_id' => $entidadId ?? $colaborador?->id,
            'colaborador_id' => $colaborador?->id,
            'colaborador_nombre' => $colaborador ? trim("{$colaborador->nombres} {$colaborador->apellidos}") : null,
            'message' => $message,
            'action' => $action,
        ];
    }
}
