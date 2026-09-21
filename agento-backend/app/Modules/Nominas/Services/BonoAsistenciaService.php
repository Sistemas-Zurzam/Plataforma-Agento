<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Asistencia\Models\AsistenciaResultadoDiario;
use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Domain\BonoAsistenciaCalculator;
use App\Modules\Nominas\Infrastructure\BonoAsistencia\BonoAsistenciaXlsxReader;
use App\Modules\Nominas\Infrastructure\BonoAsistencia\Export\BonoAsistenciaExcelExporter;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BonoAsistenciaLote;
use App\Modules\Nominas\Models\BonoAsistenciaLoteDetalle;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ColaboradorConceptoPeriodo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\PlanillaComplementaria;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;
use App\Modules\Personas\Models\Colaborador;
use App\Modules\Personas\Models\ColaboradorRemuneracion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bono de Asistencia (política Livex, personal comercial) — ver
 * App\Modules\Nominas\Domain\BonoAsistenciaCalculator para la regla de
 * negocio pura (porcentaje/monto) y las migraciones
 * 2026_09_08_000127/128/129 para el esquema completo.
 *
 * Flujo: generar() (solo asistencia del ciclo, la meta comercial TODAVÍA no
 * se conoce → propuesta conservadora; el estado del ciclo no importa, puede
 * generarse antes o después de pagarlo) → exportarExcel() (se envía a Livex,
 * fuera de Agento) → importarExcel() (Livex responde meta comercial,
 * aprobación y correcciones de faltas) → aplicar().
 *
 * aplicar() resuelve cada colaborador aprobado según el estado de SU boleta
 * en el ciclo: si todavía no está pagada, escribe en ColaboradorConceptoPeriodo
 * (que CalcularBoletaColaborador::conceptosDelPeriodo() recoge automáticamente
 * en el próximo recálculo del ciclo — no se dispara ningún recálculo desde
 * aquí). Si la boleta YA está pagada (el ciclo se cerró/pagó mientras se
 * esperaba la revisión externa de Livex), no puede reabrirse ni recalcularse
 * (CicloRemunerativoService::reabrir() lo rechaza), así que se usa el mismo
 * mecanismo que cualquier otro ajuste sobre una boleta pagada: una
 * PlanillaComplementaria nueva vía PlanillaComplementariaService::
 * agregarColaboradores()/agregarConcepto() — igual que
 * PlanillaComplementariaService::aplicarBonoPorAsistencia() (bono simple de
 * días asistidos, un problema distinto). Un mismo lote puede repartirse entre
 * ambos caminos, colaborador por colaborador.
 */
class BonoAsistenciaService
{
    public function __construct(
        private readonly PlanillaComplementariaService $complementarias,
    ) {}

