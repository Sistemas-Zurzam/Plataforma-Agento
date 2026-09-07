<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Application\CalcularBoletaColaborador;
use App\Modules\Nominas\Application\CalcularReciboHonorarios;
use App\Modules\Nominas\Infrastructure\BbvaNetCash\Export\BbvaNetCashTxtExporter;
use App\Modules\Nominas\Infrastructure\TelecreditoBcp\Export\TelecreditoBcpTxtExporter;
use App\Modules\Nominas\Domain\RegimenCalculatorFactory;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaDatosPago;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\PlanillaComplementaria;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;
use App\Modules\Nominas\Support\ParametrosVigentesResolver;
use App\Modules\Personas\Models\ColaboradorCondicionLaboral;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use App\Modules\Personas\Support\FeriadosPeru;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlanillaComplementariaService
{
    // Las faltas de planilla dependiente reducen SUELDO_BASICO, no son un egreso.
    private const INDICE_FALTA_BASICO = -1;
    public function __construct(
        private readonly CalcularBoletaColaborador $calculador,
        private readonly CalcularReciboHonorarios $calculadorHonorarios,
    ) {}

    public function listar(Empresa $empresa, CicloRemunerativo $ciclo): Collection
    {
        $this->verificar($empresa, $ciclo);

        return PlanillaComplementaria::where('ciclo_id', $ciclo->id)
            ->with(['detalles.colaborador:id,nombres,apellidos,numero_documento', 'detalles.boletaOriginal.datosPago.banco'])
            ->latest()->get();
    }

    /** @param array<int, int> $boletaIds */
    public function crear(Empresa $empresa, CicloRemunerativo $ciclo, array $boletaIds, string $motivo, int $usuarioId): PlanillaComplementaria
    {
        $this->verificar($empresa, $ciclo);
        if ($ciclo->estado !== 'pagado') {
            throw ValidationException::withMessages(['estado' => 'La planilla complementaria solo se genera sobre un ciclo pagado.']);
        }

        $originales = Boleta::where('ciclo_id', $ciclo->id)
            ->whereIn('id', $boletaIds)
            ->where('estado', 'pagada')
            ->where('es_version_vigente', true)
            ->with('colaborador')
            ->get();

        if ($originales->count() !== count(array_unique($boletaIds))) {
            throw ValidationException::withMessages(['colaboradores' => 'Todos los colaboradores deben tener una boleta vigente pagada en el ciclo.']);
        }

        $pendienteExistente = PlanillaComplementariaDetalle::whereIn('colaborador_id', $originales->pluck('colaborador_id'))
            ->whereHas('complementaria', fn ($q) => $q->where('ciclo_id', $ciclo->id)->whereIn('estado', ['calculada', 'aprobada']))
            ->exists();
        if ($pendienteExistente) {
            throw ValidationException::withMessages([
                'colaboradores' => 'Uno de los colaboradores ya tiene una complementaria pendiente. Apruébala y págala antes de generar otra para evitar duplicar el abono.',
            ]);
        }

        return DB::transaction(function () use ($ciclo, $originales, $motivo, $usuarioId) {
            $complementaria = PlanillaComplementaria::create([
                'ciclo_id' => $ciclo->id,
                'empresa_id' => $ciclo->empresa_id,
                'nombre' => 'Complementaria '.$ciclo->nombre.' '.now()->format('Ymd-His'),
                'motivo' => $motivo,
                'estado' => 'calculada',
                'creado_por' => $usuarioId,
            ]);

            foreach ($originales as $original) {
                $colaborador = $original->colaborador;
                $esHonorarios = $colaborador->tipo_contrato === 'locacion_servicios'
                    || $colaborador->regimen_laboral === 'Locacion de Servicios';
                $motor = $esHonorarios ? $this->calculadorHonorarios : $this->calculador;
                $nuevo = $motor->calcular(
                    $colaborador,
                    $ciclo->fecha_inicio->toDateString(),
                    $ciclo->fecha_fin->toDateString(),
                    $ciclo->fecha_corte_asistencia->toDateString(),
                    $ciclo->id,
                    $ciclo->fecha_pago->toDateString(),
                );

                // Si ya hubo una complementaria pagada, la nueva diferencia
                // parte del último cálculo efectivamente cubierto, no vuelve
                // a compararse contra la boleta original (antiduplicidad).
                $ultimaPagada = PlanillaComplementariaDetalle::where('boleta_original_id', $original->id)
                    ->whereHas('complementaria', fn ($q) => $q->where('estado', 'pagada'))
                    ->latest('id')->first();
                $base = $ultimaPagada?->calculo_snapshot ?? [
                    'neto_a_pagar' => $original->neto_a_pagar,
                    'total_ingresos' => $original->total_ingresos,
                    'total_egresos' => $original->total_egresos,
                    'total_aportaciones' => $original->total_aportaciones,
                ];

                PlanillaComplementariaDetalle::create([
                    'planilla_complementaria_id' => $complementaria->id,
                    'boleta_original_id' => $original->id,
                    'colaborador_id' => $colaborador->id,
                    'banco_id' => $colaborador->banco_id,
                    'tipo_cuenta_snapshot' => $colaborador->tipo_cuenta,
                    'moneda_snapshot' => $colaborador->moneda_cuenta,
                    'numero_cuenta_snapshot' => $colaborador->numero_cuenta,
                    'cci_snapshot' => $colaborador->cci,
                    'neto_original' => $base['neto_a_pagar'],
                    'neto_recalculado' => $nuevo['neto_a_pagar'],
                    'diferencia_ingresos' => bcsub((string) $nuevo['total_ingresos'], (string) $base['total_ingresos'], 2),
                    'diferencia_egresos' => bcsub((string) $nuevo['total_egresos'], (string) $base['total_egresos'], 2),
                    'diferencia_aportaciones' => bcsub((string) $nuevo['total_aportaciones'], (string) $base['total_aportaciones'], 2),
                    'diferencia_neta' => bcsub((string) $nuevo['neto_a_pagar'], (string) $base['neto_a_pagar'], 2),
                    'calculo_snapshot' => $nuevo,
                ]);
            }

            if (! $complementaria->detalles()->where('diferencia_neta', '!=', 0)->exists()) {
                throw ValidationException::withMessages(['diferencias' => 'El recálculo no produjo ninguna diferencia respecto de lo ya pagado.']);
            }

            return $this->cargar($complementaria);
        });
    }

    /**
     * Feriados nacionales seleccionables para una regularización histórica:
     * Incluye los feriados del propio ciclo y de los tres años anteriores,
     * para que el DatePicker del frontend no ofrezca
     * fechas que igual serían rechazadas al confirmar. Limitado a los 3 años
     * previos al ciclo — rango suficiente para una regularización histórica
     * real sin calcular feriados indefinidamente hacia atrás.
     *
     * @return array<string>
     */
    public function feriadosDisponibles(Empresa $empresa, CicloRemunerativo $ciclo): array
    {
        $this->verificar($empresa, $ciclo);

        $anioCiclo = (int) $ciclo->fecha_inicio->format('Y');
        $fechaFin = $ciclo->fecha_fin->toDateString();

        return collect(range($anioCiclo - 3, $anioCiclo))
            ->flatMap(fn (int $anio) => FeriadosPeru::paraAnio($anio))
            ->filter(fn (string $fecha) => $fecha <= $fechaFin)
            ->sort()
            ->values()
            ->all();
    }

    /** @param array<int, int> $boletaIds */
    public function crearRegularizacionFeriadoHistorico(Empresa $empresa, CicloRemunerativo $ciclo, array $boletaIds, string $fechaFeriado, string $motivo, int $usuarioId): PlanillaComplementaria
    {
        return DB::transaction(function () use ($empresa, $ciclo, $boletaIds, $fechaFeriado, $motivo, $usuarioId) {
            $this->verificar($empresa, $ciclo);
            $ciclo = CicloRemunerativo::whereKey($ciclo->id)->lockForUpdate()->firstOrFail();
            $colaboradorIds = $ciclo->boletas()->whereIn('id', $boletaIds)->pluck('colaborador_id');
            \App\Modules\Personas\Models\Colaborador::withoutGlobalScopes()->whereIn('id', $colaboradorIds)->orderBy('id')->lockForUpdate()->get();
            return $this->crearFeriadoBloqueado($empresa, $ciclo, $boletaIds, $fechaFeriado, $motivo, $usuarioId);
        });
    }

    private function crearFeriadoBloqueado(Empresa $empresa, CicloRemunerativo $ciclo, array $boletaIds, string $fechaFeriado, string $motivo, int $usuarioId): PlanillaComplementaria
    {
        $this->verificar($empresa, $ciclo);
        if ($ciclo->estado !== 'pagado') {
            throw ValidationException::withMessages(['estado' => 'La regularización histórica solo puede abonarse mediante un ciclo pagado.']);
        }
        if (! FeriadosPeru::esFeriado($fechaFeriado)) {
            throw ValidationException::withMessages(['fecha_feriado' => 'La fecha seleccionada no es un feriado nacional registrado.']);
        }
        if (! in_array($fechaFeriado, $this->feriadosDisponibles($empresa, $ciclo), true)) {
            throw ValidationException::withMessages(['fecha_feriado' => 'Selecciona un feriado del ciclo o de los tres años anteriores, hasta el cierre de este ciclo.']);
        }

        $originales = Boleta::where('ciclo_id', $ciclo->id)
            ->whereIn('id', $boletaIds)->where('estado', 'pagada')->where('es_version_vigente', true)
            ->with(['colaborador.empresa', 'conceptos.concepto'])->get();
        if ($originales->count() !== count(array_unique($boletaIds))) {
            throw ValidationException::withMessages(['colaboradores' => 'Todos los colaboradores deben tener una boleta vigente pagada en el ciclo seleccionado.']);
        }

        $colaboradorIds = $originales->pluck('colaborador_id');
        $pendienteExistente = PlanillaComplementariaDetalle::whereIn('colaborador_id', $colaboradorIds)
            ->whereHas('complementaria', fn ($q) => $q->where('ciclo_id', $ciclo->id)->whereIn('estado', ['calculada', 'aprobada']))
            ->exists();
        if ($pendienteExistente) {
            throw ValidationException::withMessages([
                'colaboradores' => 'Uno de los colaboradores ya tiene una complementaria pendiente. Apruébala y págala antes de generar otra.',
            ]);
        }

        $nombreRegularizacion = 'Regularización feriado '.$fechaFeriado;
        $feriadoYaRegularizado = PlanillaComplementariaDetalle::whereIn('colaborador_id', $colaboradorIds)
            ->whereHas('complementaria', fn ($q) => $q
                ->where('empresa_id', $ciclo->empresa_id)
                ->where('nombre', $nombreRegularizacion)
                ->whereIn('estado', ['calculada', 'aprobada', 'pagada']))
            ->exists();
        if ($feriadoYaRegularizado) {
            throw ValidationException::withMessages([
                'fecha_feriado' => 'El feriado seleccionado ya fue incluido para uno de los colaboradores. Retíralo de la selección para evitar un pago duplicado.',
            ]);
        }

        // Dos conceptos según el régimen de CADA boleta: HE_100 (Tabla 22,
        // sobretasa del 100% del Art. 8° D.Leg. 713) solo existe para
        // trabajadores dependientes — un locador no tiene relación laboral,
        // así que un adicional por feriado trabajado es una liberalidad
        // contractual (HONORARIO_FERIADO_TRABAJADO, sin Tabla 22, sueldo/30×1
        // en vez de ×2 — ver ConceptoRemuneracionSeeder). Se resuelven de
        // forma perezosa dentro del loop (no acá arriba): así, si todavía no
        // se corrió el seeder en un ambiente, solo falla la rama que
        // realmente lo necesita — nunca la regularización completa de un
        // lote que no incluye ningún locador.
        $conceptoDependiente = null;
        $conceptoHonorarios = null;

        return DB::transaction(function () use ($ciclo, $originales, $fechaFeriado, $motivo, $usuarioId, &$conceptoDependiente, &$conceptoHonorarios, $nombreRegularizacion) {
            $item = PlanillaComplementaria::create([
                'ciclo_id' => $ciclo->id,
                'empresa_id' => $ciclo->empresa_id,
                'nombre' => $nombreRegularizacion,
                'motivo' => "Feriado trabajado {$fechaFeriado} sin descanso sustitutorio ni pago previo — {$motivo}",
                'estado' => 'calculada',
                'creado_por' => $usuarioId,
            ]);

            foreach ($originales as $original) {
                $colaborador = $original->colaborador;
                if ($colaborador->fecha_ingreso->toDateString() > $fechaFeriado || ($colaborador->fecha_cese && $colaborador->fecha_cese->toDateString() < $fechaFeriado)) {
                    throw ValidationException::withMessages(['fecha_feriado' => "El feriado está fuera de las fechas de servicio de {$colaborador->nombres} {$colaborador->apellidos}."]);
                }

                $base = $this->baseParaReintegro($original);
                if ($fechaFeriado >= $ciclo->fecha_inicio->toDateString()
                    && $original->conceptos->contains(fn ($l) => $l->concepto?->codigo === 'HE_100' && (float) $l->monto > 0)) {
                    throw ValidationException::withMessages(['fecha_feriado' => "La boleta de {$colaborador->nombres} ya incluye horas al 100%. Revisa las fechas pagadas antes de regularizar este feriado."]);
                }
                // El feriado puede ser anterior al ciclo usado para pagar el
                // reintegro. Por ello la naturaleza del vínculo debe salir
                // de la condición laboral vigente EN ESE DÍA, no del
                // snapshot del ciclo receptor. El snapshot queda como
                // respaldo para instalaciones cuyo historial aún no tenga
                // una fila aplicable a la fecha histórica.
                $condicionHistorica = ColaboradorCondicionLaboral::vigenteEn($colaborador->id, $fechaFeriado);
                $esHonorarios = $condicionHistorica
                    ? ($condicionHistorica->tipo_contrato === 'locacion_servicios'
                        || $condicionHistorica->regimen_laboral === 'Locacion de Servicios')
                    : $original->regimen_laboral_snapshot === 'Locacion de Servicios';
                unset($base['descansos_semanales'], $base['reintegros_descuentos'], $base['feriado_regularizado']);
                if (! $esHonorarios && ! collect($base['egresos'] ?? [])->contains('codigo', 'RENTA_5TA')) {
                    $base['egresos'][] = ['codigo' => 'RENTA_5TA', 'monto' => 0, 'base_utilizada' => $base['total_ingresos']];
                }

                $remuneracion = ColaboradorRemuneracion::where('colaborador_id', $colaborador->id)
                    ->whereDate('vigencia_desde', '<=', $fechaFeriado)
                    ->orderByDesc('vigencia_desde')->orderByDesc('id')->first();
                if (! $remuneracion) {
                    throw ValidationException::withMessages(['remuneracion' => "No existe remuneración histórica para {$colaborador->nombres} {$colaborador->apellidos} al {$fechaFeriado}."]);
                }
                if ($remuneracion->periodicidad_pago !== 'mensual' || (float) $remuneracion->salario <= 0) {
                    throw ValidationException::withMessages([
                        'remuneracion' => "La remuneración histórica de {$colaborador->nombres} {$colaborador->apellidos} debe ser mensual y mayor a cero.",
                    ]);
                }

                $detalle = PlanillaComplementariaDetalle::create([
                    'planilla_complementaria_id' => $item->id,
                    'boleta_original_id' => $original->id,
                    'colaborador_id' => $colaborador->id,
                    'banco_id' => $colaborador->banco_id,
                    'tipo_cuenta_snapshot' => $colaborador->tipo_cuenta,
                    'moneda_snapshot' => $colaborador->moneda_cuenta,
                    'numero_cuenta_snapshot' => $colaborador->numero_cuenta,
                    'cci_snapshot' => $colaborador->cci,
                    'neto_original' => $base['neto_a_pagar'],
                    'neto_recalculado' => $base['neto_a_pagar'],
                    'diferencia_ingresos' => 0,
                    'diferencia_egresos' => 0,
                    'diferencia_aportaciones' => 0,
                    'diferencia_neta' => 0,
                    'calculo_snapshot' => $base,
                ]);

                if ($esHonorarios) {
                    $conceptoHonorarios ??= ConceptoRemuneracion::where('codigo', 'HONORARIO_FERIADO_TRABAJADO')->where('activo', true)->first();
                    if (! $conceptoHonorarios) {
                        throw ValidationException::withMessages([
                            'concepto' => 'Falta instalar el concepto HONORARIO_FERIADO_TRABAJADO. Ejecuta las migraciones pendientes antes de calcular el reintegro.',
                        ]);
                    }
                    $monto = round(((float) $remuneracion->salario / 30), 2);
                    $this->agregarConcepto(
                        $ciclo->empresa, $detalle, $conceptoHonorarios->id, null, $monto,
                        "Cálculo automático: ({$remuneracion->salario} / 30) × 1 por feriado trabajado {$fechaFeriado} — adicional discrecional a un locador (sin relación laboral, no aplica la sobretasa de ley del Art. 8° D.Leg. 713)",
                        $usuarioId,
                        true,
                    );
                } else {
                    $conceptoDependiente ??= ConceptoRemuneracion::where('codigo', 'HE_100')->where('activo', true)->firstOrFail();
                    $monto = round(((float) $remuneracion->salario / 30) * 2, 2);
                    $this->agregarConcepto(
                        $ciclo->empresa, $detalle, $conceptoDependiente->id, null, $monto,
                        "Cálculo automático: ({$remuneracion->salario} / 30) × 2 por feriado trabajado {$fechaFeriado}, sin descanso sustitutorio",
                        $usuarioId,
                        true,
                    );
                }
                $snapshot = $detalle->fresh()->calculo_snapshot;
                $snapshot['feriado_regularizado'] = ['fecha' => $fechaFeriado, 'importe_bruto' => $monto,
                    'sueldo_historico' => $remuneracion->salario, 'confirmado_por' => $usuarioId,
                    'tipo_pago' => $esHonorarios ? 'honorarios' : 'planilla',
                    'multiplicador' => $esHonorarios ? 1 : 2,
                    'sin_descanso_sustitutorio' => true, 'sin_pago_previo' => true, 'confirmado_at' => now()->toDateTimeString()];
                $detalle->update(['calculo_snapshot' => $snapshot]);
            }

            return $this->cargar($item);
        });
    }

    public function descuentosReintegrables(Empresa $empresa, CicloRemunerativo $ciclo, array $boletaIds): array
    {
        $originales = $this->boletasParaReintegro($empresa, $ciclo, $boletaIds);
        $nombres = ConceptoRemuneracion::pluck('nombre', 'codigo');
        $pendientes = PlanillaComplementariaDetalle::whereIn('boleta_original_id', $originales->pluck('id'))
            ->whereHas('complementaria', fn ($q) => $q->whereIn('estado', ['calculada', 'aprobada']))
            ->with('complementaria')->get()->keyBy('boleta_original_id');
        return $originales->flatMap(function ($boleta) use ($nombres, $pendientes) {
            $snapshot = $this->baseParaReintegro($boleta);
            $version = hash('sha256', json_encode($snapshot));
            $pendiente = $pendientes->get($boleta->id);
            $reservados = collect($pendiente?->calculo_snapshot['reintegros_descuentos'] ?? []);
            return collect($this->lineasDescuentoParaReintegro($boleta, $snapshot))->reject(function ($linea, $indice) use ($reservados) {
                return $reservados->contains(fn ($r) => isset($r['indice'])
                    ? (int) $r['indice'] === $indice : $r['codigo'] === $linea['codigo']);
            })->map(function ($linea, $indice) use ($boleta, $nombres, $version, $pendiente) {
                $borradorEditable = ! $pendiente || $pendiente->complementaria?->estado === 'calculada';
                return [
                    'boleta_id' => $boleta->id, 'indice' => $indice, 'version' => $version,
                    'colaborador' => trim($boleta->colaborador->nombres.' '.$boleta->colaborador->apellidos),
                    'codigo' => $linea['codigo'], 'nombre' => $linea['nombre'] ?? $nombres[$linea['codigo']] ?? $linea['codigo'],
                    'monto' => $linea['monto'], 'formula' => $linea['formula_texto'] ?? null,
                    'aplicado_en_basico' => $linea['aplicado_en_basico'] ?? false,
                    'reintegrable' => $borradorEditable && $this->esDescuentoReintegrable($linea['codigo'], $boleta),
                    'observacion_reintegro' => $linea['codigo'] === 'RETENCION_RENTA_4TA'
                        ? ($this->esDescuentoReintegrable($linea['codigo'], $boleta)
                            ? 'Constancia de suspensión registrada. Disponible para subsanar la retención descontada.'
                            : 'Guarda la constancia de suspensión de cuarta categoría en la configuración de planilla y actualiza los descuentos.')
                        : null,
                    'complementaria_pendiente_id' => $pendiente?->planilla_complementaria_id,
                    'complementaria_pendiente_estado' => $pendiente?->complementaria?->estado,
                ];
            })->filter(fn ($linea) => (float) $linea['monto'] > 0)->values();
        })->values()->all();
    }

    private function esDescuentoReintegrable(?string $codigo, Boleta $boleta): bool
    {
        if ($codigo === 'DESCUENTO_FALTA_BASICO') {
            return $boleta->regimen_laboral_snapshot !== 'Locacion de Servicios';
        }
        if ($codigo === 'RETENCION_RENTA_4TA') {
            return $boleta->regimen_laboral_snapshot === 'Locacion de Servicios'
                && $boleta->colaborador->tiene_suspension_renta_4ta;
        }
        return in_array($codigo, ['DESCUENTO_FALTA', 'DESCUENTO_TARDANZA', 'DESCUENTO_HORAS_INCOMPLETAS',
            'DESCUENTO_ERROR_OPERATIVO', 'DESCUENTO_COMPRA_MERCADERIA', 'ADELANTO_SUELDO'], true);
    }

    private function lineasDescuentoParaReintegro(Boleta $boleta, array $snapshot): array
    {
        $lineas = $snapshot['egresos'] ?? [];
        if ($boleta->regimen_laboral_snapshot === 'Locacion de Servicios' || (float) $boleta->dias_falta <= 0) {
            return $lineas;
        }
        $codigosBasico = ['SUELDO_BASICO', 'REMUNERACION_VACACIONAL'];
        $original = $boleta->conceptos->filter(fn ($l) => in_array($l->concepto?->codigo, $codigosBasico, true))->sum('monto');
        $ingresos = collect($snapshot['ingresos'] ?? []);
        if (! $ingresos->contains('codigo', 'SUELDO_BASICO')) return $lineas;

        // No devuelve prorrateos por ingreso tardío ni vacaciones: el tope
        // es exclusivamente la falta guardada en la boleta pagada.
        $descuentoOriginal = min(
            max(0, (float) $boleta->sueldo_basico_snapshot - $original),
            round(((float) $boleta->sueldo_basico_snapshot / 30) * min(30, (float) $boleta->dias_falta), 2),
        );
        $basicoCubierto = $ingresos->whereIn('codigo', $codigosBasico)->sum('monto');
        $pendiente = round(max(0, min($descuentoOriginal, $original + $descuentoOriginal - $basicoCubierto)), 2);
        if ($pendiente > 0) {
            $lineas[self::INDICE_FALTA_BASICO] = [
                'codigo' => 'DESCUENTO_FALTA_BASICO', 'nombre' => 'Faltas descontadas de la remuneración básica',
                'monto' => $pendiente, 'aplicado_en_basico' => true,
                'formula_texto' => "Boleta pagada: {$boleta->dias_falta} falta(s), S/ ".number_format($descuentoOriginal, 2, '.', '').
                    ' descontados del básico. Se reintegra el bruto seleccionado y se recalculan los aportes correspondientes.',
            ];
        }
        return $lineas;
    }

    public function boletasParaReintegro(Empresa $empresa, CicloRemunerativo $ciclo, array $boletaIds): Collection
    {
        $this->verificar($empresa, $ciclo);
        if ($ciclo->estado !== 'pagado') {
            throw ValidationException::withMessages(['estado' => 'El reintegro requiere un ciclo pagado.']);
        }
        $boletas = $ciclo->boletas()->whereIn('id', $boletaIds)->where('estado', 'pagada')
            ->where('es_version_vigente', true)->with(['colaborador', 'conceptos.concepto'])->get();
        if ($boletas->isEmpty() || $boletas->count() !== count(array_unique($boletaIds))) {
            throw ValidationException::withMessages(['boleta_ids' => 'Selecciona boletas vigentes pagadas de este ciclo.']);
        }
        return $boletas;
    }

    public function baseParaReintegro(Boleta $boleta): array
    {
        return PlanillaComplementariaDetalle::where('boleta_original_id', $boleta->id)
            ->whereHas('complementaria', fn ($q) => $q->where('estado', 'pagada'))
            ->latest('id')->first()?->calculo_snapshot ?? $this->snapshotDeBoleta($boleta);
    }

    public function reintegrarDescuentos(Empresa $empresa, CicloRemunerativo $ciclo, array $seleccion, string $motivo, int $usuarioId): PlanillaComplementaria
    {
        return DB::transaction(function () use ($empresa, $ciclo, $seleccion, $motivo, $usuarioId) {
            // Serializa la creación para que dos solicitudes no reserven el mismo descuento.
            $ciclo = CicloRemunerativo::whereKey($ciclo->id)->lockForUpdate()->firstOrFail();
            $boletas = $this->boletasParaReintegro($empresa, $ciclo, collect($seleccion)->pluck('boleta_id')->unique()->all());
            $detallesPendientes = PlanillaComplementariaDetalle::whereIn('boleta_original_id', $boletas->pluck('id'))
                ->whereHas('complementaria', fn ($q) => $q->whereIn('estado', ['calculada', 'aprobada']))
                ->with('complementaria')->lockForUpdate()->get()->keyBy('boleta_original_id');
            if ($detallesPendientes->contains(fn ($detalle) => $detalle->complementaria?->estado !== 'calculada')) {
                throw ValidationException::withMessages(['descuentos' => 'Una complementaria aprobada ya no puede modificarse. Completa su pago antes de generar otro reintegro.']);
            }
            $borradores = $detallesPendientes->pluck('planilla_complementaria_id')->unique();
            if ($borradores->count() > 1) {
                throw ValidationException::withMessages(['descuentos' => 'La selección pertenece a más de un borrador. Agrega los descuentos a cada complementaria por separado.']);
            }
            $item = $borradores->isNotEmpty()
                ? PlanillaComplementaria::whereKey($borradores->first())->lockForUpdate()->firstOrFail()
                : PlanillaComplementaria::create([
                    'ciclo_id' => $ciclo->id, 'empresa_id' => $empresa->id,
                    'nombre' => 'Reintegro de descuentos '.$ciclo->nombre.' '.now()->format('Ymd-His'),
                    'motivo' => $motivo, 'estado' => 'calculada', 'creado_por' => $usuarioId,
                ]);
            foreach ($boletas as $boleta) {
                $base = $this->baseParaReintegro($boleta);
                $detalle = $detallesPendientes->get($boleta->id);
                $snapshot = $detalle?->calculo_snapshot ?? $base;
                if (! $detalle) {
                    unset($snapshot['descansos_semanales'], $snapshot['feriado_regularizado']);
                    $snapshot['reintegros_descuentos'] = [];
                    $colaborador = $boleta->colaborador;
                    $detalle = PlanillaComplementariaDetalle::create([
                        'planilla_complementaria_id' => $item->id, 'boleta_original_id' => $boleta->id,
                        'colaborador_id' => $boleta->colaborador_id, 'banco_id' => $colaborador->banco_id,
                        'tipo_cuenta_snapshot' => $colaborador->tipo_cuenta, 'moneda_snapshot' => $colaborador->moneda_cuenta,
                        'numero_cuenta_snapshot' => $colaborador->numero_cuenta, 'cci_snapshot' => $colaborador->cci,
                        'neto_original' => $base['neto_a_pagar'], 'neto_recalculado' => $base['neto_a_pagar'],
                        'diferencia_ingresos' => 0, 'diferencia_egresos' => 0,
                        'diferencia_aportaciones' => 0, 'diferencia_neta' => 0, 'calculo_snapshot' => $snapshot,
                    ]);
                }
                $total = '0.00';
                $reintegroBasico = '0.00';
                $lineasDisponibles = $this->lineasDescuentoParaReintegro($boleta, $base);
                $indices = [];
                $reservados = collect($snapshot['reintegros_descuentos'] ?? []);
                foreach (collect($seleccion)->where('boleta_id', $boleta->id) as $elegido) {
                    $indice = (int) $elegido['indice'];
                    $linea = $lineasDisponibles[$indice] ?? null;
                    $monto = (string) $elegido['monto'];
                    $yaReservado = $reservados->contains(fn ($r) => isset($r['indice'])
                        ? (int) $r['indice'] === $indice : ($r['codigo'] ?? null) === ($linea['codigo'] ?? null));
                    if ($yaReservado || isset($indices[$indice]) || ! $linea || ! $this->esDescuentoReintegrable($linea['codigo'], $boleta)
                        || $elegido['version'] !== hash('sha256', json_encode($base))
                        || bccomp($monto, '0', 2) <= 0 || bccomp($monto, (string) $linea['monto'], 2) > 0) {
                        throw ValidationException::withMessages(['descuentos' => 'El descuento cambió o el importe no es válido. Actualiza los descuentos y selecciona un monto pendiente de reintegro.']);
                    }
                    $indices[$indice] = true;
                    if ($indice === self::INDICE_FALTA_BASICO) {
                        $reintegroBasico = $monto;
                        foreach ($snapshot['ingresos'] as &$ingreso) {
                            if ($ingreso['codigo'] !== 'SUELDO_BASICO') continue;
                            $ingreso['monto'] = (float) bcadd((string) $ingreso['monto'], $monto, 2);
                            if (isset($ingreso['cantidad']) && (float) $boleta->sueldo_basico_snapshot > 0) {
                                $ingreso['cantidad'] = round($ingreso['cantidad'] + (float) $monto * 30 / (float) $boleta->sueldo_basico_snapshot, 2);
                            }
                            $ingreso['formula_texto'] = 'Remuneración básica corregida: reintegro de faltas por S/ '.$monto.' — '.$motivo;
                            break;
                        }
                        unset($ingreso);
                    } else {
                        $snapshot['egresos'][$indice]['monto'] = (float) bcsub((string) $linea['monto'], $monto, 2);
                        $total = bcadd($total, $monto, 2);
                    }
                    $snapshot['reintegros_descuentos'][] = [
                        'indice' => $indice, 'codigo' => $linea['codigo'], 'monto' => $monto, 'monto_anterior' => $linea['monto'],
                        'motivo' => $motivo, 'registrado_por' => $usuarioId, 'registrado_en' => now()->toDateTimeString(),
                        ...($linea['codigo'] === 'RETENCION_RENTA_4TA' ? ['suspension_renta_4ta_registrada' => true] : []),
                    ];
                }
                if (bccomp($reintegroBasico, '0', 2) > 0) {
                    if (! collect($snapshot['egresos'])->contains('codigo', 'RENTA_5TA')) {
                        $snapshot['egresos'][] = ['codigo' => 'RENTA_5TA', 'monto' => 0, 'base_utilizada' => $base['total_ingresos']];
                    }
                    $this->recalcularAfpEssalud($detalle, $snapshot, (float) $reintegroBasico);
                    $this->recalcularRentaQuinta($detalle, $snapshot, (float) $reintegroBasico);
                    $this->recalcularProvisiones($detalle, $snapshot, (float) $reintegroBasico);
                    $this->guardarSnapshotYDiferencias($detalle, $snapshot);
                } else {
                    // Algunos snapshots históricos conservan los totales
                    // pero no todas las líneas de ingreso. En un reintegro
                    // puramente de egresos no se reconstruyen esos totales
                    // sumando líneas: se acumula el delta sobre el snapshot
                    // ya auditado, igual que hacía la creación original.
                    $snapshot['total_egresos'] = (float) bcsub((string) $snapshot['total_egresos'], $total, 2);
                    $snapshot['neto_a_pagar'] = (float) bcadd((string) $snapshot['neto_a_pagar'], $total, 2);
                    $detalle->update([
                        'calculo_snapshot' => $snapshot,
                        'neto_recalculado' => $snapshot['neto_a_pagar'],
                        'diferencia_egresos' => bcsub((string) $detalle->diferencia_egresos, $total, 2),
                        'diferencia_neta' => bcadd((string) $detalle->diferencia_neta, $total, 2),
                    ]);
                }
            }
            return $this->cargar($item);
        });
    }

    private function snapshotDeBoleta(Boleta $boleta): array
    {
        $bloques = ['ingreso' => [], 'egreso' => [], 'aportacion' => []];
        foreach ($boleta->conceptos as $linea) {
            if (! array_key_exists($linea->tipo, $bloques)) {
                continue;
            }
            $bloques[$linea->tipo][] = [
                'codigo' => $linea->concepto?->codigo,
                'monto' => (float) $linea->monto,
                'base_utilizada' => $linea->base_utilizada !== null ? (float) $linea->base_utilizada : null,
                'tasa_aplicada' => $linea->tasa_aplicada !== null ? (float) $linea->tasa_aplicada : null,
                'cantidad' => $linea->cantidad !== null ? (float) $linea->cantidad : null,
                'formula_texto' => $linea->formula_texto,
                'codigo_plame_snapshot' => $linea->codigo_plame_snapshot,
            ];
        }

        return [
            'ingresos' => $bloques['ingreso'], 'egresos' => $bloques['egreso'], 'aportaciones' => $bloques['aportacion'],
            'total_ingresos' => (float) $boleta->total_ingresos, 'total_egresos' => (float) $boleta->total_egresos,
            'total_aportaciones' => (float) $boleta->total_aportaciones, 'neto_a_pagar' => (float) $boleta->neto_a_pagar,
        ];
    }

    /**
     * Agrega un concepto manual (bono, comisión, descuento) a un colaborador
     * de una complementaria — únicamente mientras esté "calculada" (antes de
     * aprobarla): una vez aprobada, el monto ya quedó confirmado para pago/
     * exportación bancaria y para el PLAME (PlameCicloDatosLoader lee este
     * mismo calculo_snapshot), así que ya no debe seguir cambiando.
     *
     * No suma una línea "delta" aparte de calculo_snapshot: la agrega
     * directamente al bloque ingresos/egresos (misma forma que produce
     * CalcularBoletaColaborador::calcular()) y recalcula los totales del
     * snapshot para que quede internamente consistente — tanto para esta
     * exportación como para una futura complementaria que encadene sobre
     * "última pagada" (ver crear()).
     *
     * Si el concepto es un ingreso "es_remunerativo_laboral" (catálogo
     * ConceptoRemuneracionSeeder: COMISION/BONIFICACION marcan afecta_afp=
     * true), el aporte AFP/ONP y EsSalud del colaborador se recalculan sobre
     * la nueva base remunerativa — nunca quedan congelados con el valor del
     * cálculo original, porque legalmente si sube el ingreso computable
     * también sube el aporte previsional (ver recalcularAfpEssalud()).
     */
    public function agregarConcepto(Empresa $empresa, PlanillaComplementariaDetalle $detalle, int $conceptoId, ?int $conceptoDefinicionId, float $monto, ?string $motivo, int $usuarioId, bool $regularizacionFeriado = false): PlanillaComplementaria
    {
        if (! empty($detalle->calculo_snapshot['descansos_semanales']) || ! empty($detalle->calculo_snapshot['feriado_regularizado'])) {
            throw ValidationException::withMessages(['detalle' => 'Para corregir las semanas, elimina el borrador y vuelve a generar el reintegro.']);
        }
        $item = $detalle->complementaria;
        $this->verificarItem($empresa, $item);

        if ($item->estado !== 'calculada') {
            throw ValidationException::withMessages(['estado' => 'Solo se pueden agregar conceptos mientras la complementaria esté calculada, antes de aprobarla.']);
        }

        $concepto = ConceptoRemuneracion::where('id', $conceptoId)->where('activo', true)->firstOrFail();

        if (! in_array($concepto->tipo, ['ingreso', 'egreso'], true)) {
            throw ValidationException::withMessages(['concepto_id' => 'Solo se pueden agregar manualmente conceptos de tipo ingreso o egreso.']);
        }

        // Régimen de LA BOLETA que se regulariza, nunca el régimen vivo del
        // colaborador: este último puede haber cambiado desde entonces
        // (ascenso de honorarios a planilla o viceversa), y lo que importa
        // acá es bajo qué régimen se pagó ESA boleta específica — mismo
        // criterio que ya usan PlameCicloDatosLoader, BoletaService,
        // ResumenContableService, etc. en todo el módulo.
        $esHonorarios = $detalle->boletaOriginal->regimen_laboral_snapshot === 'Locacion de Servicios';
        // $regularizacionFeriado solo lo pasa internamente
        // crearFeriadoBloqueado() para su propio concepto
        // HONORARIO_FERIADO_TRABAJADO — nunca HE_100 (Tabla 22 es exclusivo
        // de trabajadores dependientes) — no está expuesto por el endpoint
        // manual de "+ agregar concepto": la regla general para RR.HH.
        // agregando conceptos a mano sigue intacta, un locador solo admite
        // descuentos.
        if ($esHonorarios && $concepto->tipo !== 'egreso' && ! ($regularizacionFeriado && $concepto->codigo === 'HONORARIO_FERIADO_TRABAJADO')) {
            throw ValidationException::withMessages([
                'concepto_id' => 'Un locador (Recibos por Honorarios) solo admite conceptos de descuento — los ingresos remunerativos son exclusivos de planilla dependiente.',
            ]);
        }

        $montoRedondeado = round($monto, 2);
        $bloque = $concepto->tipo === 'ingreso' ? 'ingresos' : 'egresos';

        DB::transaction(function () use ($detalle, $concepto, $conceptoDefinicionId, $montoRedondeado, $bloque, $motivo, $usuarioId, $esHonorarios) {
            $snapshot = $detalle->calculo_snapshot;
            $snapshot[$bloque][] = [
                // Identificador propio (no hay columna dedicada: la línea
                // vive dentro del JSON) — único punto de referencia estable
                // para poder editarla/eliminarla después.
                'id' => (string) Str::uuid(),
                'codigo' => $concepto->codigo,
                'monto' => $montoRedondeado,
                'base_utilizada' => null,
                'tasa_aplicada' => null,
                'cantidad' => null,
                'motivo' => $motivo,
                'formula_texto' => 'Concepto manual agregado a la complementaria'.(filled($motivo) ? " — {$motivo}" : ''),
                // BONIFICACION/BONO_NO_REMUNERATIVO llegan con una
                // clasificación PLAME concreta (ver validación del
                // controller) — PlameCicloDatosLoader la lee para resolver
                // el codigo_plame_snapshot correcto, nunca el genérico.
                'concepto_definicion_id' => $conceptoDefinicionId,
                // Marca que distingue una línea agregada a mano de las que
                // produjo el motor de cálculo — es lo que permite listarlas/
                // eliminarlas por separado (ver eliminarConcepto()).
                'agregado_por' => $usuarioId,
                'agregado_en' => now()->toDateTimeString(),
            ];

            if ($bloque === 'ingresos' && ! $esHonorarios && $concepto->es_remunerativo_laboral) {
                $this->recalcularAfpEssalud($detalle, $snapshot, $montoRedondeado);
                if ($concepto->afecta_renta_5ta) {
                    $this->recalcularRentaQuinta($detalle, $snapshot, $montoRedondeado);
                }
                $this->recalcularProvisiones($detalle, $snapshot, $montoRedondeado);
            }

            $this->guardarSnapshotYDiferencias($detalle, $snapshot);
        });

        return $this->cargar($item);
    }

    /**
     * Elimina un concepto manual agregado por error — únicamente mientras la
     * complementaria siga "calculada", igual que agregarConcepto(). Nunca
     * toca una línea que produjo el motor de cálculo (solo las marcadas con
     * `agregado_por`): no existe forma de "eliminar" un descuento por falta
     * real, solo de corregir la asistencia y volver a calcular.
     *
     * Si la línea eliminada era un ingreso remunerativo que ya había
     * disparado un recálculo de AFP/EsSalud (agregarConcepto()), ese aporte
     * se revierte simétricamente acá — nunca queda inflado con un ingreso
     * que ya no existe.
     */
    public function eliminarConcepto(Empresa $empresa, PlanillaComplementariaDetalle $detalle, string $lineaId): PlanillaComplementaria
    {
        if (! empty($detalle->calculo_snapshot['descansos_semanales']) || ! empty($detalle->calculo_snapshot['feriado_regularizado'])) {
            throw ValidationException::withMessages(['detalle' => 'Para corregir las semanas, elimina el borrador y vuelve a generar el reintegro.']);
        }
        $item = $detalle->complementaria;
        $this->verificarItem($empresa, $item);

        if ($item->estado !== 'calculada') {
            throw ValidationException::withMessages(['estado' => 'Solo se pueden eliminar conceptos mientras la complementaria esté calculada, antes de aprobarla.']);
        }

        DB::transaction(function () use ($detalle, $lineaId) {
            $snapshot = $detalle->calculo_snapshot;
            [$bloque, $linea] = $this->buscarLineaManual($snapshot, $lineaId);

            if (! $linea) {
                throw ValidationException::withMessages(['linea' => 'El concepto ya no existe o no fue agregado manualmente — no se puede eliminar un concepto calculado automáticamente.']);
            }

            $snapshot[$bloque] = collect($snapshot[$bloque])
                ->reject(fn (array $l) => ($l['id'] ?? null) === $lineaId)
                ->values()->all();

            if ($bloque === 'ingresos') {
                $colaborador = $detalle->colaborador;
                $esHonorarios = $colaborador->tipo_contrato === 'locacion_servicios' || $colaborador->regimen_laboral === 'Locacion de Servicios';
                $concepto = ConceptoRemuneracion::where('codigo', $linea['codigo'])->first();
                if (! $esHonorarios && $concepto?->es_remunerativo_laboral) {
                    $this->recalcularAfpEssalud($detalle, $snapshot, -1 * (float) $linea['monto']);
                    if ($concepto->afecta_renta_5ta) {
                        $this->recalcularRentaQuinta($detalle, $snapshot, -1 * (float) $linea['monto']);
                    }
                    $this->recalcularProvisiones($detalle, $snapshot, -1 * (float) $linea['monto']);
                }
            }

            $this->guardarSnapshotYDiferencias($detalle, $snapshot);
        });

        return $this->cargar($item);
    }

    /**
     * @return array{0: ?string, 1: ?array} [bloque, línea] o [null, null] si
     *   no se encontró o no era una línea agregada manualmente.
     */
    private function buscarLineaManual(array $snapshot, string $lineaId): array
    {
        foreach (['ingresos', 'egresos'] as $bloque) {
            foreach ($snapshot[$bloque] ?? [] as $linea) {
                if (($linea['id'] ?? null) === $lineaId && isset($linea['agregado_por'])) {
                    return [$bloque, $linea];
                }
            }
        }

        return [null, null];
    }

    /**
     * Recalcula AFP/ONP y EsSalud/SIS sobre la base remunerativa + $deltaBase
     * (positivo al agregar un ingreso remunerativo, negativo al eliminarlo),
     * reutilizando EXACTAMENTE las mismas fórmulas de
     * PlanillaDependienteCalculator (tope RMA de la prima de seguro, piso
     * legal de EsSalud, tipo de comisión AFP del colaborador) en vez de
     * reimplementarlas — un cálculo propio acá divergiría en los casos
     * límite (tope/piso) del que ya usó CalcularBoletaColaborador para el
     * cálculo original. Reemplaza las líneas AFP/ONP/EsSalud existentes del
     * snapshot por las nuevas — nunca sirve solo la diferencia porque el
     * tope/piso no escalan linealmente con la base.
     *
     * Nunca se llama para honorarios (ya filtrado por el caller): un
     * locador no tiene AFP/ONP/EsSalud.
     */
    private function recalcularAfpEssalud(PlanillaComplementariaDetalle $detalle, array &$snapshot, float $deltaBase): void
    {
        $colaborador = $detalle->colaborador;
        $ciclo = $detalle->boletaOriginal->ciclo;
        $regimen = $colaborador->regimen_laboral ?: 'General';
        $fechaCorte = $ciclo->fecha_corte_asistencia->toDateString();
        $fechaPago = $ciclo->fecha_pago->toDateString();

        $parametros = ParametrosVigentesResolver::paraRegimen($colaborador->empresa, $regimen, $fechaCorte);
        $rmaAfp = $colaborador->sistema_previsional === 'onp'
            ? null
            : ParametrosVigentesResolver::rmaAfp($colaborador->empresa, $regimen, $fechaPago);
        $calculadora = RegimenCalculatorFactory::paraRegimen($regimen);

        $codigosPrevisionales = ['AFP_APORTE_OBLIGATORIO', 'AFP_PRIMA_SEGURO', 'AFP_COMISION', 'ONP'];
        $lineaPrevisionalActual = collect($snapshot['egresos'] ?? [])
            ->first(fn (array $l) => in_array($l['codigo'], $codigosPrevisionales, true));
        $baseActual = (float) ($lineaPrevisionalActual['base_utilizada'] ?? 0.0);
        $nuevaBase = max(0.0, round($baseActual + $deltaBase, 2));

        $nuevasLineasPrevisionales = $calculadora->calcularAporteAfpOnp($colaborador, $nuevaBase, $parametros, $fechaCorte, $rmaAfp);
        $snapshot['egresos'] = collect($snapshot['egresos'] ?? [])
            ->reject(fn (array $l) => in_array($l['codigo'], $codigosPrevisionales, true))
            ->concat($nuevasLineasPrevisionales)
            ->values()->all();

        $essalud = $calculadora->calcularEsSalud($nuevaBase, $parametros, $colaborador->empresa->seguro_salud);
        $codigosEssalud = ['ESSALUD', 'SIS_APORTACION'];
        $snapshot['aportaciones'] = collect($snapshot['aportaciones'] ?? [])
            ->reject(fn (array $l) => in_array($l['codigo'], $codigosEssalud, true))
            ->push($essalud['linea'])
            ->values()->all();
    }

    private function recalcularRentaQuinta(PlanillaComplementariaDetalle $detalle, array &$snapshot, float $deltaBase): void
    {
        $colaborador = $detalle->colaborador;
        $ciclo = $detalle->boletaOriginal->ciclo;
        $fechaCorte = $ciclo->fecha_corte_asistencia->toDateString();
        $parametros = ParametrosVigentesResolver::paraRegimen($colaborador->empresa, $colaborador->regimen_laboral ?: 'General', $fechaCorte);
        $lineaActual = collect($snapshot['egresos'] ?? [])->firstWhere('codigo', 'RENTA_5TA');
        $baseActual = (float) ($lineaActual['base_utilizada'] ?? collect($snapshot['ingresos'] ?? [])->sum('monto'));
        $nueva = $this->calculador->calcularRenta5ta($colaborador, max(0, $baseActual + $deltaBase), $parametros, $fechaCorte, $ciclo->id);

        $snapshot['egresos'] = collect($snapshot['egresos'] ?? [])
            ->reject(fn (array $linea) => $linea['codigo'] === 'RENTA_5TA')->values()->all();
        if ($nueva) {
            $snapshot['egresos'][] = $nueva;
        }
    }

    /**
     * Recalcula las provisiones de CTS/gratificación/bonificación
     * extraordinaria/vacaciones (siempre dentro de `calculo_snapshot`, la
     * boleta original nunca se toca) cuando se agrega o elimina un ingreso
     * remunerativo — misma razón que recalcularAfpEssalud(): estas
     * provisiones también deben subir/bajar con la base remunerativa
     * (ConceptoRemuneracionSeeder: COMISION/BONIFICACION marcan
     * afecta_cts=afecta_gratificacion=afecta_vacaciones=true).
     *
     * Usa la base REGULAR (antes de descuentos de asistencia), no la neta
     * de asistencia que usa recalcularAfpEssalud() — son bases distintas a
     * propósito en CalcularBoletaColaborador, VACACIONES_PROVISION/
     * GRATIFICACION_LEGAL ya la guardan tal cual en `base_utilizada` así que
     * no hace falta volver a derivarla.
     *
     * Importante: por sí sola, esta corrección es interna al snapshot de la
     * complementaria — BeneficioSocialService::calcularEnVivo() (el cálculo
     * real de CTS/gratificación que se paga en mayo/noviembre/julio/
     * diciembre) también fue extendido para leer este ajuste; sin ese otro
     * cambio, esto habría quedado meramente informativo.
     */
    private function recalcularProvisiones(PlanillaComplementariaDetalle $detalle, array &$snapshot, float $deltaBase): void
    {
        $colaborador = $detalle->colaborador;
        $ciclo = $detalle->boletaOriginal->ciclo;
        $regimen = $colaborador->regimen_laboral ?: 'General';
        $fechaCorte = $ciclo->fecha_corte_asistencia->toDateString();
        $parametros = ParametrosVigentesResolver::paraRegimen($colaborador->empresa, $regimen, $fechaCorte);
        $calculadora = RegimenCalculatorFactory::paraRegimen($regimen);

        $codigosProvisiones = ['CTS_PROVISION', 'GRATIFICACION_LEGAL', 'BONIFICACION_EXTRAORDINARIA', 'VACACIONES_PROVISION'];
        $lineaBaseRegular = collect($snapshot['aportaciones'] ?? [])
            ->first(fn (array $l) => in_array($l['codigo'], ['VACACIONES_PROVISION', 'GRATIFICACION_LEGAL'], true));
        $baseRegularActual = (float) ($lineaBaseRegular['base_utilizada'] ?? 0.0);
        $nuevaBaseRegular = max(0.0, round($baseRegularActual + $deltaBase, 2));

        $gratificacionesSemestre = $this->calculador->gratificacionesPercibidasSemestre($colaborador, $fechaCorte, $ciclo->id);
        $nuevasLineasProvisiones = $calculadora->calcularProvisiones($nuevaBaseRegular, $gratificacionesSemestre, $parametros);

        $snapshot['aportaciones'] = collect($snapshot['aportaciones'] ?? [])
            ->reject(fn (array $l) => in_array($l['codigo'], $codigosProvisiones, true))
            ->concat($nuevasLineasProvisiones)
            ->values()->all();
    }

    /**
     * Recalcula los totales del snapshot y las 4 columnas de diferencia a
     * partir de la ECUACIÓN que ya define crear() (diferencia = nuevo -
     * base), nunca acumulando deltas sueltos: la "base" (lo que ya estaba
     * vigente antes de este cambio, sea el agregado de un concepto manual o
     * el recálculo de AFP/EsSalud que dispara) se despeja de lo que YA está
     * persistido (snapshot/diferencias actuales), así que un solo mecanismo
     * cubre cualquier combinación de cambios sin arrastrar un error de
     * redondeo o de signo entre agregarConcepto()/eliminarConcepto().
     */
    private function guardarSnapshotYDiferencias(PlanillaComplementariaDetalle $detalle, array $snapshot): void
    {
        $snapshotAntes = $detalle->calculo_snapshot;
        $baseIngresos = (float) ($snapshotAntes['total_ingresos'] ?? 0.0) - (float) $detalle->diferencia_ingresos;
        $baseEgresos = (float) ($snapshotAntes['total_egresos'] ?? 0.0) - (float) $detalle->diferencia_egresos;
        $baseAportaciones = (float) ($snapshotAntes['total_aportaciones'] ?? 0.0) - (float) $detalle->diferencia_aportaciones;

        $this->recalcularTotalesSnapshot($snapshot);

        $detalle->update([
            'calculo_snapshot' => $snapshot,
            'neto_recalculado' => $snapshot['neto_a_pagar'],
            'diferencia_ingresos' => round($snapshot['total_ingresos'] - $baseIngresos, 2),
            'diferencia_egresos' => round($snapshot['total_egresos'] - $baseEgresos, 2),
            'diferencia_aportaciones' => round($snapshot['total_aportaciones'] - $baseAportaciones, 2),
            'diferencia_neta' => round($snapshot['neto_a_pagar'] - (float) $detalle->neto_original, 2),
        ]);
    }

    private function recalcularTotalesSnapshot(array &$snapshot): void
    {
        $snapshot['total_ingresos'] = round(collect($snapshot['ingresos'] ?? [])->sum('monto'), 2);
        $snapshot['total_egresos'] = round(collect($snapshot['egresos'] ?? [])->sum('monto'), 2);
        $snapshot['total_aportaciones'] = round(collect($snapshot['aportaciones'] ?? [])->sum('monto'), 2);
        $snapshot['neto_a_pagar'] = round($snapshot['total_ingresos'] - $snapshot['total_egresos'], 2);
    }

    public function aprobar(Empresa $empresa, PlanillaComplementaria $item, int $usuarioId): PlanillaComplementaria
    {
        $this->verificarItem($empresa, $item);
        if ($item->estado !== 'calculada') {
            throw ValidationException::withMessages(['estado' => 'Solo se puede aprobar una complementaria calculada.']);
        }
        $item->update(['estado' => 'aprobada', 'aprobado_por' => $usuarioId, 'aprobado_at' => now()]);
        return $this->cargar($item);
    }

    /**
     * Elimina por completo una complementaria creada por error — solo
     * mientras siga "calculada": una vez aprobada representa un compromiso
     * de pago/descuento ya confirmado (y puede haber sido exportada al banco
     * o incluida en un PLAME), así que a partir de ahí ya no se puede
     * borrar, solo seguir su flujo normal. `PlanillaComplementariaDetalle`
     * se elimina en cascada (constraint de la migración) — no hace falta
     * borrarlo aparte.
     */
    public function eliminar(Empresa $empresa, PlanillaComplementaria $item): void
    {
        $this->verificarItem($empresa, $item);

        if ($item->estado !== 'calculada') {
            throw ValidationException::withMessages(['estado' => 'Solo se puede eliminar una complementaria calculada — una ya aprobada o pagada no se puede borrar, solo seguir su flujo normal.']);
        }

        $item->delete();
    }

    public function marcarPagada(Empresa $empresa, PlanillaComplementaria $item, int $usuarioId, string $referencia): PlanillaComplementaria
    {
        $this->verificarItem($empresa, $item);
        if ($item->estado !== 'aprobada') {
            throw ValidationException::withMessages(['estado' => 'Solo se puede pagar una complementaria aprobada.']);
        }
        $item->update(['estado' => 'pagada', 'pagado_por' => $usuarioId, 'pagado_at' => now(), 'referencia_pago' => $referencia]);
        return $this->cargar($item);
    }

    public function boletasDePago(Empresa $empresa, PlanillaComplementaria $item, string $subtipo): Collection
    {
        $this->verificarItem($empresa, $item);
        if ($item->estado !== 'aprobada') {
            throw ValidationException::withMessages(['estado' => 'Aprueba la complementaria antes de generar el archivo bancario.']);
        }

        $esCuarta = $subtipo === '4';
        $detalles = $item->detalles()->where('diferencia_neta', '>', 0)
            ->with(['boletaOriginal.colaborador'])->get()
            ->filter(fn ($d) => ($d->boletaOriginal->regimen_laboral_snapshot === 'Locacion de Servicios') === $esCuarta);

        if ($detalles->isEmpty()) {
            throw ValidationException::withMessages(['detalles' => 'No existen diferencias positivas para la categoría seleccionada.']);
        }

        return $detalles->map(function ($detalle) {
            $boleta = $detalle->boletaOriginal->replicate();
            $boleta->id = $detalle->boleta_original_id;
            $boleta->neto_a_pagar = $detalle->diferencia_neta;
            $boleta->setRelation('colaborador', $detalle->boletaOriginal->colaborador);
            $datosPago = new BoletaDatosPago([
                'banco_id' => $detalle->banco_id,
                'tipo_cuenta_snapshot' => $detalle->tipo_cuenta_snapshot,
                'moneda_snapshot' => $detalle->moneda_snapshot,
                'numero_cuenta_snapshot' => $detalle->numero_cuenta_snapshot,
                'cci_snapshot' => $detalle->cci_snapshot,
                'fecha_snapshot' => $detalle->created_at,
            ]);
            $datosPago->setRelation('banco', \App\Modules\Configuracion\Models\Banco::find($detalle->banco_id));
            $boleta->setRelation('datosPago', $datosPago);
            return $boleta;
        })->values();
    }

    public function exportarBcp(Empresa $empresa, PlanillaComplementaria $item, $cuenta, string $fechaProceso, string $subtipo): string
    {
        return TelecreditoBcpTxtExporter::generar($cuenta, str_replace('-', '', $fechaProceso), $subtipo, 'COMPLEMENTARIA '.$item->id, $this->boletasDePago($empresa, $item, $subtipo));
    }

    public function exportarBbva(Empresa $empresa, PlanillaComplementaria $item, $cuenta, string $subtipo): string
    {
        return BbvaNetCashTxtExporter::generar($cuenta, $subtipo, 'COMPLEMENTARIA '.$item->id, $this->boletasDePago($empresa, $item, $subtipo));
    }

    private function cargar(PlanillaComplementaria $item): PlanillaComplementaria
    {
        return $item->load(['detalles.colaborador:id,nombres,apellidos,numero_documento', 'detalles.boletaOriginal.datosPago.banco']);
    }

    private function verificar(Empresa $empresa, CicloRemunerativo $ciclo): void
    {
        if ($ciclo->empresa_id !== $empresa->id) throw new AuthorizationException('El ciclo no pertenece a la empresa autorizada.');
    }

    private function verificarItem(Empresa $empresa, PlanillaComplementaria $item): void
    {
        if ($item->empresa_id !== $empresa->id) throw new AuthorizationException('La complementaria no pertenece a la empresa autorizada.');
    }
}
