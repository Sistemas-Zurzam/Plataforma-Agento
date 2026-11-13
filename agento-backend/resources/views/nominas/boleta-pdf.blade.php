{{-- Boleta de pago en PDF para el correo (dompdf: CSS 2.1, tablas, sin flex).
     Mismo contenido que BoletaDocumento.jsx en su versión oficial (boleta pagada).
     Colores: tokens agento-blue. --}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Boleta de pago {{ $periodo }}</title>
<style>
    @page { margin: 22px 26px; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { font-size: 9px; color: #1f2937; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    .cabecera td { vertical-align: middle; padding: 8px 0; }
    .marca { font-size: 15px; font-weight: bold; color: #014693; }
    .sub { font-size: 7px; color: #9ca3af; letter-spacing: 1px; text-transform: uppercase; }
    .titulo { font-size: 15px; font-weight: bold; text-align: center; }
    .numero { background: #014693; color: #fff; padding: 5px 9px; text-align: right; }
    .empresa { background: #f9fafb; border-top: 1px solid #f3f4f6; border-bottom: 1px solid #f3f4f6; }
    .empresa td { padding: 6px 4px; }
    .tarjeta { border: 1px solid #e5e7eb; }
    .tarjeta th { background: #014693; color: #fff; text-align: left; padding: 4px 6px; font-size: 8px; text-transform: uppercase; }
    .tarjeta td { padding: 2px 6px; border-bottom: 1px solid #f3f4f6; }
    .tarjeta td.valor { text-align: right; font-weight: bold; }
    .matriz th { background: #014693; color: #fff; padding: 4px; font-size: 7.5px; text-transform: uppercase; text-align: right; }
    .matriz th.izq, .matriz td.izq { text-align: left; }
    .matriz td { padding: 3px 4px; border-bottom: 1px solid #f3f4f6; text-align: right; vertical-align: top; }
    .matriz .seccion td { background: #e6eefb; color: #001a3d; font-weight: bold; font-size: 7.5px; text-transform: uppercase; text-align: left; }
    .matriz .totales td { background: #e6eefb; color: #001a3d; font-weight: bold; }
    .detalle { display: block; color: #9ca3af; font-size: 7px; }
    .neto td { padding: 8px; }
    .neto .etiqueta { background: #001a3d; color: #fff; font-weight: bold; font-size: 11px; }
    .neto .monto { background: #e6eefb; color: #001a3d; font-weight: bold; font-size: 14px; text-align: right; }
    .pie { color: #9ca3af; font-size: 7px; background: #f9fafb; padding: 6px; }
    .espacio { height: 8px; }
</style>
</head>
<body>
@php($b = $boleta)

<table class="cabecera">
    <tr>
        <td style="width: 35%;"><span class="marca">AGENTO</span><br><span class="sub">Sistema de Gestión de Personal</span></td>
        <td class="titulo">Boleta de pago<br><span class="sub" style="font-size: 8px;">{{ str_starts_with(mb_strtolower($periodo), 'planilla') ? $periodo : 'Planilla '.$periodo }}</span></td>
        <td style="width: 30%; text-align: right;">
            <div class="numero"><span class="sub" style="color: #e6eefb;">N° boleta</span><br><b>{{ $b['numero_boleta'] ?? '—' }}</b></div>
        </td>
    </tr>
</table>

<table class="empresa">
    <tr>
        <td><b style="font-size: 10px;">{{ $empresaTitulo }}</b><br>RUC: {{ $b['empresa']['ruc'] ?? '—' }} · {{ $b['empresa']['direccion'] ?? '—' }}</td>
        @if ($logo)<td style="text-align: right; width: 130px;"><img src="{{ $logo }}" style="max-height: 34px; max-width: 130px;"></td>@endif
    </tr>
</table>

<div class="espacio"></div>
<table>
    <tr>
        <td style="width: 50%; vertical-align: top; padding-right: 4px;">
            <table class="tarjeta">
                <tr><th colspan="2">Datos del trabajador</th></tr>
                <tr><td>Apellidos y nombres</td><td class="valor">{{ $b['colaborador']['nombre_completo'] }}</td></tr>
                <tr><td>DNI</td><td class="valor">{{ $b['colaborador']['numero_documento'] ?? '—' }}</td></tr>
                <tr><td>Código</td><td class="valor">{{ $b['colaborador']['legajo'] ?? '—' }}</td></tr>
                <tr><td>Cargo</td><td class="valor">{{ $b['colaborador']['cargo'] ?? '—' }}</td></tr>
                <tr><td>Área</td><td class="valor">{{ $b['colaborador']['area'] ?? '—' }}</td></tr>
                <tr><td>Fecha de ingreso</td><td class="valor">{{ $fecha($b['colaborador']['fecha_ingreso'] ?? null) }}</td></tr>
                <tr><td>Régimen laboral</td><td class="valor">{{ $b['regimen_laboral'] ?? '—' }}</td></tr>
                <tr><td>AFP / ONP</td><td class="valor">{{ ($b['colaborador']['sistema_previsional'] ?? null) === 'onp' ? 'ONP' : 'AFP' }}</td></tr>
                <tr><td>CUSPP</td><td class="valor">{{ $b['colaborador']['cuspp'] ?: 'No registrado' }}</td></tr>
                <tr><td>Sucursal</td><td class="valor">{{ $b['colaborador']['sede'] ?? '—' }}</td></tr>
            </table>
        </td>
        <td style="width: 50%; vertical-align: top; padding-left: 4px;">
            <table class="tarjeta">
                <tr><th colspan="2">Información del período</th></tr>
                <tr><td>Período</td><td class="valor">{{ $periodo ?: '—' }}</td></tr>
                <tr><td>Fecha de inicio</td><td class="valor">{{ $fecha($b['ciclo']['fecha_inicio'] ?? null) }}</td></tr>
                <tr><td>Fecha de fin</td><td class="valor">{{ $fecha($b['ciclo']['fecha_fin'] ?? null) }}</td></tr>
                <tr><td>Fecha de pago</td><td class="valor">{{ $fecha($b['pagado_at'] ?? $b['ciclo']['fecha_pago'] ?? null) }}</td></tr>
                <tr><td>Días trabajados</td><td class="valor">{{ number_format((float) $b['dias_pagados'], 2) }}</td></tr>
                <tr><td>Horas extras 25%</td><td class="valor">{{ number_format($horasExtra25, 1) }}</td></tr>
                <tr><td>Horas extras 35%</td><td class="valor">{{ number_format($horasExtra35, 1) }}</td></tr>
                <tr><td>Vacaciones</td><td class="valor">{{ ($b['ausencias_periodo']['vacaciones'] ?? false) ? 'Sí' : 'No' }}</td></tr>
                <tr><td>Descanso médico</td><td class="valor">{{ ($b['ausencias_periodo']['descanso_medico'] ?? false) ? 'Sí' : 'No' }}</td></tr>
                <tr><td>Licencias</td><td class="valor">{{ ($b['ausencias_periodo']['licencia'] ?? null) ?: 'Ninguna' }}</td></tr>
                <tr><td>Sueldo / jornal</td><td class="valor">{{ number_format((float) $b['sueldo_basico'], 2) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="espacio"></div>
<table class="matriz tarjeta">
    <tr>
        <th class="izq">Concepto</th><th>Días / horas</th><th>Ingresos (S/)</th><th>Reintegros (S/)</th><th>Descuentos (S/)</th><th>Aporte empleador (S/)</th>
    </tr>
    <tr class="seccion"><td colspan="6">Remuneración ordinaria</td></tr>
    @forelse ($ingresos as $c)
        <tr><td class="izq">{{ $c['nombre'] }}<span class="detalle">{{ $c['formula_texto'] }}</span></td><td>{{ $c['cantidad'] !== null ? number_format((float) $c['cantidad'], 2) : '—' }}</td><td>{{ $soles($c['monto']) }}</td><td>—</td><td>—</td><td>—</td></tr>
    @empty
        <tr><td class="izq" colspan="6">Sin ingresos registrados</td></tr>
    @endforelse

    @if ($reintegros->isNotEmpty())
        <tr class="seccion"><td colspan="6">Reintegros pagados con posterioridad (importe bruto)</td></tr>
        @foreach ($reintegros as $r)
            <tr><td class="izq">{{ $r['nombre'] }}<span class="detalle">{{ $r['motivo'] ?? '' }} — Neto pagado: {{ $soles($r['monto'] ?? 0) }}</span></td><td>—</td><td>—</td><td>{{ $soles($r['bruto']) }}</td><td>—</td><td>—</td></tr>
        @endforeach
    @endif

    <tr class="seccion"><td colspan="6">Descuentos</td></tr>
    @forelse ($egresos as $c)
        <tr>
            <td class="izq">{{ $c['nombre'] }}
                @if ($c['previsional'])
                    <span class="detalle">De remuneración: {{ $soles($c['previsional']['de_remuneracion']) }} · De reintegros: {{ $soles($c['previsional']['de_reintegros']) }}</span>
                @else
                    <span class="detalle">{{ $c['formula_texto'] }}</span>
                @endif
            </td>
            <td>{{ $c['cantidad'] !== null ? number_format((float) $c['cantidad'], $c['codigo'] === 'DESCUENTO_TARDANZA' ? 0 : 2).($c['codigo'] === 'DESCUENTO_TARDANZA' ? ' min' : '') : '—' }}</td>
            <td>—</td><td>—</td><td>{{ $soles($c['monto_vigente']) }}</td><td>—</td>
        </tr>
    @empty
        <tr><td class="izq" colspan="6">Sin descuentos</td></tr>
    @endforelse

    <tr class="seccion"><td colspan="6">Aportes del empleador (informativo)</td></tr>
    @forelse ($aportes as $c)
        <tr><td class="izq">{{ $c['nombre'] }}<span class="detalle">{{ $c['formula_texto'] }}</span></td><td>—</td><td>—</td><td>—</td><td>—</td><td>{{ $soles($c['monto']) }}</td></tr>
    @empty
        <tr><td class="izq" colspan="6">Sin aportes</td></tr>
    @endforelse

    <tr class="totales">
        <td class="izq">TOTALES</td><td>—</td><td>{{ $soles($b['total_ingresos']) }}</td><td>{{ $soles($totalReintegros) }}</td><td>{{ $soles($totalDescuentos) }}</td><td>{{ $soles($totalAportes) }}</td>
    </tr>
</table>

<div class="espacio"></div>
<table class="neto">
    <tr>
        <td class="etiqueta">NETO A PAGAR<br><span style="font-size: 7px; font-weight: normal;">Ingresos + reintegros (brutos) – descuentos</span></td>
        <td class="monto">{{ $soles($neto) }}</td>
    </tr>
</table>
<p style="font-weight: bold;"><span style="color: #9ca3af; font-weight: normal;">SON:</span> {{ $netoEnLetras }}</p>

<table class="tarjeta">
    <tr><th colspan="4">Datos de pago</th></tr>
    <tr>
        <td>Banco: <b>{{ $b['datos_pago']['banco'] ?? '—' }}</b></td>
        <td>Cuenta: <b>{{ $b['datos_pago']['numero_cuenta'] ?? '—' }}</b></td>
        <td>CCI: <b>{{ $b['datos_pago']['cci'] ?? '—' }}</b></td>
        <td>Moneda: <b>{{ ($b['datos_pago']['moneda'] ?? null) === 'USD' ? 'Dólares (US$)' : (($b['datos_pago']['moneda'] ?? null) === 'PEN' ? 'Soles (S/)' : '—') }}</b></td>
    </tr>
</table>

<div class="espacio"></div>
<div class="pie">
    Documento emitido electrónicamente conforme al D.S. N° 001-98-TR y normas complementarias.<br>
    Calculado el {{ $b['calculado_at'] ?? '—' }} · Parámetros: {{ $b['snapshot_parametros_version'] ?? '—' }} · Reglas: {{ $b['snapshot_reglas_version'] ?? '—' }}
</div>
</body>
</html>
