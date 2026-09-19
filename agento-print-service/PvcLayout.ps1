# Pure geometry shared by physical printing and the offline preview.
# Initial slot centers derived from the L8050 reference linked in README.
# They still require one clean-card verification on the actual tray.
function Assert-PvcPrinter {
    param([string]$Nombre)
    Add-Type -AssemblyName System.Printing
    $servidor = [System.Printing.LocalPrintServer]::new()
    $cola = $null
    $lector = $null
    try {
        $cola = $servidor.GetPrintQueue($Nombre)
        if ($cola.QueueDriver.Name -notmatch 'L8050') {
            throw 'Este perfil corresponde a la Epson L8050 con bandeja original.'
        }
        $lector = [IO.StreamReader]::new($cola.UserPrintTicket.GetXmlStream())
        [xml]$ticket = $lector.ReadToEnd()
        $opciones = @{}
        foreach ($feature in $ticket.SelectNodes('//*[local-name()="Feature"]')) {
            $opciones[($feature.GetAttribute('name') -split ':')[-1]] = ($feature.Option.GetAttribute('name') -split ':')[-1]
        }
        if ($opciones.PageInputBin -ne 'Manual' -or $opciones.PageMediaType -notin @('PVCIDCard', 'PVCIDCardBorderless')) {
            throw 'Configura la bandeja disco/tarjeta ID y el tipo PVC en Preferencias de impresion de Epson.'
        }
    } finally {
        if ($lector) { $lector.Dispose() }
        if ($cola) { $cola.Dispose() }
        $servidor.Dispose()
    }
}

function Get-PvcLayout {
    param([object]$Config, [double]$ImagenAncho, [double]$ImagenAlto, [switch]$SinMargen)

    $ranura = if ($null -ne $Config.Ranura) { [int]$Config.Ranura } else { 1 }
    if ($ranura -notin @(1, 2)) { throw 'Ranura debe ser 1 (izquierda) o 2 (derecha).' }
    $ancho = if ($Config.AnchoMm) { [double]$Config.AnchoMm } else { 54.0 }
    $alto = if ($Config.AltoMm) { [double]$Config.AltoMm } else { 86.0 }
    if ([Math]::Abs($ancho - 54) -gt 1 -or [Math]::Abs($alto - 86) -gt 1) {
        throw 'Este perfil es para tarjetas verticales de 54 x 86 mm en la bandeja original L8050.'
    }
    if ($ImagenAncho -le 0 -or $ImagenAlto -le 0) { throw 'La imagen no tiene dimensiones validas.' }
    $x = if ($ranura -eq 1) { 10.5 } else { 85.7 }
    $y = if ($ranura -eq 1) { 39.0 } else { 38.9 }
    $x += [double]$Config.DesplazamientoXmm
    $y += [double]$Config.DesplazamientoYmm
    if ($x -lt 0 -or $y -lt 0 -or $x + $ancho -gt 210 -or $y + $alto -gt 297) {
        throw 'Los desplazamientos colocan la tarjeta fuera de la pagina A4.'
    }

    # Contain: never stretch the portrait or crop a barcode to fill the card.
    $margen = if ($null -ne $Config.MargenSeguridadMm) { [double]$Config.MargenSeguridadMm } else { 0.0 }
    if ([double]::IsNaN($margen) -or [double]::IsInfinity($margen) -or $margen -lt 0 -or $margen -gt 3) {
        throw 'MargenSeguridadMm debe estar entre 0 y 3 mm.'
    }
    # Calibration rulers must keep their physical millimetre scale.
    if ($SinMargen) { $margen = 0.0 }
    $escala = [Math]::Min(($ancho - 2 * $margen) / $ImagenAncho, ($alto - 2 * $margen) / $ImagenAlto)
    $dibujoAncho = $ImagenAncho * $escala
    $dibujoAlto = $ImagenAlto * $escala
    [pscustomobject]@{
        Ranura = $ranura
        Tarjeta = [System.Drawing.RectangleF]::new($x, $y, $ancho, $alto)
        Dibujo = [System.Drawing.RectangleF]::new(
            ($x + ($ancho - $dibujoAncho) / 2), ($y + ($alto - $dibujoAlto) / 2),
            $dibujoAncho, $dibujoAlto)
    }
}

function Draw-PvcImage {
    param([System.Drawing.Graphics]$Graphics, [System.Drawing.Image]$Imagen, [object]$Layout)
    $estado = $Graphics.Save()
    try {
        $Graphics.SetClip($Layout.Tarjeta, [System.Drawing.Drawing2D.CombineMode]::Intersect)
        $origen = [System.Drawing.RectangleF]::new(0, 0, $Imagen.Width, $Imagen.Height)
        $Graphics.DrawImage($Imagen, [System.Drawing.RectangleF]$Layout.Dibujo,
            $origen, [System.Drawing.GraphicsUnit]::Pixel)
    } finally { $Graphics.Restore($estado) }
}