    public function generar(
        Empresa $empresa,
        CicloRemunerativo $ciclo,
        int $conceptoId,
        ?int $conceptoDefinicionId,
        string $nombre,
        ?string $motivo,
        int $usuarioId,
    ): BonoAsistenciaLote {
        $this->verificar($empresa, $ciclo);

        // Sin restricción de estado del ciclo a propósito: el conteo de
        // faltas/tardanzas y el bono_asistencia_base no dependen de si el
        // ciclo ya se cerró o pagó — aplicar() es quien decide, colaborador
        // por colaborador, si corresponde ColaboradorConceptoPeriodo (boleta
        // todavía no pagada) o Planilla Complementaria (boleta ya pagada).

        // Un ciclo no puede tener dos lotes activos a la vez: aplicar() no
        // sabe nada de otros lotes, así que dos lotes aprobados por
        // separado duplicarían el ColaboradorConceptoPeriodo del mismo
        // colaborador+concepto+ciclo. Hay que anular el lote existente
        // antes de generar uno nuevo.
        if (BonoAsistenciaLote::where('ciclo_id', $ciclo->id)->where('estado', '!=', 'anulado')->exists()) {
            throw ValidationException::withMessages([
                'ciclo' => 'Ya existe un lote de bono de asistencia activo para este ciclo. Anúlalo antes de generar uno nuevo.',
            ]);
        }

        $concepto = ConceptoRemuneracion::where('id', $conceptoId)->where('activo', true)->firstOrFail();
        if ($concepto->tipo !== 'ingreso') {
            throw ValidationException::withMessages(['concepto_id' => 'El bono de asistencia solo admite conceptos de tipo ingreso.']);
        }

        // Mismo criterio que PlanillaComplementariaController::agregarConcepto()
        // / PlanillaComplementariaService::aplicarBonoPorAsistencia():
        // BONIFICACION/BONO_NO_REMUNERATIVO son demasiado genéricos para
        // Tabla 22 sin una clasificación PLAME concreta.
        $requiereDefinicion = in_array($concepto->codigo, ['BONIFICACION', 'BONO_NO_REMUNERATIVO'], true);
        if ($requiereDefinicion && ! $conceptoDefinicionId) {
            throw ValidationException::withMessages(['concepto_definicion_id' => 'Este concepto requiere seleccionar una clasificación PLAME (Tabla 22).']);
        }
        if (! $requiereDefinicion && $conceptoDefinicionId) {
            throw ValidationException::withMessages(['concepto_definicion_id' => 'Este concepto no admite una clasificación PLAME adicional.']);
        }

        // Mismo criterio que BoletaService::colaboradoresElegibles() (privado,
        // no reutilizable directamente): activo, con vínculo laboral vigente
        // en el rango del ciclo. Un colaborador cesado o inactivo no entra al
        // motor de boletas — si igual se le generara un bono aquí, quedaría
        // huérfano (Livex lo vería aprobado pero nadie lo procesaría).
        $colaboradores = Colaborador::where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->whereDate('fecha_ingreso', '<=', $ciclo->fecha_fin)
            ->where(fn ($query) => $query->whereNull('fecha_cese')->orWhereDate('fecha_cese', '>=', $ciclo->fecha_inicio))
            ->get();

        // N+1 deliberado: mismo patrón ya usado varias veces en
        // PlanillaComplementariaService (ej. agregarHorasExtra()) para
        // resolver "la remuneración vigente a una fecha" por colaborador —
        // no hay forma simple de traer "la última fila por vigencia_desde
        // por colaborador" en una sola consulta portable en MySQL sin
        // funciones de ventana, y la cantidad de colaboradores de una
        // empresa no justifica esa complejidad adicional.
        $elegibles = collect();
        foreach ($colaboradores as $colaborador) {
            $remuneracion = ColaboradorRemuneracion::where('colaborador_id', $colaborador->id)
                ->whereDate('vigencia_desde', '<=', $ciclo->fecha_inicio)
                ->orderByDesc('vigencia_desde')
                ->orderByDesc('id')
                ->first();

            if ($remuneracion !== null && $remuneracion->bono_asistencia_base !== null) {
                $elegibles->put($colaborador->id, [
                    'colaborador' => $colaborador,
                    'bono_base' => (float) $remuneracion->bono_asistencia_base,
                ]);
            }
        }

        if ($elegibles->isEmpty()) {
            throw ValidationException::withMessages([
                'colaboradores' => 'Ningún colaborador de la empresa tiene un bono de asistencia base configurado para este ciclo.',
            ]);
        }

        $colaboradorIds = $elegibles->keys();
        $inicio = $ciclo->fecha_inicio->toDateString();
        $fin = $ciclo->fecha_fin->toDateString();

        // Siempre con empresa_id explícito: AsistenciaResultadoDiario
        // pertenece a otro módulo (Asistencia) — mismo criterio que
        // PlanillaComplementariaService::colaboradoresPorAsistencia().
        $conteoBase = fn () => AsistenciaResultadoDiario::withoutGlobalScopes()
            ->where('empresa_id', $empresa->id)
            ->whereIn('colaborador_id', $colaboradorIds)
            ->whereDate('fecha', '>=', $inicio)
            ->whereDate('fecha', '<=', $fin);

        $justificadas = $conteoBase()->where('estado', 'falta_justificada')
            ->selectRaw('colaborador_id, count(*) as dias')->groupBy('colaborador_id')->pluck('dias', 'colaborador_id');
        // 'falta' = injustificada por defecto (motor automático); 'falta_justificada'
        // es siempre explícita — ver EditarAsistenciaDiaRequest.
        $injustificadas = $conteoBase()->where('estado', 'falta')
            ->selectRaw('colaborador_id, count(*) as dias')->groupBy('colaborador_id')->pluck('dias', 'colaborador_id');
        // Un día cuenta como tardanza cuando minutos_tardanza > 0 — mismo
        // criterio que AsistenciaColaboradorResource.
        $tardanzas = $conteoBase()->where('minutos_tardanza', '>', 0)
            ->selectRaw('colaborador_id, count(*) as dias')->groupBy('colaborador_id')->pluck('dias', 'colaborador_id');

        $calculador = new BonoAsistenciaCalculator;

        return DB::transaction(function () use (
            $empresa, $ciclo, $conceptoId, $conceptoDefinicionId, $nombre, $motivo, $usuarioId,
            $elegibles, $justificadas, $injustificadas, $tardanzas, $calculador,
        ) {
            $lote = BonoAsistenciaLote::create([
                'empresa_id' => $empresa->id,
                'ciclo_id' => $ciclo->id,
                'nombre' => $nombre,
                'motivo' => $motivo,
                'estado' => 'borrador',
                'concepto_id' => $conceptoId,
                'concepto_definicion_id' => $conceptoDefinicionId,
                'creado_por' => $usuarioId,
            ]);

            foreach ($elegibles as $colaboradorId => $info) {
                $diasJustificadas = (int) ($justificadas[$colaboradorId] ?? 0);
                $diasInjustificadas = (int) ($injustificadas[$colaboradorId] ?? 0);
                $diasTardanza = (int) ($tardanzas[$colaboradorId] ?? 0);

                // metaComercialCumplida: null a propósito — todavía no se
                // conoce (recién llega al reimportar el Excel de Livex), así
                // que se propone SIEMPRE el porcentaje más conservador.
                $resultado = $calculador->calcular($diasJustificadas, $diasInjustificadas, $diasTardanza, $info['bono_base'], null);

                BonoAsistenciaLoteDetalle::create([
                    'bono_asistencia_lote_id' => $lote->id,
                    'colaborador_id' => $colaboradorId,
                    'documento_snapshot' => (string) $info['colaborador']->numero_documento,
                    'colaborador_nombre_snapshot' => trim($info['colaborador']->nombres.' '.$info['colaborador']->apellidos),
                    'dias_falta_justificada' => $diasJustificadas,
                    'dias_falta_injustificada' => $diasInjustificadas,
                    'tardanzas' => $diasTardanza,
                    'bono_base' => $info['bono_base'],
                    'porcentaje_propuesto' => $resultado['porcentaje'],
                    'monto_propuesto' => $resultado['monto'],
                ]);
            }

            return $lote->load('detalles.colaborador');
        });
    }

