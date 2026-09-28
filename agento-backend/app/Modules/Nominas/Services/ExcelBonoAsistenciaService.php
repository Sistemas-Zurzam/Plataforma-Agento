<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\CicloRemunerativo;
use App\Modules\Nominas\Models\ConceptoRemuneracion;
use App\Modules\Nominas\Models\PlanillaComplementaria;
use App\Modules\Nominas\Models\PlanillaComplementariaDetalle;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Protection;

class ExcelBonoAsistenciaService
{
    public const CABECERAS = ['ID', 'Empresa', 'Mes', 'Colaborador', 'Documento', 'Días efectivos', 'Descansos', 'Tardanzas',
        'Faltas justificadas (validar)', 'Faltas injustificadas', 'Sin huellero completo', 'Incidencias turno', 'Días sin clasificar',
        'Días sin resultado', 'Evaluación preliminar', 'Bono base', '% inicial', 'Monto inicial provisional', '% recuperable',
        'Monto recuperable por meta', 'Observaciones', 'Decisión Gerencia', 'Cumplió meta', 'Monto aprobado', 'Responsable',
        'Fecha aprobación (AAAA-MM-DD)', 'Sustento Gerencia', 'Control del sistema'];

    public function __construct(private readonly ReporteBonoAsistenciaService $reportes, private readonly PlanillaComplementariaService $planillas) {}

    public function exportar(Empresa $empresa, string $mes, float $monto, ?int $areaId = null): Spreadsheet
    {
        $reporte = $this->reportes->generar($empresa, $mes, $areaId);
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet()->setTitle('Decisiones');
        $hoja->fromArray(self::CABECERAS);
        $lote = (string) Str::uuid();
        foreach ($reporte['colaboradores'] as $i => $c) {
            $fijos = [$c['colaborador_id'], $reporte['empresa'], $mes, $c['colaborador'], $c['documento'], $c['dias_efectivos'],
                $c['descansos'], $c['tardanzas'], $c['faltas_justificadas'], $c['faltas_injustificadas'], $c['sin_huellero_completo'],
                $c['incumplimientos_turno'], $c['dias_sin_clasificar'], $c['dias_sin_resultado'], $c['evaluacion'], number_format($monto, 2, '.', ''),
                $c['porcentaje_inicial'], number_format($monto * $c['porcentaje_inicial'] / 100, 2, '.', ''), $c['porcentaje_recuperable'],
                number_format($monto * $c['porcentaje_recuperable'] / 100, 2, '.', ''), $c['observaciones']];
            $fijos = array_map(fn ($v) => (string) ($v ?? ''), $fijos);
            $token = Crypt::encryptString(json_encode(['version' => 1, 'empresa_id' => $empresa->id, 'mes' => $mes, 'area_id' => $reporte['area_id'],
                'lote' => $lote, 'colaborador_id' => $c['colaborador_id'], 'huella' => $this->huella($c), 'fijos' => $fijos], JSON_THROW_ON_ERROR));
            foreach ([...$fijos, 'Pendiente', 'Pendiente', '', '', '', '', $token] as $j => $valor) {
                $hoja->setCellValueExplicit(Coordinate::stringFromColumnIndex($j + 1).($i + 2), $valor, DataType::TYPE_STRING);
            }
            foreach (['V' => 'Pendiente,Aprobado,Rechazado', 'W' => 'Pendiente,Si,No'] as $col => $valores) {
                $hoja->getCell($col.($i + 2))->getDataValidation()->setType(DataValidation::TYPE_LIST)
                    ->setFormula1('"'.$valores.'"')->setShowDropDown(true)->setShowErrorMessage(true)->setError('Selecciona una opción de la lista.');
            }
        }
        $ultima = max(2, count($reporte['colaboradores']) + 1);
        $hoja->freezePane('F2'); $hoja->setAutoFilter('A1:AA'.$ultima);
        $hoja->getDefaultRowDimension()->setRowHeight(60);
        $hoja->getStyle('A1:AA1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $hoja->getStyle('A1:AA1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FF003366');
        $hoja->getStyle('A1:AA'.$ultima)->getAlignment()->setWrapText(true);
        foreach (range(1, 27) as $j) $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($j))->setWidth(in_array($j, [4, 21, 27]) ? 42 : 20);
        $hoja->getStyle('V2:AA'.$ultima)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFFFF2CC');
        $hoja->getStyle('V2:AA'.$ultima)->getProtection()->setLocked(Protection::PROTECTION_UNPROTECTED);
        $hoja->getStyle('V2:AA'.$ultima)->getNumberFormat()->setFormatCode('@');
        $hoja->getProtection()->setSheet(true)->setAutoFilter(false)->setPassword(Str::random(16));
        $hoja->getColumnDimension('AB')->setVisible(false);
        $instrucciones = $libro->createSheet()->setTitle('Instrucciones');
        $instrucciones->fromArray([
            ['Bono de asistencia — revisión de Gerencia. Área: '.(collect($reporte['areas'])->firstWhere('id', $reporte['area_id'])['nombre'] ?? '')],
            ['Edita únicamente las celdas amarillas de la hoja Decisiones. No alteres datos de asistencia ni identificadores.'],
            ['Decisión: Pendiente, Aprobado o Rechazado. Solo se pagan filas Aprobado, con monto, responsable y fecha.'],
            ['Monto aprobado es el BONO BRUTO, no el neto bancario. El sistema calcula descuentos y aportes aplicables.'],
            ['Si el monto supera el inicial, Cumplió meta debe ser Si. Nunca puede exceder el inicial más el recuperable.'],
            ['Una falta injustificada o 3 tardanzas impiden aprobar el bono. Corrige primero Asistencia y vuelve a exportar.'],
            ['Las filas Requiere revisión exigen sustento de Gerencia. RR. HH. debe corroborar permisos y autorizaciones previas.'],
            ['No se puede pagar un mes en curso, con días sin resultado o sin clasificar. No uses fórmulas en las celdas editables.'],
            ['Al importar se revalida empresa, mes, asistencia, boleta pagada, monto y bonos anteriores. Si algo cambió, exporta nuevamente.'],
            ['Registra decisiones para todo el listado; Pendiente y Rechazado se conservan para revisión y no generan pago.'],
            ['Después de validar, genera la complementaria, revísala, apruébala y descarga el TXT del banco correspondiente.'],
            ['La pérdida del bono no modifica comisiones comerciales. Las horas extra no compensan faltas ni tardanzas.'],
        ]);
        $instrucciones->getColumnDimension('A')->setWidth(130);
        $instrucciones->getDefaultRowDimension()->setRowHeight(40);
        $instrucciones->getStyle('A1:A12')->getAlignment()->setWrapText(true);
        $libro->setActiveSheetIndex(0);
        return $libro;
    }

