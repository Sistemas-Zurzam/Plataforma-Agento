<?php

namespace App\Modules\Nominas\Services;

use App\Modules\Configuracion\Models\Empresa;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Models\BoletaComprobanteRh;
use App\Modules\Nominas\Models\CicloRemunerativo;
use Illuminate\Support\Carbon;
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
            ->with('colaborador')->get()->keyBy(fn (Boleta $b) => preg_replace('/\D/', '', (string) $b->colaborador?->numero_documento));
        $filas = []; $errores = []; $omitidas = []; $vistos = [];

        foreach ($hoja->toArray(null, true, true, false) as $indice => $fila) {
            $documento = preg_replace('/\D/', '', (string) ($fila[5] ?? ''));
            $comprobanteTexto = strtoupper(trim((string) ($fila[2] ?? '')));
            if ($documento === '' || ! preg_match('/^([A-Z0-9]{1,4})\s*-\s*([0-9]{1,8})$/', $comprobanteTexto, $partes)) continue;
            $numeroFila = $indice + 1;
            $estado = mb_strtoupper(trim((string) ($fila[3] ?? '')));
            if (in_array($estado, ['ANULADO', 'REVERTIDO'], true)) {
                $omitidas[] = ['fila' => $numeroFila, 'documento' => $documento, 'comprobante' => $comprobanteTexto, 'motivo' => $estado];
                continue;
            }
            $boleta = $boletas->get($documento);
            if (! $boleta) { $errores[] = ['fila' => $numeroFila, 'mensaje' => "No existe boleta RH de esta empresa/ciclo para el documento {$documento}."]; continue; }
            try { $fechaEmision = $this->fecha($fila[0] ?? null); }
            catch (\Throwable) { $errores[] = ['fila' => $numeroFila, 'mensaje' => 'Fecha de emision invalida.']; continue; }
            if ($fechaEmision < $ciclo->fecha_inicio->toDateString() || $fechaEmision > $ciclo->fecha_fin->toDateString()) {
                $errores[] = ['fila' => $numeroFila, 'mensaje' => 'La fecha de emision no pertenece al ciclo seleccionado.']; continue;
            }
            $monto = $this->numero($fila[10] ?? null);
            $retencion = $this->numero($fila[11] ?? 0);
            if ($monto <= 0) { $errores[] = ['fila' => $numeroFila, 'mensaje' => 'La renta bruta debe ser mayor a cero.']; continue; }
            $clave = $boleta->id.'|'.$partes[1].'|'.$partes[2];
            if (isset($vistos[$clave])) { $errores[] = ['fila' => $numeroFila, 'mensaje' => 'Comprobante duplicado dentro del Excel.']; continue; }
            $vistos[$clave] = true;
            $filas[] = ['fila' => $numeroFila, 'boleta_id' => $boleta->id, 'colaborador' => trim($boleta->colaborador->nombres.' '.$boleta->colaborador->apellidos),
                'documento' => $documento, 'tipo_comprobante' => 'R', 'serie' => $partes[1], 'numero' => $partes[2],
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
                    [...collect($fila)->except(['fila', 'colaborador', 'documento'])->all(), 'importe_aporte_regimen_pensionario' => null, 'registrado_por' => $usuarioId],
                );
            }
            return $revision['resumen'];
        });
    }

    private function fecha(mixed $valor): string
    {
        if (is_numeric($valor)) return Date::excelToDateTimeObject((float) $valor)->format('Y-m-d');
        return Carbon::createFromFormat('j/n/Y', trim((string) $valor))->format('Y-m-d');
    }

    private function numero(mixed $valor): float
    {
        if (is_numeric($valor)) return (float) $valor;
        return (float) str_replace([',', ' '], ['', ''], trim((string) $valor));
    }
}