    /**
     * Genera el .xlsx para enviar a Livex y marca el lote como 'exportado'.
     *
     * DESVIACIÓN DEL CONTRATO: se agregó `int $usuarioId` (el contrato
     * original no lo incluía en la firma), porque el propio contrato exige
     * completar `exportado_por` — imposible sin conocer al usuario que
     * exporta. `exportado_por` es nullable en la migración, pero dejarlo
     * vacío rompería la trazabilidad que esa columna existe para dar.
     */
    public function exportarExcel(Empresa $empresa, BonoAsistenciaLote $lote, int $usuarioId): string
    {
        $this->verificarLote($empresa, $lote);
        $lote->loadMissing(['detalles', 'ciclo']);

        $contenido = BonoAsistenciaExcelExporter::generar($lote);
        $nombre = sprintf('bono-asistencia-%s-%s.xlsx', Str::slug($lote->ciclo->nombre), now()->format('Y_m_d'));

        $lote->update([
            'estado' => 'exportado',
            'exportado_en' => now(),
            'exportado_por' => $usuarioId,
            'archivo_exportado_nombre' => $nombre,
        ]);

        return $contenido;
    }

    /**
     * @return array{coincidencias: int, sin_coincidencia: array<int, string>, lote: BonoAsistenciaLote}
     */
    public function importarExcel(Empresa $empresa, BonoAsistenciaLote $lote, UploadedFile $archivo, int $usuarioId): array
    {
        $this->verificarLote($empresa, $lote);

        // Se puede reimportar más de una vez antes de aplicar (Livex puede
        // mandar una versión corregida del Excel).
        if (! in_array($lote->estado, ['exportado', 'revisado'], true)) {
            throw ValidationException::withMessages([
                'estado' => 'El lote debe estar exportado antes de reimportar el Excel de Livex.',
            ]);
        }

        $filas = (new BonoAsistenciaXlsxReader)->leer($archivo->getRealPath());
        $detalles = $lote->detalles()->get()->keyBy(fn (BonoAsistenciaLoteDetalle $d) => trim((string) $d->documento_snapshot));

        // Es una segunda (o más) reimportación si el lote ya pasó por aquí
        // antes — determina si una celda en blanco debe interpretarse como
        // "todavía no decidido" (primera vez, "No" por defecto) o "sin
        // cambios, respeta lo que ya se guardó" (reimportación posterior).
        $esReimportacion = $lote->estado === 'revisado';

        $coincidencias = 0;
        $sinCoincidencia = [];
        $documentosVistos = [];
        $duplicados = [];
        $calculador = new BonoAsistenciaCalculator;

        DB::transaction(function () use ($filas, $detalles, $calculador, &$coincidencias, &$sinCoincidencia, &$documentosVistos, &$duplicados, $lote, $usuarioId, $archivo, $esReimportacion) {
            foreach ($filas as $fila) {
                $documento = trim((string) $fila['documento']);
                $detalle = $detalles->get($documento);

                if (! $detalle) {
                    $sinCoincidencia[] = $documento;

                    continue;
                }

                // Un DNI repetido en el Excel de Livex (copiar/pegar, error
                // humano) es ambiguo — no hay forma segura de decidir cuál
                // fila vale. Se ignoran TODAS las filas repetidas de ese DNI
                // (se conserva lo que ya tenía el detalle) y se reporta, en
                // vez de aplicar "la última que gana" silenciosamente.
                if (isset($documentosVistos[$documento])) {
                    $duplicados[$documento] = true;

                    continue;
                }
                $documentosVistos[$documento] = true;

                $coincidencias++;

                $metaCumplida = $this->valorConPreservacion($fila['meta_comercial_cumplida'], $detalle->meta_comercial_cumplida, $esReimportacion);
                $aprobado = $this->valorConPreservacion($fila['aprobado'], $detalle->aprobado, $esReimportacion);

                // Solo se admite la corrección de días de falta INJUSTIFICADA
                // (única columna que expone el Excel exportado — ver
                // BonoAsistenciaExcelExporter). El esquema también reserva
                // dias_falta_justificada_livex para una futura columna
                // simétrica, pero el contrato actual del Excel no la pide,
                // así que se deja siempre null.
                $correccion = $fila['correccion_dias_falta_injustificada'];
                $celdaCorreccionEnBlanco = $correccion === null || trim((string) $correccion) === '';
                $diasInjustificadaLivex = match (true) {
                    $celdaCorreccionEnBlanco && $esReimportacion => $detalle->dias_falta_injustificada_livex,
                    $celdaCorreccionEnBlanco => null,
                    is_numeric($correccion) && (int) $correccion <= (int) $detalle->dias_falta_injustificada => (int) $correccion,
                    default => null,
                };

                $resultado = $calculador->calcular(
                    (int) $detalle->dias_falta_justificada,
                    $diasInjustificadaLivex ?? (int) $detalle->dias_falta_injustificada,
                    (int) $detalle->tardanzas,
                    (float) $detalle->bono_base,
                    $metaCumplida,
                );

                $detalle->update([
                    'meta_comercial_cumplida' => $metaCumplida,
                    'aprobado' => $aprobado,
                    'observacion_livex' => $fila['observacion'] ?: $detalle->observacion_livex,
                    'dias_falta_injustificada_livex' => $diasInjustificadaLivex,
                    'porcentaje_final' => $resultado['porcentaje'],
                    'monto_final' => $resultado['monto'],
                ]);
            }

            $lote->update([
                'estado' => 'revisado',
                'revisado_en' => now(),
                'revisado_por' => $usuarioId,
                'archivo_importado_nombre' => $archivo->getClientOriginalName(),
            ]);
        });

        return [
            'coincidencias' => $coincidencias,
            'sin_coincidencia' => array_values(array_unique($sinCoincidencia)),
            'duplicados' => array_keys($duplicados),
            'lote' => $lote->fresh()->load('detalles.colaborador'),
        ];
    }

