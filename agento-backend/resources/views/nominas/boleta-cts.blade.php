{{-- Boleta de CTS (hoja de liquidación del depósito, D.S. 001-97-TR art. 29).
     dompdf: CSS 2.1 y tablas. Una página por colaborador. Colores: tokens agento-blue. --}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Liquidación de depósito de CTS</title>
<style>
    @page { margin: 26px 30px; }
    * { font-family: "DejaVu Sans", sans-serif; }
    body { font-size: 9.5px; color: #1f2937; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    .pagina { page-break-after: always; }
    .pagina:last-child { page-break-after: auto; }
    .marca { font-size: 15px; font-weight: bold; color: #014693; }
    .sub { font-size: 7px; color: #9ca3af; letter-spacing: 1px; text-transform: uppercase; }
    .titulo { font-size: 15px; font-weight: bold; text-align: center; }
    .empresa { background: #f9fafb; border-top: 1px solid #f3f4f6; border-bottom: 1px solid #f3f4f6; }
    .empresa td { padding: 6px 4px; }
    .tarjeta { border: 1px solid #e5e7eb; }
    .tarjeta th { background: #014693; color: #fff; text-align: left; padding: 4px 6px; font-size: 8px; text-transform: uppercase; }
    .tarjeta td { padding: 3px 6px; border-bottom: 1px solid #f3f4f6; }
    .tarjeta td.valor { text-align: right; font-weight: bold; }
    .total td { background: #e6eefb; color: #001a3d; font-weight: bold; }
    .monto td { padding: 9px; }
    .monto .etiqueta { background: #001a3d; color: #fff; font-weight: bold; font-size: 11px; }
    .monto .valor { background: #e6eefb; color: #001a3d; font-weight: bold; font-size: 15px; text-align: right; }
    .firmas td { width: 50%; padding-top: 46px; text-align: center; font-size: 8px; color: #4b5563; vertical-align: top; }
    .linea { border-top: 1px solid #9ca3af; width: 70%; margin: 0 auto 3px auto; }
    .pie { color: #9ca3af; font-size: 7px; background: #f9fafb; padding: 6px; }
    .espacio { height: 9px; }
</style>
</head>
<body>
@foreach ($paginas as $p)
<div class="pagina">
    <table>
        <tr>
            <td style="width: 32%; padding: 8px 0;"><span class="marca">AGENTO</span><br><span class="sub">Sistema de Gestión de Personal</span></td>
            <td class="titulo">{{ $p['titulo'] }}<br><span class="sub" style="font-size: 8px;">Período {{ $p['periodo'] }}</span></td>
            <td style="width: 32%; text-align: right;">@if ($logo)<img src="{{ $logo }}" style="max-height: 34px; max-width: 130px;">@endif</td>
        </tr>
    </table>

    <table class="empresa">
        <tr><td><b style="font-size: 10px;">{{ $p['empresa']['nombre'] }}</b><br>RUC: {{ $p['empresa']['ruc'] ?? '—' }} · {{ $p['empresa']['direccion'] ?? '—' }}</td></tr>
    </table>

    <div class="espacio"></div>
    <table>
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 4px;">
                <table class="tarjeta">
                    <tr><th colspan="2">Datos del trabajador</th></tr>
                    <tr><td>Apellidos y nombres</td><td class="valor">{{ $p['trabajador']['nombre'] }}</td></tr>
                    <tr><td>Documento</td><td class="valor">{{ $p['trabajador']['documento'] ?? '—' }}</td></tr>
                    <tr><td>Cargo</td><td class="valor">{{ $p['trabajador']['cargo'] ?? '—' }}</td></tr>
                    <tr><td>Fecha de ingreso</td><td class="valor">{{ $p['trabajador']['fecha_ingreso'] ?? '—' }}</td></tr>
                </table>
            </td>
            <td style="width: 50%; vertical-align: top; padding-left: 4px;">
                <table class="tarjeta">
                    <tr><th colspan="2">Tiempo de servicio computable</th></tr>
                    <tr><td>Período</td><td class="valor">{{ $p['periodo'] }}</td></tr>
                    <tr><td>Meses</td><td class="valor">{{ $p['meses'] }}</td></tr>
                    <tr><td>Días</td><td class="valor">{{ $p['dias'] }}</td></tr>
                    <tr><td>Total días computables</td><td class="valor">{{ $p['dias_computables'] }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="espacio"></div>
    <table class="tarjeta">
        <tr><th>Remuneración computable</th><th style="text-align: right;">Importe (S/)</th></tr>
        @foreach ($p['base'] as $linea)
            <tr><td>{{ $linea['concepto'] }}</td><td class="valor">{{ number_format($linea['monto'], 2) }}</td></tr>
        @endforeach
        <tr class="total"><td>Total remuneración computable</td><td class="valor">{{ number_format($p['base_total'], 2) }}</td></tr>
    </table>

    <div class="espacio"></div>
    <table class="tarjeta">
        <tr><th colspan="2">Cálculo</th></tr>
        <tr><td>Remuneración computable / 360 × {{ $p['dias_computables'] }} días{{ $p['tasa'] < 1 ? ' × '.rtrim(rtrim(number_format($p['tasa'] * 100, 2), '0'), '.').'% (régimen pequeña empresa)' : '' }}</td>
            <td class="valor">S/ {{ number_format($p['monto'], 2) }}</td></tr>
    </table>

    <div class="espacio"></div>
    <table class="monto">
        <tr><td class="etiqueta">CTS A DEPOSITAR</td><td class="valor">S/ {{ number_format($p['monto'], 2) }}</td></tr>
    </table>

    <div class="espacio"></div>
    <table class="tarjeta">
        <tr><th colspan="4">Datos del depósito</th></tr>
        <tr>
            <td>Entidad: <b>{{ $p['deposito']['entidad'] ?? 'Pendiente' }}</b></td>
            <td>Cuenta CTS: <b>{{ $p['deposito']['cuenta'] ?? '—' }}</b></td>
            <td>Fecha de depósito: <b>{{ $p['deposito']['fecha'] ?? 'Pendiente' }}</b></td>
            <td>Moneda: <b>{{ $p['moneda'] }}</b></td>
        </tr>
        @if ($p['deposito']['referencia'])
            <tr><td colspan="4">N.º de operación: <b>{{ $p['deposito']['referencia'] }}</b> · Importe depositado: <b>S/ {{ number_format($p['deposito']['importe'], 2) }}</b></td></tr>
        @endif
    </table>

    <table class="firmas">
        <tr>
            <td><div class="linea"></div>Empleador</td>
            <td><div class="linea"></div>Trabajador<br>{{ $p['trabajador']['nombre'] }}</td>
        </tr>
    </table>

    <div class="espacio"></div>
    <div class="pie">Liquidación del depósito de la Compensación por Tiempo de Servicios conforme al D.S. N° 001-97-TR (art. 29) y normas complementarias.</div>
</div>
@endforeach
</body>
</html>
