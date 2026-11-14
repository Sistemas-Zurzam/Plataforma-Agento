<?php

namespace App\Modules\Nominas\Infrastructure\Cts;

use App\Modules\Nominas\Models\BeneficioSocial;
use App\Modules\Nominas\Models\BeneficioSocialDetalle;
use App\Modules\Nominas\Services\BeneficioSocialService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Boleta de CTS: hoja de liquidación del depósito semestral (D.S. 001-97-TR,
 * art. 29). Se arma con el snapshot del cálculo (detalle_calculo) y los
 * depósitos registrados; nunca recalcula. Una página por colaborador.
 */
class BoletaCtsPdf
{
    private const NOMBRES = [
        'SUELDO_BASICO' => 'Remuneración básica', 'ASIGNACION_FAMILIAR' => 'Asignación familiar',
        'COMISION' => 'Promedio de comisiones', 'HE_25' => 'Promedio de horas extra 25%',
        'HE_35' => 'Promedio de horas extra 35%', 'HE_100' => 'Promedio de horas extra 100%',
        // CTS cargada desde el Excel de RR.HH.: promedios ya calculados.
        'PROMEDIO_HORAS_EXTRA' => 'Promedio de horas extra', 'PROMEDIO_COMISIONES' => 'Promedio de comisiones',
    ];

    /** @param  Collection<int, BeneficioSocialDetalle>  $detalles  De un mismo beneficio de CTS. */
    public function generar(BeneficioSocial $beneficio, Collection $detalles): string
    {
        $paginas = $detalles->map(fn (BeneficioSocialDetalle $d) => $this->datos($beneficio, $d))->values()->all();

        return Pdf::loadView('nominas.boleta-cts', ['paginas' => $paginas, 'logo' => $this->logo($beneficio)])
            ->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->output();
    }

    /** @return array<string, mixed> */
    public function datos(BeneficioSocial $beneficio, BeneficioSocialDetalle $detalle): array
    {
        $calculo = $detalle->detalle_calculo ?? [];
        $rango = BeneficioSocialService::rango($beneficio->tipo, $beneficio->anio);
        $colaborador = $detalle->colaborador;
        $empresa = $beneficio->empresa;

        $base = [];
        foreach ($calculo['conceptos'] ?? ['SUELDO_BASICO' => (float) $detalle->sueldo_basico] as $codigo => $monto) {
            if ((float) $monto > 0) {
                $base[] = ['concepto' => self::NOMBRES[$codigo] ?? $codigo, 'monto' => (float) $monto];
            }
        }
        if ((float) ($calculo['gratificacion_sexto'] ?? 0) > 0) {
            $base[] = ['concepto' => '1/6 de gratificación ('.number_format((float) $calculo['gratificacion_legal_percibida'], 2).' / 6)', 'monto' => (float) $calculo['gratificacion_sexto']];
        }
        $depositos = $detalle->depositos->sortBy('fecha_deposito')->values();
        $ultimo = $depositos->last();

        return [
            'titulo' => 'Liquidación de depósito de CTS',
            'periodo' => self::mes($rango['inicio']).' a '.self::mes($rango['fin']),
            'empresa' => ['nombre' => $empresa->razon_social ?: $empresa->nombre_comercial, 'ruc' => $empresa->ruc, 'direccion' => $empresa->direccion],
            'trabajador' => [
                'nombre' => trim($colaborador->nombres.' '.$colaborador->apellidos),
                'documento' => $colaborador->numero_documento,
                'cargo' => $colaborador->cargo,
                'fecha_ingreso' => $colaborador->fecha_ingreso?->format('d/m/Y'),
            ],
            'base' => $base,
            'base_total' => round(array_sum(array_column($base, 'monto')), 2),
            'meses' => (int) ($calculo['meses'] ?? $detalle->meses),
            'dias' => (int) ($calculo['dias'] ?? 0),
            'dias_computables' => (int) ($calculo['dias_computables'] ?? ((int) $detalle->meses * 30)),
            'tasa' => (float) ($calculo['tasa'] ?? 1),
            'monto' => (float) $detalle->neta,
            'deposito' => [
                'entidad' => $ultimo?->entidad,
                'cuenta' => $colaborador->cts_cuenta,
                'fecha' => $ultimo?->fecha_deposito?->format('d/m/Y'),
                'referencia' => $depositos->pluck('referencia')->implode(', '),
                'importe' => round((float) $depositos->sum('importe'), 2),
            ],
            'moneda' => 'Soles (S/)',
        ];
    }

    /** "Mayo 2026" sin depender del locale del servidor. */
    private static function mes(string $fecha): string
    {
        $meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Setiembre', 'Octubre', 'Noviembre', 'Diciembre'];
        $f = Carbon::parse($fecha);

        return $meses[$f->month - 1].' '.$f->year;
    }

    /** Logo de la empresa embebido (dompdf no descarga URLs remotas; WebP/SVG no se dibujan bien). */
    private function logo(BeneficioSocial $beneficio): ?string
    {
        $ruta = $beneficio->empresa?->logo_path;
        if (! $ruta || ! Storage::disk('public')->exists($ruta)) {
            return null;
        }
        $mime = Storage::disk('public')->mimeType($ruta);
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($ruta));
    }
}