    public function aplicar(Empresa $empresa, BonoAsistenciaLote $lote, int $usuarioId): BonoAsistenciaLote
    {
        $this->verificarLote($empresa, $lote);

        if ($lote->estado === 'aplicado') {
            throw ValidationException::withMessages(['estado' => 'Este lote ya fue aplicado — no puede aplicarse dos veces.']);
        }
        if ($lote->estado !== 'revisado') {
            throw ValidationException::withMessages(['estado' => 'El lote debe estar revisado (Excel de Livex reimportado) antes de aplicarse.']);
        }

        $lote->loadMissing('ciclo');

        return DB::transaction(function () use ($empresa, $lote, $usuarioId) {
            $lote = BonoAsistenciaLote::whereKey($lote->id)->lockForUpdate()->firstOrFail();

            // Reconfirma tras el lock: una segunda llamada concurrente que
            // quedó esperando el lockForUpdate no debe pisar aplicado_por/
            // aplicado_en de la primera (solo audita quién aplicó de verdad).
            if ($lote->estado === 'aplicado') {
                return $lote->fresh()->load(['detalles.colaborador', 'planillaComplementaria']);
            }

            // DESVIACIÓN DEL CONTRATO ORIGINAL: aquí existía un guard que
            // rechazaba aplicar() si el ciclo ya estaba 'cerrado'/'pagado'
            // ("aplicar aquí ya no tendría efecto real en ninguna boleta").
            // Eso ya no es cierto: abajo, cada colaborador aprobado se
            // resuelve según el estado de SU boleta — ColaboradorConceptoPeriodo
            // si todavía no está pagada (como antes), o Planilla
            // Complementaria si ya lo está. Mantener ese guard bloquearía por
            // completo el caso que motivó esta tarea (aplicar sobre un ciclo
            // ya pagado), así que se elimina.
            $ciclo = CicloRemunerativo::findOrFail($lote->ciclo_id);

            // Reclasifica en Asistencia las faltas que Livex corrigió para
            // TODOS los detalles con corrección, sin importar si el
            // colaborador quedó aprobado para el bono — es un dato de
            // asistencia real, independiente de si finalmente cobra o no.
            $detallesConCorreccion = $lote->detalles()
                ->whereNotNull('dias_falta_injustificada_livex')
                ->whereColumn('dias_falta_injustificada_livex', '<', 'dias_falta_injustificada')
                ->get();

            foreach ($detallesConCorreccion as $detalle) {
                $diasAJustificar = (int) $detalle->dias_falta_injustificada - (int) $detalle->dias_falta_injustificada_livex;

                // Aproximación determinista: el Excel de Livex solo trae un
                // conteo agregado de días corregidos, nunca la fecha exacta
                // que decidieron justificar — se reclasifican los N días MÁS
                // RECIENTES con estado 'falta' dentro del rango del ciclo
                // (orden desc por fecha). Es el criterio más razonable
                // posible con la información disponible.
                $idsAReclasificar = AsistenciaResultadoDiario::withoutGlobalScopes()
                    ->where('empresa_id', $empresa->id)
                    ->where('colaborador_id', $detalle->colaborador_id)
                    ->where('estado', 'falta')
                    ->whereDate('fecha', '>=', $ciclo->fecha_inicio)
                    ->whereDate('fecha', '<=', $ciclo->fecha_fin)
                    ->orderByDesc('fecha')
                    ->limit($diasAJustificar)
                    ->pluck('id');

                AsistenciaResultadoDiario::withoutGlobalScopes()
                    ->whereIn('id', $idsAReclasificar)
                    ->update(['estado' => 'falta_justificada']);
            }

            // Defensa adicional fila por fila contra doble aplicación,
            // además del guard de estado de arriba. También excluye los que
            // ya se resolvieron por Planilla Complementaria (ver debajo) —
            // relevante solo si aplicar() se reintenta tras un fallo parcial
            // ajeno a esta transacción (p. ej. un reintento manual).
            $detallesAprobados = $lote->detalles()
                ->where('aprobado', true)
                ->whereNull('colaborador_concepto_periodo_id')
                ->whereNull('planilla_complementaria_detalle_id')
                ->get();

            // Boleta VIGENTE de cada colaborador en este ciclo: determina si
            // ya se pagó (va por Complementaria) o todavía no (sigue el
            // camino de siempre, ColaboradorConceptoPeriodo). Un colaborador
            // sin boleta vigente en el ciclo (aún no se generó) cae también
            // en el camino de ColaboradorConceptoPeriodo: no hay nada que
            // regularizar todavía.
            $boletas = Boleta::where('ciclo_id', $lote->ciclo_id)
                ->whereIn('colaborador_id', $detallesAprobados->pluck('colaborador_id'))
                ->where('es_version_vigente', true)
                ->get()
                ->keyBy('colaborador_id');

            [$detallesPagados, $detallesPendientes] = $detallesAprobados->partition(
                fn (BonoAsistenciaLoteDetalle $detalle) => $boletas->get($detalle->colaborador_id)?->estado === 'pagada'
            );

            foreach ($detallesPendientes as $detalle) {
                $conceptoPeriodo = ColaboradorConceptoPeriodo::create([
                    'empresa_id' => $empresa->id,
                    'ciclo_id' => $lote->ciclo_id,
                    'colaborador_id' => $detalle->colaborador_id,
                    'concepto_id' => $lote->concepto_id,
                    'concepto_definicion_id' => $lote->concepto_definicion_id,
                    'monto' => $detalle->monto_final,
                    'motivo' => "Bono de asistencia — {$lote->nombre}",
                    'creado_por' => $usuarioId,
                ]);

                $detalle->update(['colaborador_concepto_periodo_id' => $conceptoPeriodo->id]);
            }

            if ($detallesPagados->isNotEmpty()) {
                // La boleta de este colaborador ya está pagada — un ciclo
                // pagado no puede reabrirse ni recalcularse
                // (CicloRemunerativoService::reabrir() ya lo rechaza), así
                // que se usa EXACTAMENTE el mismo mecanismo que cualquier
                // otro ajuste sobre una boleta ya pagada (reintegros, horas
                // extra, feriados, bono simple de días asistidos): una
                // Planilla Complementaria nueva.
                $complementariaNueva = PlanillaComplementaria::create([
                    'ciclo_id' => $lote->ciclo_id,
                    'empresa_id' => $empresa->id,
                    'nombre' => "Bono de asistencia — {$lote->nombre}",
                    'motivo' => $lote->motivo,
                    'estado' => 'calculada',
                    'creado_por' => $usuarioId,
                ]);

                $boletaIdsDeEsteGrupo = $detallesPagados
                    ->map(fn (BonoAsistenciaLoteDetalle $detalle) => $boletas->get($detalle->colaborador_id)?->id)
                    ->filter()
                    ->values()
                    ->all();

                // agregarColaboradores()/agregarConcepto() pueden lanzar
                // ValidationException (ej. un colaborador ya está en otra
                // complementaria pendiente, o es un locador que no admite
                // este tipo de ingreso) — se deja propagar a propósito: es
                // un fallo esperado que aborta toda la transacción, igual
                // que cualquier otro error dentro de aplicar() hoy. El
                // usuario debe resolver el conflicto (aprobar/eliminar el
                // borrador en conflicto) antes de reintentar.
                $this->complementarias->agregarColaboradores($empresa, $complementariaNueva, $boletaIdsDeEsteGrupo);

                foreach ($detallesPagados as $detalle) {
                    $boletaId = $boletas->get($detalle->colaborador_id)?->id;

                    $detalleComplementaria = PlanillaComplementariaDetalle::where('planilla_complementaria_id', $complementariaNueva->id)
                        ->where('boleta_original_id', $boletaId)
                        ->firstOrFail();

                    $this->complementarias->agregarConcepto(
                        $empresa,
                        $detalleComplementaria,
                        $lote->concepto_id,
                        $lote->concepto_definicion_id,
                        (float) $detalle->monto_final,
                        "Bono de asistencia — {$lote->nombre}",
                        $usuarioId,
                    );

                    $detalle->update(['planilla_complementaria_detalle_id' => $detalleComplementaria->id]);
                }

                $lote->planilla_complementaria_id = $complementariaNueva->id;
            }

            $lote->update([
                'estado' => 'aplicado',
                'aplicado_en' => now(),
                'aplicado_por' => $usuarioId,
                'planilla_complementaria_id' => $lote->planilla_complementaria_id,
            ]);

            return $lote->fresh()->load(['detalles.colaborador', 'planillaComplementaria']);
        });
    }

