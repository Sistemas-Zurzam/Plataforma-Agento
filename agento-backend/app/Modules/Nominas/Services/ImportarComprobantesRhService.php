<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaComprobanteRh;
use App\Modules\Nominas\Models\CicloRemunerativo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ImportarComprobantesRhService
{
    public function revisar(Empresa $empresa, CicloRemunerativo $ciclo, string $ruta, string $fechaPago): array
    {
        abort_unless($ciclo->empresa_id === $empresa->id, 403);
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        $boletas = Boleta::where('empresa_id', $empresa->id)->where('ciclo_id', $ciclo->id)
            ->where('es_version_vigente', true)->where('regimen_laboral_snapshot', 'Locacion de Servicios')
            ->with('colaborador')->get()->groupBy(fn (Boleta $b) => $this->normalizarDocumento($b->colaborador?->numero_documento));
        $filas = []; $errores = []; $omitidas = []; $vistos = [];

        // formatData=false conserva las fechas nativas como seriales de Excel. Si se
        // solicita el valor formateado, PhpSpreadsheet puede aplicar el locale del
        // servidor e intercambiar dia y mes (p. ej. 03/08 -> 8 de marzo).
        foreach ($hoja->toArray(null, true, false, false) as $indice => $fila) {
            $documento = preg_replace('/\D/', '', (string) ($fila[5] ?? ''));
            $comprobanteTexto = strtoupper(trim((string) ($fila[2] ?? '')));
            if ($documento === '' || ! preg_match('/^([A-Z0-9]{1,4})\s*-\s*([0-9]{1,8})$/', $comprobanteTexto, $partes)) continue;
            $numeroFila = $indice + 1;
            $estado = mb_strtoupper(trim((string) ($fila[3] ?? '')));
            if (in_array($estado, ['ANULADO', 'REVERTIDO'], true)) {
                $omitidas[] = ['fila' => $numeroFila, 'documento' => $documento, 'comprobante' => $comprobanteTexto, 'motivo' => $estado];
                continue;
            }
            $documentoMatch = $documento;
            $tipoMatch = 'documento_exacto';
            $candidatas = $boletas->get($documentoMatch, collect());

            if ($candidatas->isEmpty() && $this->esRucPersonaNatural($documento)) {
                $documentoMatch = substr($documento, 2, 8);
                $tipoMatch = 'dni_desde_ruc';
                $candidatas = $boletas->get($documentoMatch, collect());
            }

            if ($candidatas->isEmpty()) {
                $errores[] = ['fila' => $numeroFila, 'mensaje' => "No existe boleta RH de esta empresa/ciclo para el documento {$documento}."];
                continue;
            }
            if ($candidatas->count() > 1) {
                $errores[] = ['fila' => $numeroFila, 'mensaje' => "El documento {$documento} coincide con mas de una boleta RH de esta empresa/ciclo."];
                continue;
            }
            $boleta = $candidatas->first();
            try { $fechaEmision = $this->fecha($fila[0] ?? null); }
            catch (\Throwable) { $errores[] = ['fila' => $numeroFila, 'mensaje' => 'Fecha de emision invalida.']; continue; }
            if ($fechaEmision < $ciclo->fecha_inicio->toDateString() || $fechaEmision > $ciclo->fecha_fin->toDateString()) {
                $errores[] = ['fila' => $numeroFila, 'mensaje' => "La fecha de emision {$fechaEmision} no pertenece al ciclo {$ciclo->fecha_inicio->toDateString()} a {$ciclo->fecha_fin->toDateString()}."]; continue;
            }
            $monto = $this->numero($fila[10] ?? null);
            $retencion = $this->numero($fila[11] ?? 0);
            if ($monto <= 0) { $errores[] = ['fila' => $numeroFila, 'mensaje' => 'La renta bruta debe ser mayor a cero.']; continue; }
            $clave = $boleta->id.'|'.$partes[1].'|'.$partes[2];
            if (isset($vistos[$clave])) { $errores[] = ['fila' => $numeroFila, 'mensaje' => 'Comprobante duplicado dentro del Excel.']; continue; }
            $vistos[$clave] = true;
            $filas[] = ['fila' => $numeroFila, 'boleta_id' => $boleta->id, 'colaborador' => trim($boleta->colaborador->nombres.' '.$boleta->colaborador->apellidos),
                'documento' => $documento, 'documento_match' => $documentoMatch, 'tipo_match' => $tipoMatch,
                'tipo_comprobante' => 'R', 'serie' => $partes[1], 'numero' => $partes[2],
                'fecha_emision' => $fechaEmision, 'fecha_pago' => $fechaPago, 'monto_total_servicio' => round($monto, 2),
                'indicador_retencion_4ta' => $retencion > 0, 'indicador_retencion_regimen_pensionario' => '3'];
        }
        $hoja->getParent()->disconnectWorksheets();
        return ['filas' => $filas, 'errores' => $errores, 'omitidas' => $omitidas, 'listo' => $filas !== [] && $errores === [],
            'resumen' => ['validos' => count($filas), 'errores' => count($errores), 'omitidos' => count($omitidas)]];
    }

    public function importar(Empresa $empresa, CicloRemunerativo $ciclo, string $ruta, string $fechaPago, int $usuarioId): array
    {
        return DB::transaction(function () use ($empresa, $ciclo, $ruta, $fechaPago, $usuarioId) {
            $revision = $this->revisar($empresa, $ciclo, $ruta, $fechaPago);
            if (! $revision['listo']) throw ValidationException::withMessages(['archivo' => collect($revision['errores'])->map(fn ($e) => "Fila {$e['fila']}: {$e['mensaje']}")->all() ?: ['No hay comprobantes validos.']]);
            foreach ($revision['filas'] as $fila) {
                BoletaComprobanteRh::updateOrCreate(
                    ['boleta_id' => $fila['boleta_id'], 'serie' => $fila['serie'], 'numero' => $fila['numero']],
                    [...collect($fila)->except(['fila', 'colaborador', 'documento', 'documento_match', 'tipo_match'])->all(), 'importe_aporte_regimen_pensionario' => null, 'registrado_por' => $usuarioId],
                );
            }
            return $revision['resumen'];
        });
    }

    private function fecha(mixed $valor): string
    {
        if (is_numeric($valor)) return Date::excelToDateTimeObject((float) $valor)->format('Y-m-d');

        $texto = trim((string) $valor);
        foreach (['!d/m/Y', '!j/n/Y', '!d/m/y', '!j/n/y'] as $formato) {
            $fecha = \DateTimeImmutable::createFromFormat($formato, $texto);
            $errores = \DateTimeImmutable::getLastErrors();
            if ($fecha !== false && ($errores === false || ($errores['warning_count'] === 0 && $errores['error_count'] === 0))) {
                return $fecha->format('Y-m-d');
            }
        }

        throw new \InvalidArgumentException('Fecha de emision invalida.');
    }

    private function numero(mixed $valor): float
    {
        if (is_numeric($valor)) return (float) $valor;
        return (float) str_replace([',', ' '], ['', ''], trim((string) $valor));
    }

    private function normalizarDocumento(mixed $documento): string
    {
        return preg_replace('/\D/', '', (string) $documento);
    }

    private function esRucPersonaNatural(string $documento): bool
    {
        return strlen($documento) === 11 && str_starts_with($documento, '10');
    }
}
