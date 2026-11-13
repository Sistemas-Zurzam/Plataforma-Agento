<?php

namespace App\Modules\Nominas\Infrastructure\EnvioBoletas;

use App\Modules\Nominas\Http\Resources\BoletaResource;
use App\Modules\Nominas\Models\Boleta;
use App\Modules\Nominas\Support\NumeroALetras;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * PDF de la boleta de pago que se adjunta al correo. Parte del mismo payload que
 * la boleta imprimible del frontend (BoletaResource sobre BoletaService::ver())
 * y replica sus totales (BoletaDocumento.jsx): descuentos previsionales
 * vigentes, reintegros brutos y neto. Nunca recalcula la boleta.
 */
class BoletaPdf
{
    /** Aportes del empleador que el trabajador sí ve (ESSALUD o SIS). */
    private const APORTES_VISIBLES = ['ESSALUD', 'SIS_APORTACION'];

    /** @param  Boleta  $boleta  Cargada con BoletaService::ver(). */
    public function generar(Boleta $boleta): string
    {
        // Subsetting: solo los glifos usados de la fuente (de ~860 KB a decenas de KB por correo).
        return Pdf::loadView('nominas.boleta-pdf', $this->datos($boleta))->setPaper('a4')
            ->setOption('isFontSubsettingEnabled', true)->output();
    }

    /** @return array<string, mixed> */
    public function datos(Boleta $boleta): array
    {
        $d = json_decode(json_encode((new BoletaResource($boleta))->resolve()), true);
        $conceptos = collect($d['conceptos'] ?? []);
        $desglose = collect($d['desglose_previsional'] ?? [])->keyBy('codigo');

        $ingresos = $conceptos->where('tipo', 'ingreso')->values();
        $egresos = $conceptos->where('tipo', 'egreso')->map(function (array $c) use ($desglose) {
            $previsional = $desglose->get($c['codigo']);
            $c['monto_vigente'] = $previsional
                ? (float) $previsional['de_remuneracion'] + (float) $previsional['de_reintegros']
                : (float) $c['monto'];
            $c['previsional'] = $previsional;

            return $c;
        })->values();
        $aportes = $conceptos->where('tipo', 'aportacion')->whereIn('codigo', self::APORTES_VISIBLES)->values();
        $reintegros = collect($d['reintegros'] ?? [])->map(fn (array $r) => [
            ...$r, 'bruto' => (float) ($r['monto'] ?? 0) + (float) ($r['afp_retenido'] ?? 0),
        ]);

        $totalReintegros = (float) $reintegros->sum('bruto');
        $totalDescuentos = (float) $egresos->sum('monto_vigente');
        $neto = (float) $d['total_ingresos'] + $totalReintegros - $totalDescuentos;
        $empresa = $d['empresa'] ?? [];
        $moneda = $d['datos_pago']['moneda'] ?? 'PEN';

        return [
            'boleta' => $d,
            'empresaTitulo' => collect([$empresa['razon_social'] ?? null, $empresa['nombre_comercial'] ?? null])->filter()->unique()->implode(' - ') ?: '—',
            'logo' => $this->logo($boleta),
            'periodo' => $d['ciclo']['nombre'] ?? '',
            'ingresos' => $ingresos,
            'egresos' => $egresos,
            'aportes' => $aportes,
            'reintegros' => $reintegros,
            'totalReintegros' => $totalReintegros,
            'totalDescuentos' => $totalDescuentos,
            'totalAportes' => (float) $aportes->sum('monto'),
            'neto' => $neto,
            'netoEnLetras' => NumeroALetras::convertir($neto, $moneda),
            'horasExtra25' => (float) $conceptos->where('codigo', 'HE_25')->sum('cantidad'),
            'horasExtra35' => (float) $conceptos->where('codigo', 'HE_35')->sum('cantidad'),
            'fecha' => fn (?string $valor) => $valor ? Carbon::parse($valor)->format('d/m/Y') : '—',
            'soles' => fn ($valor) => 'S/ '.number_format((float) $valor, 2, '.', ','),
        ];
    }

    /** Logo de la empresa embebido (dompdf no descarga URLs remotas). */
    private function logo(Boleta $boleta): ?string
    {
        $ruta = $boleta->colaborador?->empresa?->logo_path;
        if (! $ruta || ! Storage::disk('public')->exists($ruta)) {
            return null;
        }
        $mime = Storage::disk('public')->mimeType($ruta);
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
            return null; // dompdf no dibuja WebP/SVG de forma fiable: el PDF sale sin logo.
        }

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($ruta));
    }
}