    public function anular(Empresa $empresa, BonoAsistenciaLote $lote, string $motivo, int $usuarioId): void
    {
        $this->verificarLote($empresa, $lote);

        // Mismo criterio que PlanillaComplementaria: un documento ya
        // aplicado (dinero ya reflejado en colaborador_conceptos_periodo)
        // no puede anularse por aquí.
        if ($lote->estado === 'aplicado') {
            throw ValidationException::withMessages(['estado' => 'Un lote ya aplicado no puede anularse.']);
        }

        $lote->update([
            'estado' => 'anulado',
            'anulado_en' => now(),
            'anulado_por' => $usuarioId,
            'motivo_anulacion' => $motivo,
        ]);
    }

    private function verificar(Empresa $empresa, CicloRemunerativo $ciclo): void
    {
        if ($ciclo->empresa_id !== $empresa->id) {
            throw new AuthorizationException('El ciclo no pertenece a la empresa autorizada.');
        }
    }

    private function verificarLote(Empresa $empresa, BonoAsistenciaLote $lote): void
    {
        if ($lote->empresa_id !== $empresa->id) {
            throw new AuthorizationException('El lote de bono de asistencia no pertenece a la empresa autorizada.');
        }
    }

    /**
     * En la primera importación (lote 'exportado'), una celda en blanco es
     * "todavía no decidido" → false (nunca se asume una aprobación que
     * Livex no marcó explícitamente). En una REIMPORTACIÓN (lote ya
     * 'revisado'), una celda en blanco significa "sin cambios" — se
     * conserva la decisión ya guardada, para no perder una aprobación
     * previa solo porque Livex no repitió la marca en una versión corregida
     * del Excel que solo tocaba otras filas.
     */
    private function valorConPreservacion(mixed $crudo, ?bool $valorAnterior, bool $esReimportacion): bool
    {
        $enBlanco = $crudo === null || trim((string) $crudo) === '';
        if ($enBlanco && $esReimportacion && $valorAnterior !== null) {
            return $valorAnterior;
        }

        return $this->normalizarBooleano($crudo);
    }

    /**
     * 'Si'/'Sí'/'SI'/'X'/1/true → true; 'No'/vacío/cualquier otro valor →
     * false. Nunca retorna null: si Livex deja la columna en blanco, el
     * contrato del import interpreta eso como "no" (nunca se asume una
     * aprobación o un cumplimiento de meta que Livex no marcó
     * explícitamente).
     */
    private function normalizarBooleano(mixed $valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }
        if (is_numeric($valor)) {
            return (float) $valor === 1.0;
        }

        $texto = mb_strtolower(trim((string) ($valor ?? '')));

        return in_array($texto, ['si', 'sí', 'x', 'true'], true);
    }
}