    private function huella(array $fila): string { return hash('sha256', json_encode($fila, JSON_THROW_ON_ERROR)); }

    public function validar(Empresa $empresa, CicloRemunerativo $ciclo, string $archivo): array
    {
        if ($ciclo->empresa_id !== $empresa->id) throw ValidationException::withMessages(['archivo' => 'Empresa incorrecta.']);
        $zip = new \ZipArchive();
        if ($zip->open($archivo) !== true) throw ValidationException::withMessages(['archivo' => 'Adjunta el Excel .xlsx exportado por el sistema.']);
        $bytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) $bytes += $zip->statIndex($i)['size'];
        $zip->close();
        if ($bytes > 50000000) throw ValidationException::withMessages(['archivo' => 'El Excel excede el tamaño permitido.']);
        $reader = new Xlsx();
        $info = collect($reader->listWorksheetInfo($archivo))->firstWhere('worksheetName', 'Decisiones');
        if (! $info || $info['totalRows'] > 5001 || $info['totalColumns'] > 28) throw ValidationException::withMessages(['archivo' => 'Plantilla inválida o supera 5000 colaboradores.']);
        $reader->setLoadSheetsOnly('Decisiones');
        $libro = $reader->load($archivo);
        try {
            $filas = $libro->getActiveSheet()->toArray('', false, false, false);
        } finally { $libro->disconnectWorksheets(); }
        if (array_map('strval', array_shift($filas) ?? []) !== self::CABECERAS) throw ValidationException::withMessages(['archivo' => 'No cambies las columnas del Excel exportado.']);
        $mes = $ciclo->fecha_inicio->format('Y-m');
        $reportesPorArea = [];
        $boletas = Boleta::where('empresa_id', $empresa->id)->where('ciclo_id', $ciclo->id)->where('estado', 'pagada')->where('es_version_vigente', true)->get()->keyBy('colaborador_id');
        $detalles = PlanillaComplementariaDetalle::whereHas('complementaria', fn ($q) => $q->where('empresa_id', $empresa->id)->whereIn('estado', ['calculada', 'aprobada', 'pagada']))->with('complementaria')->get();
        $vistos = []; $resultado = []; $errores = []; $loteArchivo = null;
        foreach ($filas as $indice => $fila) {
            if (implode('', $fila) === '') continue;
            $numero = $indice + 2;
            try {
                $control = json_decode(Crypt::decryptString((string) ($fila[27] ?? '')), true, 512, JSON_THROW_ON_ERROR);
                if (($control['version'] ?? null) !== 1 || $control['empresa_id'] !== $empresa->id || $control['mes'] !== $mes) throw new \RuntimeException('El Excel corresponde a otra empresa, mes o versión.');
                if (array_map('strval', array_slice($fila, 0, 21)) !== $control['fijos']) throw new \RuntimeException('Se modificaron datos de consulta. Cambia solo las columnas amarillas.');
                $loteArchivo ??= $control['lote'];
                if ($loteArchivo !== $control['lote']) throw new \RuntimeException('No mezcles filas de distintas exportaciones.');
                $id = $control['colaborador_id'];
                if (isset($vistos[$id])) throw new \RuntimeException('Colaborador repetido en el Excel.');
                $vistos[$id] = true;
                $area = $control['area_id'] ?? null;
                $claveArea = $area ?? 'ventas';
                $reportesPorArea[$claveArea] ??= collect($this->reportes->generar($empresa, $mes, $area)['colaboradores'])->keyBy('colaborador_id');
                $actual = $reportesPorArea[$claveArea]->get($id);
                if (! $actual) throw new \RuntimeException('El colaborador ya no pertenece al área seleccionada en el Excel. Exporta nuevamente.');
                if (! $actual || ! hash_equals($control['huella'], $this->huella($actual))) throw new \RuntimeException('La asistencia o los datos cambiaron desde la exportación. Exporta y revisa nuevamente.');
                foreach (array_slice($fila, 21, 6) as $valor) if (preg_match('/^\s*[=+@]/', (string) $valor)) throw new \RuntimeException('No se admiten fórmulas en las decisiones.');
                $decision = mb_strtolower(trim((string) $fila[21]));
                $meta = str_replace('í', 'i', mb_strtolower(trim((string) $fila[22])));
                if (! in_array($decision, ['aprobado', 'rechazado', 'pendiente'], true) || ! in_array($meta, ['si', 'no', 'pendiente'], true)) throw new \RuntimeException('Decisión o cumplimiento de meta inválidos.');
                $monto = 0;
                if ($decision === 'aprobado') {
                    if ($ciclo->estado !== 'pagado' || $ciclo->fecha_fin->isFuture()) throw new \RuntimeException('Se requiere un ciclo pagado y un mes terminado.');
                    if ($actual['faltas_injustificadas'] > 0 || $actual['tardanzas'] >= 3) throw new \RuntimeException('No cumple: faltas injustificadas o 3 tardanzas. Corrige Asistencia antes de aprobar.');
                    if ($actual['dias_sin_resultado'] > 0 || $actual['dias_sin_clasificar'] > 0) throw new \RuntimeException('Completa o clasifica los resultados diarios antes de aprobar.');
                    if ($actual['dias_efectivos'] + $actual['faltas_justificadas'] < 26 || $actual['descansos'] !== 4) throw new \RuntimeException('No cumple las 26 jornadas (considerando las excepciones justificadas) y los 4 descansos. Revisa Asistencia.');
                    if (! preg_match('/^\d{1,7}([.,]\d{1,2})?$/', (string) $fila[23])) throw new \RuntimeException('Monto inválido: ingresa un número positivo con hasta dos decimales.');
                    $monto = (int) round((float) str_replace(',', '.', $fila[23]) * 100);
                    $maximo = (int) round(((float) $control['fijos'][17] + ($meta === 'si' ? (float) $control['fijos'][19] : 0)) * 100);
                    if ($monto <= 0 || $monto > $maximo) throw new \RuntimeException('El monto excede el bono permitido; verifica el porcentaje y la meta comercial.');
                    if (! trim((string) $fila[24]) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fila[25]) || ! checkdate((int) substr($fila[25], 5, 2), (int) substr($fila[25], 8, 2), (int) substr($fila[25], 0, 4)) || $fila[25] > today()->toDateString()) throw new \RuntimeException('Indica responsable y fecha válida de aprobación (AAAA-MM-DD), sin fecha futura.');
                    if ($actual['evaluacion'] === 'Requiere revisión' && mb_strlen(trim((string) $fila[26])) < 5) throw new \RuntimeException('La revisión requiere sustento de Gerencia sobre las observaciones.');
                    if (mb_strlen((string) $fila[26]) > 1000 || mb_strlen((string) $fila[24]) > 200) throw new \RuntimeException('Responsable o sustento demasiado largos.');
                    $boleta = $boletas->get($id);
                    if (! $boleta) throw new \RuntimeException('No tiene boleta vigente y pagada en este ciclo.');
                    foreach ($detalles->where('colaborador_id', $id) as $d) {
                        if (($d->calculo_snapshot['bono_asistencia_gerencia']['mes'] ?? null) === $mes
                            || ($d->complementaria->ciclo_id === $ciclo->id && ! empty($d->calculo_snapshot['bonos_masivos']))) throw new \RuntimeException('Ya tiene un bono de asistencia registrado o reservado para este mes.');
                        if ($d->complementaria->ciclo_id === $ciclo->id && $d->complementaria->estado === 'calculada') throw new \RuntimeException('Ya pertenece a una complementaria calculada. Apruébala o elimínala antes de generar este bono.');
                    }
                }
                $resultado[] = ['fila' => $numero, 'colaborador_id' => $id, 'boleta_id' => $boletas->get($id)?->id,
                    'colaborador' => $actual['colaborador'], 'decision' => $decision, 'meta' => $meta, 'monto' => $monto / 100,
                    'responsable' => trim((string) $fila[24]), 'fecha' => (string) $fila[25], 'sustento' => trim((string) $fila[26]),
                    'mes' => $mes, 'lote' => $control['lote'], 'evaluacion' => $actual];
            } catch (\Illuminate\Contracts\Encryption\DecryptException|\JsonException $e) {
                $errores[] = ['fila' => $numero, 'mensaje' => 'Control inválido. Utiliza el Excel exportado por el sistema.'];
            } catch (\RuntimeException $e) { $errores[] = ['fila' => $numero, 'mensaje' => $e->getMessage()]; }
        }
        $aprobados = collect($resultado)->where('decision', 'aprobado');
        return ['filas' => $resultado, 'errores' => $errores, 'aprobados' => $aprobados->count(),
            'total_bruto' => round($aprobados->sum('monto'), 2), 'listo' => ! $errores && $aprobados->isNotEmpty()];
    }

    public function generar(Empresa $empresa, CicloRemunerativo $ciclo, string $archivo, ?int $conceptoId, ?int $definicionId, string $motivo, int $usuarioId): PlanillaComplementaria
    {
        return DB::transaction(function () use ($empresa, $ciclo, $archivo, $conceptoId, $definicionId, $motivo, $usuarioId) {
            Empresa::whereKey($empresa->id)->lockForUpdate()->firstOrFail();
            $ciclo = CicloRemunerativo::whereKey($ciclo->id)->lockForUpdate()->firstOrFail();
            $validacion = $this->validar($empresa, $ciclo, $archivo);
            if (! $validacion['listo']) throw ValidationException::withMessages(['archivo' => $validacion['errores'] ? array_map(fn ($e) => "Fila {$e['fila']}: {$e['mensaje']}", $validacion['errores']) : ['No hay bonos aprobados para generar.']]);
            $aprobados = collect($validacion['filas'])->where('decision', 'aprobado');
            $hayPlanilla = Boleta::whereIn('id', $aprobados->pluck('boleta_id'))->where('regimen_laboral_snapshot', '!=', 'Locacion de Servicios')->exists();
            if ($hayPlanilla) {
                $concepto = ConceptoRemuneracion::whereKey($conceptoId)->where('activo', true)->first();
                if (! $concepto || ! in_array($concepto->codigo, ['BONIFICACION', 'BONO_NO_REMUNERATIVO'], true)) throw ValidationException::withMessages(['concepto_id' => 'Selecciona un concepto de bono para los colaboradores en planilla.']);
                if (! DB::table('concepto_definiciones_plame')->where('id', $definicionId)->where('concepto_remuneracion_id', $conceptoId)->where('activo', true)->exists()) throw ValidationException::withMessages(['concepto_definicion_id' => 'Selecciona una clasificación PLAME válida.']);
            }
            $item = PlanillaComplementaria::create(['empresa_id' => $empresa->id, 'ciclo_id' => $ciclo->id,
                'nombre' => 'Bonos aprobados por Gerencia '.$ciclo->nombre, 'motivo' => $motivo, 'estado' => 'calculada', 'creado_por' => $usuarioId]);
            $this->planillas->agregarColaboradores($empresa, $item, $aprobados->pluck('boleta_id')->all());
            foreach ($item->detalles()->get() as $detalle) {
                $fila = $aprobados->firstWhere('boleta_id', $detalle->boleta_original_id);
                if ($detalle->boletaOriginal->regimen_laboral_snapshot === 'Locacion de Servicios') {
                    $this->planillas->agregarBonoHonorariosGerencia($empresa, $detalle, $fila['monto'], $motivo, $usuarioId);
                } else {
                    $this->planillas->agregarConcepto($empresa, $detalle, $conceptoId, $definicionId, $fila['monto'], $motivo, $usuarioId);
                }
                $snapshot = $detalle->fresh()->calculo_snapshot;
                if ((float) $detalle->fresh()->diferencia_neta <= 0) throw ValidationException::withMessages(['bono' => "{$fila['colaborador']}: el bono no produce un neto positivo tras los descuentos. Revisa el cálculo antes de generar el pago."]);
                $snapshot['bono_asistencia_gerencia'] = [...$fila, 'archivo_sha256' => hash_file('sha256', $archivo),
                    'importado_por' => $usuarioId, 'importado_en' => now()->toIso8601String()];
                $detalle->update(['calculo_snapshot' => $snapshot]);
            }
            return $item->load('detalles');
        });
    }
}
