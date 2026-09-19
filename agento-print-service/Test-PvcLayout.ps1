#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
. (Join-Path $PSScriptRoot 'PvcLayout.ps1')

function Assert-True { param([bool]$Condicion, [string]$Mensaje)
    if (-not $Condicion) { throw $Mensaje }
}

foreach ($ranura in @(1, 2)) {
    $cfg = [pscustomobject]@{ Ranura = $ranura; AnchoMm = 54; AltoMm = 86 }
    $layout = Get-PvcLayout $cfg 540 860
    Assert-True ($layout.Dibujo.Width -eq 54 -and $layout.Dibujo.Height -eq 86) 'Se altero el tamano de la tarjeta.'
    $cuadrado = Get-PvcLayout $cfg 600 600
    Assert-True ($cuadrado.Dibujo.Width -eq $cuadrado.Dibujo.Height) 'Se deformo la imagen.'
    Assert-True ($cuadrado.Dibujo.Top -gt $layout.Tarjeta.Top) 'Falta centrado vertical.'

    # Paint through the same function used by PrintPage. Check that ink is
    # restricted to the selected slot, including when the other is empty.
    $pagina = [Drawing.Bitmap]::new(2100, 2970)
    $imagen = [Drawing.Bitmap]::new(540, 860)
    $gImagen = [Drawing.Graphics]::FromImage($imagen)
    $gPagina = [Drawing.Graphics]::FromImage($pagina)
    try {
        $gImagen.Clear([Drawing.Color]::Black)
        $gPagina.Clear([Drawing.Color]::White)
        $gPagina.ScaleTransform(10, 10)
        Draw-PvcImage $gPagina $imagen $layout
        $centroX = [int](($layout.Tarjeta.X + 27) * 10)
        $centroY = [int](($layout.Tarjeta.Y + 43) * 10)
        Assert-True ($pagina.GetPixel($centroX, $centroY).R -eq 0) 'La ranura seleccionada quedo vacia.'
        $otroX = if ($ranura -eq 1) { 1127 } else { 375 }
        Assert-True ($pagina.GetPixel($otroX, 820).R -eq 255) 'Se dibujo en la otra ranura.'
        Assert-True ($pagina.GetPixel(100, 100).R -eq 255) 'Se dibujo fuera de la tarjeta.'
    } finally {
        $gPagina.Dispose(); $gImagen.Dispose(); $pagina.Dispose(); $imagen.Dispose()
    }
}

$cfgMargen = [pscustomobject]@{ Ranura = 2; MargenSeguridadMm = 1 }
$seguro = Get-PvcLayout $cfgMargen 540 860
Assert-True ([Math]::Abs($seguro.Dibujo.Width - 52) -lt 0.001) 'Margen horizontal incorrecto.'
Assert-True ([Math]::Abs($seguro.Dibujo.Width / $seguro.Dibujo.Height - 54.0 / 86) -lt 0.0001) 'El margen deforma el carnet.'
Assert-True ($seguro.Dibujo.Top - $seguro.Tarjeta.Top -ge 1) 'Margen vertical insuficiente.'
$patron = Get-PvcLayout $cfgMargen 540 860 -SinMargen
Assert-True ($patron.Dibujo.Width -eq 54 -and $patron.Dibujo.Height -eq 86) 'Se altero la escala de las reglas de calibracion.'
$cfg = [pscustomobject]@{ Ranura = 2; DesplazamientoXmm = 1.5; DesplazamientoYmm = -2 }
$ajustado = Get-PvcLayout $cfg 540 860
Assert-True ([Math]::Abs($ajustado.Tarjeta.X - 87.2) -lt 0.001) 'Offset X incorrecto.'
Assert-True ([Math]::Abs($ajustado.Tarjeta.Y - 36.9) -lt 0.001) 'Offset Y incorrecto.'
foreach ($invalido in @([pscustomobject]@{Ranura=3}, [pscustomobject]@{Ranura=2; DesplazamientoXmm=200})) {
    $rechazado = $false
    try { Get-PvcLayout $invalido 540 860 | Out-Null } catch { $rechazado = $true }
    Assert-True $rechazado 'Se acepto una configuracion invalida.'
}
Write-Host 'OK: ambas ranuras, proporcion, centrado, offsets, recorte y configuraciones invalidas. Sin impresiones fisicas.'
