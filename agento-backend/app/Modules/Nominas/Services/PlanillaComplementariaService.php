<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Asistencia\Models\AsistenciaHoraExtra;
use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Afp;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Application\CalcularBoletaColaborador;
use App\Modules\Nominas\Application\CalcularReciboHonorarios;
use App\Modules\Nominas\Domain\BbvaNetCash\BbvaNetCashExportException;
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
use Illuminate\Support\Carbon;
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

    public function colaboradoresDisponibles(Empresa $empresa, PlanillaComplementaria $item, ?string $busqueda = null): Collection
    {
        $this->verificarItem($empresa, $item);

        if ($item->estado !== 'calculada') {
            throw ValidationException::withMessages(['estado' => 'Solo se pueden agregar colaboradores a una complementaria calculada.']);
        }

        $ocupados = PlanillaComplementariaDetalle::whereHas('complementaria', fn ($q) => $q
            ->where('ciclo_id', $item->ciclo_id)
            ->where('estado', 'calculada'))
            ->pluck('colaborador_id');

        return Boleta::where('ciclo_id', $item->ciclo_id)
            ->where('estado', 'pagada')
            ->where('es_version_vigente', true)
            ->whereNotIn('colaborador_id', $ocupados)
            ->with('colaborador')
            ->when(filled($busqueda), function ($query) use ($busqueda) {
                $termino = '%'.trim($busqueda).'%';
                $query->whereHas('colaborador', fn ($q) => $q
                    ->where('nombres', 'like', $termino)
                    ->orWhere('apellidos', 'like', $termino)
                    ->orWhere('numero_documento', 'like', $termino));
            })
            ->orderBy('colaborador_id')
            ->get()
            ->map(fn (Boleta $boleta) => [
                'boleta_id' => $boleta->id,
                'colaborador_id' => $boleta->colaborador_id,
                'colaborador' => trim(($boleta->colaborador?->nombres ?? '').' '.($boleta->colaborador?->apellidos ?? '')),
                'documento' => $boleta->colaborador?->numero_documento,
                'regimen_laboral' => $boleta->regimen_laboral_snapshot,
            ])
            ->values();
    }

    /** @param array<int, int> $boletaIds */
    public function agregarColaboradores(Empresa $empresa, PlanillaComplementaria $item, array $boletaIds): PlanillaComplementaria
    {
        return DB::transaction(function () use ($empresa, $item, $boletaIds) {
            $item = PlanillaComplementaria::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->verificarItem($empresa, $item);

            if ($item->estado !== 'calculada') {
                throw ValidationException::withMessages(['estado' => 'Solo se pueden agregar colaboradores mientras la complementaria esté calculada.']);
            }

            $ids = array_values(array_unique(array_map('intval', $boletaIds)));
            $boletas = Boleta::where('ciclo_id', $item->ciclo_id)
                ->whereIn('id', $ids)
                ->where('estado', 'pagada')
                ->where('es_version_vigente', true)
                ->with(['colaborador', 'conceptos.concepto', 'datosPago'])
                ->lockForUpdate()
                ->get();

            if ($boletas->count() !== count($ids)) {
                throw ValidationException::withMessages(['boleta_ids' => 'Selecciona boletas vigentes y pagadas del ciclo original.']);
            }

            $ocupados = PlanillaComplementariaDetalle::whereIn('colaborador_id', $boletas->pluck('colaborador_id'))
                ->whereHas('complementaria', fn ($q) => $q
                    ->where('ciclo_id', $item->ciclo_id)
                    ->where('estado', 'calculada'))
                ->lockForUpdate()
                ->exists();

            if ($ocupados) {
                throw ValidationException::withMessages(['boleta_ids' => 'Uno de los colaboradores ya pertenece a un borrador de complementaria sin aprobar. Actualiza la lista e inténtalo nuevamente.']);
            }

            foreach ($boletas as $boleta) {
                $base = $this->baseParaReintegro($boleta);
                // Si la base proviene de una complementaria pagada anterior,
                // conservar sus montos pero no las marcas operativas que
                // bloqueaban agregar conceptos en este nuevo borrador.
                unset($base['descansos_semanales'], $base['feriado_regularizado']);
                $pago = $boleta->datosPago;
                $colaborador = $boleta->colaborador;

                PlanillaComplementariaDetalle::create([
                    'planilla_complementaria_id' => $item->id,
                    'boleta_original_id' => $boleta->id,
                    'colaborador_id' => $boleta->colaborador_id,
                    'banco_id' => $pago?->banco_id ?? $colaborador?->banco_id,
                    'tipo_cuenta_snapshot' => $pago?->tipo_cuenta_snapshot ?? $colaborador?->tipo_cuenta,
                    'moneda_snapshot' => $pago?->moneda_snapshot ?? $colaborador?->moneda_cuenta,
                    'numero_cuenta_snapshot' => $pago?->numero_cuenta_snapshot ?? $colaborador?->numero_cuenta,
                    'cci_snapshot' => $pago?->cci_snapshot ?? $colaborador?->cci,
                    'neto_original' => $base['neto_a_pagar'],
                    'neto_recalculado' => $base['neto_a_pagar'],
                    'diferencia_ingresos' => 0,
                    'diferencia_egresos' => 0,
                    'diferencia_aportaciones' => 0,
                    'diferencia_neta' => 0,
                    'calculo_snapshot' => $base,
                ]);
            }

            return $this->cargar($item);
        });
    }

    /**
     * Criterio determinístico para reconocer un "bono por asistencia" ya
     * aplicado dentro de calculo_snapshot['bonos_masivos'] — combina ciclo,
     * operador, días y concepto: el mismo colaborador no puede recibir DOS
     * VECES el mismo bono, pero sí puede recibir otro bono distinto (otro
     * concepto u otro umbral de días) sobre el mismo ciclo pagado.
     */
    private function criterioAsistencia(int $cicloId, string $operador, int $dias, int $conceptoId): string
    {
        return "asistencia:{$operador}:{$dias}:{$cicloId}:{$conceptoId}";
    }

    /**
     * Lista, para un ciclo YA PAGADO, todos los colaboradores cuyos días de
     * asistencia ('presente' en AsistenciaResultadoDiario, cruzando desde el
     * módulo Asistencia con empresa_id explícito) cumplen el criterio de días
     * (exacto o mínimo) — incluyendo a los que ya NO están disponibles para
     * el bono, con el motivo, para que el frontend pueda explicar la
     * exclusión en vez de ocultarlos silenciosamente. Reutilizable para
     * cualquier concepto/umbral: no asume "27 días" ni ningún monto.
     *
     * @return array{colaboradores: array<int, array{boleta_id:int,colaborador_id:int,colaborador:string,documento:?string,dias_asistidos:int,disponible:bool,motivo:?string}>}
     */
    public function colaboradoresPorAsistencia(Empresa $empresa, CicloRemunerativo $ciclo, int $dias, string $operador, int $conceptoId): array
    {
        $this->verificar($empresa, $ciclo);

        if (! in_array($operador, ['exacto', 'minimo'], true)) {
            throw ValidationException::withMessages(['operador' => 'El operador debe ser "exacto" o "minimo".']);
        }
        if ($ciclo->estado !== 'pagado') {
            throw ValidationException::withMessages(['estado' => 'El bono por asistencia solo se aplica sobre un ciclo pagado.']);
        }

        // Siempre con empresa_id explícito: AsistenciaResultadoDiario
        // pertenece a otro módulo (Asistencia) y su scope global nunca debe
        // asumirse como único límite de tenant en una query entre módulos.
        $conteos = AsistenciaResultadoDiario::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->whereNotNull('entrada_at')->whereNotNull('salida_at')
            ->whereColumn('salida_at', '>', 'entrada_at')->where('minutos_trabajados', '>', 0)
            ->whereDate('fecha', '>=', $ciclo->fecha_inicio->toDateString())
            ->whereDate('fecha', '<=', $ciclo->fecha_fin->toDateString())
            ->selectRaw('colaborador_id, count(*) as dias')
            ->groupBy('colaborador_id')
            ->having('dias', $operador === 'exacto' ? '=' : '>=', $dias)
            ->pluck('dias', 'colaborador_id');

        if ($conteos->isEmpty()) {
            return ['colaboradores' => []];
        }

        $boletas = Boleta::where('ciclo_id', $ciclo->id)
            ->where('estado', 'pagada')
            ->where('es_version_vigente', true)
            ->whereIn('colaborador_id', $conteos->keys())
            ->with('colaborador')
            ->get();

        // Mismo criterio "ocupados" que ya usan agregarColaboradores() /
        // colaboradoresDisponibles(): un colaborador con un borrador de
        // complementaria sin aprobar en este ciclo no puede recibir otro
        // bono hasta que ese borrador se apruebe o se elimine.
        $ocupados = PlanillaComplementariaDetalle::whereIn('colaborador_id', $boletas->pluck('colaborador_id'))
            ->whereHas('complementaria', fn ($q) => $q
                ->where('ciclo_id', $ciclo->id)
                ->whereIn('estado', ['calculada', 'aprobada']))
            ->pluck('colaborador_id');

        $criterio = $this->criterioAsistencia($ciclo->id, $operador, $dias, $conceptoId);
        $yaRecibieron = PlanillaComplementariaDetalle::whereIn('boleta_original_id', $boletas->pluck('id'))
            ->whereHas('complementaria', fn ($q) => $q->whereIn('estado', ['calculada', 'aprobada', 'pagada']))
            ->get(['id', 'boleta_original_id', 'calculo_snapshot'])
            ->filter(fn ($d) => collect($d->calculo_snapshot['bonos_masivos'] ?? [])->contains('criterio', $criterio))
            ->pluck('boleta_original_id');

        $colaboradores = $boletas->map(function (Boleta $boleta) use ($conteos, $ocupados, $yaRecibieron) {
            $motivo = match (true) {
                $ocupados->contains($boleta->colaborador_id) => 'Ya tiene una complementaria pendiente.',
                $yaRecibieron->contains($boleta->id) => 'Ya recibió este bono.',
                default => null,
            };
            return [
                'boleta_id' => $boleta->id,
                'colaborador_id' => $boleta->colaborador_id,
                'colaborador' => trim(($boleta->colaborador?->nombres ?? '').' '.($boleta->colaborador?->apellidos ?? '')),
                'documento' => $boleta->colaborador?->numero_documento,
                'dias_asistidos' => (int) $conteos[$boleta->colaborador_id],
                'disponible' => $motivo === null,
                'motivo' => $motivo,
            ];
        })->sortBy('colaborador')->values()->all();

        return ['colaboradores' => $colaboradores];
    }

    /**
     * Aplica un concepto (bono) en bloque a varias boletas pagadas de un
     * ciclo cerrado, filtradas por días de asistencia — SIEMPRE mediante una
     * PlanillaComplementaria NUEVA (nunca reutiliza un borrador existente:
     * más simple y más seguro para un lote masivo). Sigue el mismo patrón
     * que DescansoSemanalComplementariaService::crear(): agregarConcepto()
     * ya decide sola si recalcula AFP/EsSalud/renta 5ta/provisiones según el
     * concepto (para BONO_NO_REMUNERATIVO, es_remunerativo_laboral=false,
     * así que solo puede afectar renta de 5ta — nunca AFP/EsSalud/CTS), y
     * acá solo se agrega la marca propia `bonos_masivos` al snapshot para
     * poder identificar el bono después (ver colaboradoresPorAsistencia()).
     *
     * @param array<int, int> $boletaIds
     */
    public function aplicarBonoPorAsistencia(Empresa $empresa, CicloRemunerativo $ciclo, array $boletaIds, int $dias, string $operador, int $conceptoId, ?int $conceptoDefinicionId, float $monto, string $motivo, int $usuarioId): PlanillaComplementaria
    {
        return DB::transaction(function () use ($empresa, $ciclo, $boletaIds, $dias, $operador, $conceptoId, $conceptoDefinicionId, $monto, $motivo, $usuarioId) {
            // Serializa la creación para que dos solicitudes no compitan por
            // los mismos colaboradores (mismo patrón que reintegrarDescuentos()).
            $ciclo = CicloRemunerativo::whereKey($ciclo->id)->lockForUpdate()->firstOrFail();

            $ids = array_values(array_unique(array_map('intval', $boletaIds)));

            // Nunca confiar en lo que mandó el frontend (esto es dinero
            // real): se recalcula la disponibilidad COMPLETA dentro de la
            // transacción — la asistencia, el bloqueo por otro borrador
            // pendiente y el bono ya recibido pudieron cambiar entre que el
            // usuario vio la lista y confirmó la selección.
            $disponibles = collect($this->colaboradoresPorAsistencia($empresa, $ciclo, $dias, $operador, $conceptoId)['colaboradores'])
                ->keyBy('boleta_id');
            foreach ($ids as $boletaId) {
                $fila = $disponibles->get($boletaId);
                if (! $fila || ! $fila['disponible']) {
                    throw ValidationException::withMessages(['boleta_ids' => 'Uno de los colaboradores ya no está disponible: cambió su asistencia, ya tiene una complementaria pendiente o ya recibió este bono. Actualiza la lista e inténtalo nuevamente.']);
                }
            }

            $concepto = ConceptoRemuneracion::where('id', $conceptoId)->where('activo', true)->firstOrFail();
            if ($concepto->tipo !== 'ingreso') {
                throw ValidationException::withMessages(['concepto_id' => 'Un bono por asistencia solo admite conceptos de tipo ingreso.']);
            }
            // Defensa en profundidad: el Controller ya exige/prohíbe
            // concepto_definicion_id con este mismo criterio (BONIFICACION/
            // BONO_NO_REMUNERATIVO) antes de llegar aquí, pero el Service no
            // debe confiar únicamente en esa validación HTTP.
            $requiereDefinicion = in_array($concepto->codigo, ['BONIFICACION', 'BONO_NO_REMUNERATIVO'], true);
            if ($requiereDefinicion && ! $conceptoDefinicionId) {
                throw ValidationException::withMessages(['concepto_definicion_id' => 'Este concepto requiere seleccionar una clasificación PLAME (Tabla 22).']);
            }
            if (! $requiereDefinicion && $conceptoDefinicionId) {
                throw ValidationException::withMessages(['concepto_definicion_id' => 'Este concepto no admite una clasificación PLAME adicional.']);
            }

            $etiquetaDias = $operador === 'minimo' ? '>= '.$dias : (string) $dias;
            $item = PlanillaComplementaria::create([
                'ciclo_id' => $ciclo->id,
                'empresa_id' => $empresa->id,
                'nombre' => 'Bono asistencia '.$etiquetaDias.'d '.$ciclo->nombre,
                'motivo' => $motivo,
                'estado' => 'calculada',
                'creado_por' => $usuarioId,
            ]);

            $this->agregarColaboradores($empresa, $item, $ids);

            $criterio = $this->criterioAsistencia($ciclo->id, $operador, $dias, $conceptoId);
            $bloque = $concepto->tipo === 'ingreso' ? 'ingresos' : 'egresos';

            // $item->detalles()->get() en vez de cargar($item): cargar()
            // recorta la relación `colaborador` a columnas sin empresa_id
            // (footgun documentado en agregarConcepto()) — acá no hace falta
            // esa relación para nada, así que se evita ese recorte del todo.
            foreach ($item->detalles()->get() as $detalle) {
                $this->agregarConcepto($empresa, $detalle, $conceptoId, $conceptoDefinicionId, $monto, $motivo, $usuarioId);

                $snapshot = $detalle->fresh()->calculo_snapshot;
                $linea = collect($snapshot[$bloque] ?? [])->last(fn (array $l) => ($l['agregado_por'] ?? null) === $usuarioId);
                $snapshot['bonos_masivos'][] = [
                    'linea_id' => $linea['id'] ?? null,
                    'criterio' => $criterio,
                    'dias' => $dias,
                    'operador' => $operador,
                    'monto' => $monto,
                    'motivo' => $motivo,
                    'registrado_por' => $usuarioId,
                    'registrado_en' => now()->toDateTimeString(),
                ];
                $detalle->update(['calculo_snapshot' => $snapshot]);
            }

            return $this->cargar($item);
        });
    }

    public function horasExtraPendientes(Empresa $empresa, CicloRemunerativo $ciclo, array $boletaIds = []): array
    {
        $this->verificar($empresa, $ciclo);
        if ($ciclo->estado !== 'pagado') {
            throw ValidationException::withMessages(['estado' => 'Las horas extra pendientes solo se regularizan sobre un ciclo pagado.']);
        }

        $boletas = Boleta::where('ciclo_id', $ciclo->id)->where('estado', 'pagada')->where('es_version_vigente', true)
            ->when($boletaIds !== [], fn ($query) => $query->whereIn('id', $boletaIds))
            ->with(['colaborador', 'conceptos.concepto'])->get()->keyBy('colaborador_id');
        $horas = AsistenciaHoraExtra::withoutGlobalScopes()->where('empresa_id', $empresa->id)
            ->whereBetween('fecha', [$ciclo->fecha_inicio, $ciclo->fecha_fin])->where('estado', 'aprobado')
            ->whereIn('colaborador_id', $boletas->keys())->orderBy('fecha')->orderBy('id')->get();

        $detallesActivos = PlanillaComplementariaDetalle::whereIn('boleta_original_id', $boletas->pluck('id'))
            ->whereHas('complementaria', fn ($q) => $q->whereIn('estado', ['calculada', 'aprobada', 'pagada']))
            ->get(['id', 'colaborador_id', 'boleta_original_id', 'calculo_snapshot']);

        $reservas = $detallesActivos->flatMap(fn ($d) => $d->calculo_snapshot['horas_extra_regularizadas'] ?? [])
            ->where('origen', 'huellero')->groupBy('asistencia_hora_extra_id')
            ->map(fn ($items) => (int) $items->sum('minutos'));

        // Un HE al 100% ya cubierto por "descanso semanal sin sustitutorio" o "feriado
        // trabajado no pagado" no puede volver a ofrecerse aquí: ambos flujos pagan el
        // día completo (sueldo/30) sin dejar un vínculo por minutos en asistencia_horas_extra.
        $semanasReservadasPorColaborador = $detallesActivos->groupBy('colaborador_id')->map(
            fn ($detalles) => $detalles->flatMap(fn ($d) => $d->calculo_snapshot['descansos_semanales'] ?? [])
                ->pluck('semana_inicio')->filter()->unique()->values()
        );
        $feriadosReservadosPorColaborador = $detallesActivos->groupBy('colaborador_id')->map(
            fn ($detalles) => $detalles->pluck('calculo_snapshot.feriado_regularizado.fecha')->filter()->unique()->values()
        );

        $pagadoOriginal = [];
        foreach ($boletas as $boleta) {
            foreach ($boleta->conceptos as $linea) {
                $tasa = match ($linea->concepto?->codigo) { 'HE_25' => '25', 'HE_35' => '35', 'HE_100' => '100', default => null };
                if ($tasa) $pagadoOriginal[$boleta->colaborador_id][$tasa] = ($pagadoOriginal[$boleta->colaborador_id][$tasa] ?? 0) + (int) round((float) $linea->cantidad * 60);
            }
        }

        $resultado = [];
        foreach ($horas as $hora) {
            $boleta = $boletas[$hora->colaborador_id];
            if ((string) $hora->tasa === '100') {
                $fecha = $hora->fecha->toDateString();
                $enSemanaReservada = ($semanasReservadasPorColaborador[$hora->colaborador_id] ?? collect())
                    ->contains(fn ($inicio) => $fecha >= $inicio && $fecha <= Carbon::parse($inicio)->addDays(6)->toDateString());
                $enFeriadoReservado = ($feriadosReservadosPorColaborador[$hora->colaborador_id] ?? collect())->contains($fecha);
                if ($enSemanaReservada || $enFeriadoReservado) continue;
            }
            $cubiertos = min((int) $hora->minutos_aprobados, $pagadoOriginal[$hora->colaborador_id][(string) $hora->tasa] ?? 0);
            $pagadoOriginal[$hora->colaborador_id][(string) $hora->tasa] = max(0, ($pagadoOriginal[$hora->colaborador_id][(string) $hora->tasa] ?? 0) - $cubiertos);
            $pendientes = max(0, (int) $hora->minutos_aprobados - $cubiertos - (int) ($reservas[$hora->id] ?? 0));
            if ($pendientes === 0) continue;
            $resultado[] = [
                'id' => $hora->id, 'boleta_id' => $boleta->id, 'colaborador_id' => $hora->colaborador_id,
                'colaborador' => trim($boleta->colaborador->nombres.' '.$boleta->colaborador->apellidos),
                'fecha' => $hora->fecha->toDateString(), 'tasa' => (string) $hora->tasa,
                'minutos_aprobados' => (int) $hora->minutos_aprobados, 'minutos_pendientes' => $pendientes,
                'motivo' => $hora->motivo,
            ];
        }

        $colaboradores = $boletas
            ->map(fn ($b) => ['boleta_id' => $b->id, 'colaborador_id' => $b->colaborador_id,
                'colaborador' => trim($b->colaborador->nombres.' '.$b->colaborador->apellidos),
                'documento' => $b->colaborador->numero_documento])->values()->all();

        return ['horas' => $resultado, 'colaboradores' => $colaboradores];
    }

    /** @param array<int, array{hora_extra_id:int,minutos:int}> $detectadas @param array<int, array{boleta_id:int,fecha:string,minutos:int,tasa:string,motivo:string}> $manuales */
    public function agregarHorasExtra(Empresa $empresa, PlanillaComplementaria $item, array $detectadas, array $manuales, int $usuarioId): PlanillaComplementaria
    {
        return DB::transaction(function () use ($empresa, $item, $detectadas, $manuales, $usuarioId) {
            $item = PlanillaComplementaria::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->verificarItem($empresa, $item);
            if ($item->estado !== 'calculada') throw ValidationException::withMessages(['estado' => 'Solo se agregan horas extra a una complementaria calculada.']);

            $ciclo = $item->ciclo;
            $pendientes = collect($this->horasExtraPendientes($empresa, $ciclo)['horas'])->keyBy('id');
            $entradas = collect();
            foreach ($detectadas as $seleccion) {
                $hora = $pendientes->get((int) $seleccion['hora_extra_id']);
                if (! $hora || (int) $seleccion['minutos'] > $hora['minutos_pendientes']) {
                    throw ValidationException::withMessages(['horas_detectadas' => 'Una hora extra ya fue pagada, reservada o supera los minutos pendientes. Actualiza la lista.']);
                }
                $entradas->push([...$hora, 'origen' => 'huellero', 'minutos' => (int) $seleccion['minutos'], 'asistencia_hora_extra_id' => (int) $seleccion['hora_extra_id']]);
            }
            foreach ($manuales as $manual) {
                if ($manual['fecha'] < $ciclo->fecha_inicio->toDateString() || $manual['fecha'] > $ciclo->fecha_fin->toDateString()) {
                    throw ValidationException::withMessages(['horas_manuales' => 'La fecha manual debe pertenecer al período del ciclo pagado.']);
                }
                $entradas->push([...$manual, 'origen' => 'manual', 'asistencia_hora_extra_id' => null]);
            }
            if ($entradas->isEmpty()) throw ValidationException::withMessages(['horas_extra' => 'Selecciona o registra al menos una hora extra.']);

            $boletas = Boleta::where('ciclo_id', $ciclo->id)->whereIn('id', $entradas->pluck('boleta_id')->unique())
                ->where('estado', 'pagada')->where('es_version_vigente', true)->with('colaborador')->get()->keyBy('id');
            if ($boletas->count() !== $entradas->pluck('boleta_id')->unique()->count()) throw ValidationException::withMessages(['boleta_id' => 'Una boleta no pertenece al ciclo pagado.']);

            $faltantes = $boletas->keys()->diff($item->detalles()->pluck('boleta_original_id'));
            if ($faltantes->isNotEmpty()) $this->agregarColaboradores($empresa, $item, $faltantes->all());

            foreach ($entradas->groupBy('boleta_id') as $boletaId => $grupo) {
                $boleta = $boletas[$boletaId];
                $nombreColaborador = trim($boleta->colaborador->nombres.' '.$boleta->colaborador->apellidos);
                $esHonorarios = $boleta->regimen_laboral_snapshot === 'Locacion de Servicios';
                $detalle = $item->detalles()->where('boleta_original_id', $boletaId)->firstOrFail();
                $detalle->load(['colaborador.empresa', 'boletaOriginal.ciclo']);
                if (! empty($detalle->calculo_snapshot['descansos_semanales']) || ! empty($detalle->calculo_snapshot['feriado_regularizado'])) {
                    throw ValidationException::withMessages(['boleta_id' => "{$nombreColaborador} ya tiene un descanso semanal o feriado regularizado en este borrador: elimínalo y vuelve a generarlo aparte."]);
                }
                $snapshot = $detalle->calculo_snapshot;
                $delta = 0.0;
                foreach ($grupo as $entrada) {
                    $condicion = ColaboradorCondicionLaboral::vigenteEn($boleta->colaborador_id, $entrada['fecha']);
                    $habilitadaHistoricamente = $condicion?->contabilizar_horas_extra ?? false;
                    $autorizadaActualmente = (bool) $boleta->colaborador->contabilizar_horas_extra;
                    if (($condicion?->es_trabajador_confianza ?? false) || (! $habilitadaHistoricamente && ! $autorizadaActualmente)) {
                        throw ValidationException::withMessages(['horas_extra' => "{$nombreColaborador} no tenía habilitado el pago de horas extra en {$entrada['fecha']}."]);
                    }
                    $remuneracion = ColaboradorRemuneracion::where('colaborador_id', $boleta->colaborador_id)->whereDate('vigencia_desde', '<=', $entrada['fecha'])->latest('vigencia_desde')->latest('id')->first();
                    if (! $remuneracion) throw ValidationException::withMessages(['horas_extra' => "No existe remuneración histórica para {$nombreColaborador} al {$entrada['fecha']}."]);
                    $parametros = $esHonorarios
                        ? ParametrosVigentesResolver::paraHonorarios($empresa, $entrada['fecha'])
                        : ParametrosVigentesResolver::paraRegimen($empresa, $condicion?->regimen_laboral ?? $boleta->regimen_laboral_snapshot, $entrada['fecha']);
                    $factor = match ((string) $entrada['tasa']) { '25' => $parametros['horas_extra_tasa_x25'], '35' => $parametros['horas_extra_tasa_x35'], '100' => $parametros['horas_extra_tasa_nocturna'] };
                    $codigo = 'HE_'.(string) $entrada['tasa'];
                    $monto = round(((float) $remuneracion->salario / 240) * $factor * ((int) $entrada['minutos'] / 60), 2);
                    $delta += $monto;
                    $lineaId = (string) Str::uuid();
                    $snapshot['ingresos'][] = ['id' => $lineaId, 'codigo' => $codigo, 'monto' => $monto,
                        'base_utilizada' => round((float) $remuneracion->salario / 240, 4), 'tasa_aplicada' => $factor,
                        'cantidad' => round((int) $entrada['minutos'] / 60, 4), 'motivo' => $entrada['motivo'] ?? null,
                        'formula_texto' => "({$remuneracion->salario}/240) × {$factor} × {$entrada['minutos']} min / 60",
                        'agregado_por' => $usuarioId, 'agregado_en' => now()->toDateTimeString()];
                    $snapshot['horas_extra_regularizadas'][] = ['linea_id' => $lineaId, 'origen' => $entrada['origen'],
                        'asistencia_hora_extra_id' => $entrada['asistencia_hora_extra_id'], 'fecha' => $entrada['fecha'],
                        'tasa' => (string) $entrada['tasa'], 'minutos' => (int) $entrada['minutos'], 'monto' => $monto,
                        'motivo' => $entrada['motivo'] ?? null, 'registrado_por' => $usuarioId, 'registrado_en' => now()->toDateTimeString()];
                }
                // Honorarios conserva la retención del recibo original, igual que
                // CalcularReciboHonorarios: los adicionales HE no cambian su base.
                if (! $esHonorarios) {
                    $this->recalcularAfpEssalud($detalle, $snapshot, $delta);
                    $this->recalcularRentaQuinta($detalle, $snapshot, $delta);
                    $this->recalcularProvisiones($detalle, $snapshot, $delta);
                }
                $this->guardarSnapshotYDiferencias($detalle, $snapshot);
            }
            return $this->cargar($item);
        });
    }

    public function crearConHorasExtra(Empresa $empresa, CicloRemunerativo $ciclo, array $detectadas, array $manuales, string $motivo, int $usuarioId): PlanillaComplementaria
    {
        return DB::transaction(function () use ($empresa, $ciclo, $detectadas, $manuales, $motivo, $usuarioId) {
            $this->verificar($empresa, $ciclo);
            if ($ciclo->estado !== 'pagado') {
                throw ValidationException::withMessages(['estado' => 'Las horas extra solo se regularizan sobre un ciclo pagado.']);
            }

            $pendientes = collect($this->horasExtraPendientes($empresa, $ciclo)['horas'])->keyBy('id');
            $boletaIds = collect($detectadas)->map(fn ($seleccion) => $pendientes->get((int) $seleccion['hora_extra_id'])['boleta_id'] ?? null)
                ->concat(collect($manuales)->pluck('boleta_id'))->filter()->unique()->values()->all();
            if ($boletaIds === []) {
                throw ValidationException::withMessages(['horas_extra' => 'Selecciona o registra al menos una hora extra pendiente.']);
            }

            $borradores = PlanillaComplementariaDetalle::whereIn('boleta_original_id', $boletaIds)
                ->whereHas('complementaria', fn ($q) => $q
                    ->where('ciclo_id', $ciclo->id)
                    ->where('estado', 'calculada'))
                ->pluck('planilla_complementaria_id')
                ->unique()
                ->values();

            if ($borradores->count() > 1) {
                throw ValidationException::withMessages(['horas_extra' => 'Los colaboradores seleccionados pertenecen a borradores distintos. Agrégalos por separado a cada regularización.']);
            }

            // Un documento aprobado nunca se modifica, pero tampoco bloquea una
            // regularización posterior. Si ya existe un borrador calculado para
            // el colaborador, las horas se incorporan automáticamente en él.
            $item = $borradores->isNotEmpty()
                ? PlanillaComplementaria::whereKey($borradores->first())->lockForUpdate()->firstOrFail()
                : PlanillaComplementaria::create([
                    'ciclo_id' => $ciclo->id, 'empresa_id' => $empresa->id,
                    'nombre' => 'Pago de horas extra '.$ciclo->nombre.' '.now()->format('Ymd-His'),
                    'motivo' => $motivo, 'estado' => 'calculada', 'creado_por' => $usuarioId,
                ]);

            $boletasYaIncluidas = $item->detalles()->whereIn('boleta_original_id', $boletaIds)
                ->pluck('boleta_original_id');
            $boletasFaltantes = collect($boletaIds)->diff($boletasYaIncluidas)->values()->all();
            if ($boletasFaltantes !== []) {
                $this->agregarColaboradores($empresa, $item, $boletasFaltantes);
            }

            return $this->agregarHorasExtra($empresa, $item, $detectadas, $manuales, $usuarioId);
        });
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
            ->whereHas('complementaria', fn ($q) => $q->where('ciclo_id', $ciclo->id)->where('estado', 'calculada'))
            ->exists();
        if ($pendienteExistente) {
            throw ValidationException::withMessages([
                'colaboradores' => 'Uno de los colaboradores ya tiene un borrador de complementaria sin aprobar. Apruébalo o elimínalo antes de generar otra.',
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
            ->whereHas('complementaria', fn ($q) => $q->where('ciclo_id', $ciclo->id)->where('estado', 'calculada'))
            ->exists();
        if ($pendienteExistente) {
            throw ValidationException::withMessages([
                'colaboradores' => 'Uno de los colaboradores ya tiene un borrador de complementaria sin aprobar. Apruébalo o elimínalo antes de generar otra.',
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
        $this->limpiarMarcasHeredadas($detalle);
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

            // afecta_renta_5ta se evalúa independiente de es_remunerativo_laboral:
            // BONO_NO_REMUNERATIVO es el caso real (es_remunerativo_laboral=false,
            // afecta_renta_5ta=true en el seeder) — un bono no remunerativo no
            // aporta a AFP/EsSalud/CTS pero sí tributa renta de 5ta.
            if ($bloque === 'ingresos' && ! $esHonorarios) {
                if ($concepto->es_remunerativo_laboral) {
                    $this->recalcularAfpEssalud($detalle, $snapshot, $montoRedondeado);
                    $this->recalcularProvisiones($detalle, $snapshot, $montoRedondeado);
                }
                if ($concepto->afecta_renta_5ta) {
                    $this->recalcularRentaQuinta($detalle, $snapshot, $montoRedondeado);
                }
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
        $this->limpiarMarcasHeredadas($detalle);
        if (! empty($detalle->calculo_snapshot['bono_asistencia_gerencia'])) {
            throw ValidationException::withMessages(['detalle' => 'Para corregir un bono importado, elimina su complementaria calculada y vuelve a importar el Excel aprobado.']);
        }
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

            $snapshot['horas_extra_regularizadas'] = collect($snapshot['horas_extra_regularizadas'] ?? [])
                ->reject(fn (array $hora) => ($hora['linea_id'] ?? null) === $lineaId)
                ->values()->all();

            if ($bloque === 'ingresos') {
                // $detalle puede venir de cargar() (colaborador con columnas
                // restringidas, sin empresa_id) cuando el caller encadena
                // varias operaciones sobre el mismo objeto en memoria en vez
                // de volver a resolverlo por route model binding — recargar
                // aquí evita un empresa_id nulo en recalcularAfpEssalud().
                $detalle->load(['colaborador.empresa', 'boletaOriginal.ciclo']);
                $colaborador = $detalle->colaborador;
                $esHonorarios = $colaborador->tipo_contrato === 'locacion_servicios' || $colaborador->regimen_laboral === 'Locacion de Servicios';
                $concepto = ConceptoRemuneracion::where('codigo', $linea['codigo'])->first();
                // Mismo desacople que agregarConcepto(): afecta_renta_5ta no
                // depende de es_remunerativo_laboral.
                if (! $esHonorarios) {
                    if ($concepto?->es_remunerativo_laboral) {
                        $this->recalcularAfpEssalud($detalle, $snapshot, -1 * (float) $linea['monto']);
                        $this->recalcularProvisiones($detalle, $snapshot, -1 * (float) $linea['monto']);
                    }
                    if ($concepto?->afecta_renta_5ta) {
                        $this->recalcularRentaQuinta($detalle, $snapshot, -1 * (float) $linea['monto']);
                    }
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
     * Repara borradores creados antes de que agregarColaboradores() limpiara
     * las marcas del feriado/descanso pagado usado como base. Una diferencia
     * cero demuestra que el detalle actual todavía no tiene una operación
     * propia; solo en ese caso se retiran las marcas heredadas.
     */
    private function limpiarMarcasHeredadas(PlanillaComplementariaDetalle $detalle): void
    {
        if (round((float) $detalle->diferencia_neta, 2) !== 0.0) {
            return;
        }

        $snapshot = $detalle->calculo_snapshot;
        if (empty($snapshot['descansos_semanales']) && empty($snapshot['feriado_regularizado'])) {
            return;
        }

        unset($snapshot['descansos_semanales'], $snapshot['feriado_regularizado']);
        $detalle->update(['calculo_snapshot' => $snapshot]);
        $detalle->setAttribute('calculo_snapshot', $snapshot);
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
    public function agregarBonoHonorariosGerencia(Empresa $empresa, PlanillaComplementariaDetalle $detalle, float $monto, string $motivo, int $usuarioId): void
    {
        $this->verificarItem($empresa, $detalle->complementaria);
        if ($detalle->complementaria->estado !== 'calculada' || $detalle->boletaOriginal->regimen_laboral_snapshot !== 'Locacion de Servicios' || $monto <= 0) {
            throw ValidationException::withMessages(['bono' => 'El adicional requiere honorarios y una complementaria calculada.']);
        }
        $detalle->load(['colaborador', 'boletaOriginal.ciclo']);
        $parametros = ParametrosVigentesResolver::paraHonorarios($empresa, $detalle->boletaOriginal->ciclo->fecha_corte_asistencia->toDateString());
        $snapshot = $detalle->calculo_snapshot;
        $base = (float) collect($snapshot['ingresos'])->where('codigo', 'HONORARIO_BRUTO')->sum('monto');
        $retencion = fn ($valor) => $detalle->colaborador->tiene_suspension_renta_4ta || $valor <= $parametros['umbral_retencion_4ta']
            ? 0 : round($valor * $parametros['tasa_retencion_4ta'], 2);
        $adicionalRetencion = max(0, round($retencion($base + $monto) - $retencion($base), 2));
        $snapshot['ingresos'][] = ['id' => (string) Str::uuid(), 'codigo' => 'HONORARIO_BRUTO', 'monto' => round($monto, 2),
            'formula_texto' => 'Bono por asistencia aprobado por Gerencia: '.$motivo,
            'motivo' => $motivo, 'agregado_por' => $usuarioId, 'agregado_en' => now()->toDateTimeString()];
        if ($adicionalRetencion > 0) {
            $indice = collect($snapshot['egresos'])->search(fn ($l) => $l['codigo'] === 'RETENCION_RENTA_4TA');
            if ($indice === false) {
                $snapshot['egresos'][] = ['codigo' => 'RETENCION_RENTA_4TA', 'monto' => $adicionalRetencion,
                    'base_utilizada' => $base + $monto, 'tasa_aplicada' => $parametros['tasa_retencion_4ta']];
            } else {
                $snapshot['egresos'][$indice]['monto'] = round($snapshot['egresos'][$indice]['monto'] + $adicionalRetencion, 2);
                $snapshot['egresos'][$indice]['base_utilizada'] = $base + $monto;
            }
        }
        $this->guardarSnapshotYDiferencias($detalle, $snapshot);
    }

    private function recalcularAfpEssalud(PlanillaComplementariaDetalle $detalle, array &$snapshot, float $deltaBase): void
    {
        // cargar() optimiza la respuesta del modal seleccionando solo columnas
        // de presentación del colaborador. Si ese mismo objeto se reutiliza
        // para agregar un concepto, empresa_id/AFP/tipo_comision pueden no
        // estar hidratados. Recargar las relaciones completas evita calcular
        // con datos parciales (o fallar intentando resolver los parámetros).
        $detalle->load(['colaborador.empresa', 'boletaOriginal.ciclo']);
        $colaborador = $this->colaboradorPrevisionalParaRecalculo($detalle, $snapshot);
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

    /**
     * Compatibilidad con boletas antiguas que guardaron el nombre de la AFP
     * en sistema_previsional, pero dejaron afp_id/tipo_comision vacíos. Esas
     * boletas sí descontaron el aporte obligatorio y luego fallaban con un
     * RuntimeException al agregar o retirar un ingreso remunerativo de una
     * complementaria.
     *
     * Se trabaja sobre una copia en memoria: no corrige silenciosamente la
     * ficha actual ni modifica la boleta pagada. La AFP se resuelve por su
     * clave y el tipo se infiere de la línea histórica de comisión: una tasa
     * positiva corresponde a flujo; cero, al componente flujo de mixta.
     */
    private function colaboradorPrevisionalParaRecalculo(PlanillaComplementariaDetalle $detalle, array $snapshot)
    {
        $colaborador = $detalle->colaborador->replicate();
        $colaborador->setRelation('empresa', $detalle->colaborador->empresa);

        if ($colaborador->sistema_previsional === 'onp') {
            return $colaborador;
        }

        $condicion = ColaboradorCondicionLaboral::vigenteEn(
            $detalle->colaborador_id,
            $detalle->boletaOriginal->ciclo->fecha_corte_asistencia->toDateString(),
        );

        $colaborador->sistema_previsional = $condicion?->sistema_previsional ?: $colaborador->sistema_previsional;
        $colaborador->afp_id = $condicion?->afp_id ?: $colaborador->afp_id;
        $colaborador->tipo_comision = $condicion?->tipo_comision ?: $colaborador->tipo_comision;

        if (! $colaborador->afp_id && filled($colaborador->sistema_previsional)) {
            $colaborador->afp_id = Afp::where('clave', $colaborador->sistema_previsional)->value('id');
        }

        if (! in_array($colaborador->tipo_comision, ['flujo', 'mixta'], true)) {
            $lineaComision = collect($snapshot['egresos'] ?? [])->firstWhere('codigo', 'AFP_COMISION');
            $colaborador->tipo_comision = (float) ($lineaComision['tasa_aplicada'] ?? 0) > 0
                ? 'flujo'
                : 'mixta';
        }

        return $colaborador;
    }

    private function recalcularRentaQuinta(PlanillaComplementariaDetalle $detalle, array &$snapshot, float $deltaBase): void
    {
        $colaborador = $detalle->colaborador;
        $ciclo = $detalle->boletaOriginal->ciclo;
        $fechaCorte = $ciclo->fecha_corte_asistencia->toDateString();
        $parametros = ParametrosVigentesResolver::paraRegimen($colaborador->empresa, $colaborador->regimen_laboral ?: 'General', $fechaCorte);
        $lineaActual = collect($snapshot['egresos'] ?? [])->firstWhere('codigo', 'RENTA_5TA');
        // El snapshot ya contiene el ingreso agregado (o eliminado).
        $baseActual = (float) ($lineaActual['base_utilizada'] ?? (collect($snapshot['ingresos'] ?? [])->sum('monto') - $deltaBase));
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

    public function reabrir(Empresa $empresa, PlanillaComplementaria $item, int $usuarioId, string $motivo): PlanillaComplementaria
    {
        return DB::transaction(function () use ($empresa, $item, $usuarioId, $motivo) {
            $item = PlanillaComplementaria::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->verificarItem($empresa, $item);

            if ($item->estado !== 'aprobada') {
                throw ValidationException::withMessages([
                    'estado' => 'Solo se puede reabrir una complementaria aprobada que todavía no fue pagada.',
                ]);
            }

            $reapertura = [
                'motivo' => trim($motivo),
                'reabierto_por' => $usuarioId,
                'reabierto_at' => now()->toDateTimeString(),
                'aprobado_por_anterior' => $item->aprobado_por,
                'aprobado_at_anterior' => $item->aprobado_at?->toDateTimeString(),
            ];

            foreach ($item->detalles()->lockForUpdate()->get() as $detalle) {
                $snapshot = $detalle->calculo_snapshot;
                $snapshot['reaperturas'][] = $reapertura;
                $detalle->update(['calculo_snapshot' => $snapshot]);
            }

            $item->update([
                'estado' => 'calculada',
                'aprobado_por' => null,
                'aprobado_at' => null,
            ]);

            return $this->cargar($item);
        });
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

        $boletas = $this->detallesParaPago($item, $subtipo);
        if ($boletas->isEmpty()) {
            throw ValidationException::withMessages(['detalles' => 'No existen diferencias positivas para la categoría seleccionada.']);
        }

        return $boletas;
    }

    /**
     * Igual que boletasDePago() pero combinando varios reintegros aprobados
     * en una sola colección — para generar un único archivo bancario en vez
     * de uno por reintegro. Cada complementaria debe pertenecer a la empresa
     * autorizada y estar 'aprobada' (misma exigencia que la exportación
     * individual, ver detallesParaPago()); si alguna no cumple, se rechaza
     * el lote completo antes de generar nada — nunca un archivo parcial.
     *
     * @param array<int, int> $itemIds
     */
    public function boletasDePagoMasivo(Empresa $empresa, array $itemIds, string $subtipo): Collection
    {
        $items = PlanillaComplementaria::whereIn('id', $itemIds)->get();
        if ($items->count() !== count(array_unique($itemIds))) {
            throw ValidationException::withMessages(['complementaria_ids' => 'Selecciona reintegros válidos.']);
        }
        foreach ($items as $item) {
            $this->verificarItem($empresa, $item);
            if ($item->estado !== 'aprobada') {
                throw ValidationException::withMessages(['estado' => "\"{$item->nombre}\" todavía no está aprobada — apruébala antes de incluirla en el archivo consolidado."]);
            }
        }

        $boletas = $items->flatMap(fn ($item) => $this->detallesParaPago($item, $subtipo))->values();
        if ($boletas->isEmpty()) {
            throw ValidationException::withMessages(['detalles' => 'No existen diferencias positivas para la categoría seleccionada en los reintegros elegidos.']);
        }

        return $boletas;
    }

    /** Detalles con diferencia positiva de UN reintegro, filtrados por categoría (4ta/5ta) y adaptados a la forma que esperan los exportadores bancarios. */
    private function detallesParaPago(PlanillaComplementaria $item, string $subtipo): Collection
    {
        $esCuarta = $subtipo === '4';

        return $item->detalles()->where('diferencia_neta', '>', 0)
            ->with(['boletaOriginal.colaborador'])->get()
            ->filter(fn ($d) => ($d->boletaOriginal->regimen_laboral_snapshot === 'Locacion de Servicios') === $esCuarta)
            ->map(function ($detalle) {
                $boleta = $detalle->boletaOriginal->replicate();
                $colaborador = $detalle->boletaOriginal->colaborador;
                $bancoId = $detalle->banco_id ?: $colaborador?->banco_id;
                $boleta->id = $detalle->boleta_original_id;
                $boleta->neto_a_pagar = $detalle->diferencia_neta;
                $boleta->setRelation('colaborador', $colaborador);
                $datosPago = new BoletaDatosPago([
                    // La complementaria conserva su snapshot original. Solo
                    // completamos campos que nacieron vacíos con los datos
                    // bancarios actuales, por ejemplo un CCI corregido luego
                    // de aprobar el reintegro; nunca reemplazamos un valor que
                    // ya estaba congelado.
                    'banco_id' => $bancoId,
                    'tipo_cuenta_snapshot' => $detalle->tipo_cuenta_snapshot ?: $colaborador?->tipo_cuenta,
                    'moneda_snapshot' => $detalle->moneda_snapshot ?: $colaborador?->moneda_cuenta,
                    'numero_cuenta_snapshot' => $detalle->numero_cuenta_snapshot ?: $colaborador?->numero_cuenta,
                    'cci_snapshot' => $detalle->cci_snapshot ?: $colaborador?->cci,
                    'fecha_snapshot' => $detalle->created_at,
                ]);
                $datosPago->setRelation('banco', \App\Modules\Configuracion\Models\Banco::find($bancoId));
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
        try {
            return BbvaNetCashTxtExporter::generar($cuenta, $subtipo, 'COMPLEMENTARIA '.$item->id, $this->boletasDePago($empresa, $item, $subtipo));
        } catch (BbvaNetCashExportException $e) {
            throw ValidationException::withMessages(['bbva_netcash' => $e->getMessage()]);
        }
    }

    /** @param array<int, int> $itemIds */
    public function exportarBcpMasivo(Empresa $empresa, array $itemIds, $cuenta, string $fechaProceso, string $subtipo): string
    {
        return TelecreditoBcpTxtExporter::generar($cuenta, str_replace('-', '', $fechaProceso), $subtipo, 'REINTEGROS '.now()->format('Ymd-His'), $this->boletasDePagoMasivo($empresa, $itemIds, $subtipo));
    }

    /** @param array<int, int> $itemIds */
    public function exportarBbvaMasivo(Empresa $empresa, array $itemIds, $cuenta, string $subtipo): string
    {
        try {
            return BbvaNetCashTxtExporter::generar($cuenta, $subtipo, 'REINTEGROS '.now()->format('Ymd-His'), $this->boletasDePagoMasivo($empresa, $itemIds, $subtipo));
        } catch (BbvaNetCashExportException $e) {
            throw ValidationException::withMessages(['bbva_netcash' => $e->getMessage()]);
        }
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
