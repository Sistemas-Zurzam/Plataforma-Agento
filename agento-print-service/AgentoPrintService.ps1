#Requires -Version 5.1
<#
    Agento Print Service — puente local entre el navegador (Agento) y la
    cola de impresión de Windows donde está conectada la Epson L8050.
    Existe porque, según el estudio de viabilidad: (1) Epson Photo+ no tiene
    ninguna API/CLI pública, y (2) Chrome no puede seleccionar impresora,
    bandeja ni tipo de medio por diseño de seguridad del navegador.

    Corre EN LA MISMA PC que la impresora — nunca en el servidor de Agento.
    Es PowerShell puro (viene con Windows, sin SDK ni compilación) — usa
    System.Net.HttpListener para el servidor HTTP y System.Drawing.Printing
    (cargado desde la GAC de .NET Framework, ya presente en todo Windows)
    para enviar el trabajo a la impresora.
#>

param(
    [string]$RutaConfig = (Join-Path $PSScriptRoot 'config.json'),
    [switch]$VistaPrevia,
    [switch]$ValidarControlador
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
. (Join-Path $PSScriptRoot 'PvcLayout.ps1')

if (-not (Test-Path $RutaConfig)) {
    Write-Host "ERROR: no existe $RutaConfig — copia config.example.json a config.json y complétalo." -ForegroundColor Red
    exit 1
}

$config = Get-Content $RutaConfig -Raw | ConvertFrom-Json

$origenesPermitidos = @()
if ($config.OrigenesPermitidos) {
    $origenesPermitidos = @($config.OrigenesPermitidos)
} elseif ($config.OrigenPermitido) {
    # Compatibilidad con config.json creados antes del soporte de producción.
    $origenesPermitidos = @([string]$config.OrigenPermitido)
}
$origenesPermitidos = @($origenesPermitidos | ForEach-Object { ([string]$_).TrimEnd('/') } | Where-Object { $_ })
if (-not $origenesPermitidos.Count) {
    Write-Host 'ERROR: configura OrigenesPermitidos en config.json.' -ForegroundColor Red
    exit 1
}

if ([string]::IsNullOrWhiteSpace($config.Token) -or $config.Token -like '*CAMBIA-ESTO*') {
    Write-Host "ERROR: configura un 'Token' propio en config.json antes de usar este servicio." -ForegroundColor Red
    Write-Host "Genera uno único (una cadena aleatoria larga) — ese mismo valor es el que pegarás" -ForegroundColor Red
    Write-Host 'en Agento la primera vez que uses Imprimir en PVC desde esta PC.' -ForegroundColor Red
    exit 1
}

$puerto = if ($config.Puerto) { $config.Puerto } else { 5588 }
$anchoMm = if ($config.AnchoMm) { $config.AnchoMm } else { 54 }
$altoMm = if ($config.AltoMm) { $config.AltoMm } else { 86 }
# Las antiguas escalas PaginaAnchoMm/PaginaAltoMm ya no se usan.
# La posicion de la tarjeta en la pagina A4 se resuelve en PvcLayout.ps1.

$carpetaLogs = Join-Path $PSScriptRoot 'logs'
New-Item -ItemType Directory -Force -Path $carpetaLogs | Out-Null

function Write-Log {
    param([string]$Mensaje)
    $linea = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') $Mensaje"
    Write-Host $linea
    try {
        Add-Content -Path (Join-Path $carpetaLogs "servicio-$(Get-Date -Format 'yyyy-MM-dd').log") -Value $linea
    } catch {
        # Un fallo escribiendo el log nunca debe impedir imprimir — es
        # trazabilidad, no una condición para el trabajo de impresión.
    }
}

# Comparación en tiempo constante (mismo motivo que hash_equals() en el
# backend de Agento): un token secreto nunca debe compararse con -eq
# directo, que filtra por temporización cuánto del prefijo coincide.
# CryptographicOperations.FixedTimeEquals no está disponible en el .NET
# Framework de Windows PowerShell 5.1, así que se hace a mano: se recorren
# TODOS los bytes siempre (sin salir antes ante la primera diferencia) y se
# acumulan las diferencias con XOR/OR — el resultado es 0 solo si son
# idénticos, y el tiempo de ejecución no depende de en qué byte difieren.
function Test-TokenValido {
    param([string]$Recibido)
    if ([string]::IsNullOrEmpty($Recibido)) { return $false }
    $esperadoBytes = [System.Text.Encoding]::UTF8.GetBytes([string]$config.Token)
    $recibidoBytes = [System.Text.Encoding]::UTF8.GetBytes($Recibido)
    if ($esperadoBytes.Length -ne $recibidoBytes.Length) { return $false }
    $diferencia = 0
    for ($i = 0; $i -lt $esperadoBytes.Length; $i++) {
        $diferencia = $diferencia -bor ($esperadoBytes[$i] -bxor $recibidoBytes[$i])
    }
    return $diferencia -eq 0
}

# Antirebote — mismo espíritu que el antirebote de
# RegistrarMarcacionCarnetService en el backend de Agento: un doble clic o
# un reintento de red no debe mandar el mismo carnet dos veces a la
# impresora física (una tarjeta PVC desperdiciada no se puede deshacer).
$ultimosEnvios = @{}
$ventanaAntireboteSegundos = 5

function Send-JsonResponse {
    param($Response, [int]$StatusCode, [hashtable]$Cuerpo)
    $Response.StatusCode = $StatusCode
    $Response.ContentType = 'application/json'
    $json = $Cuerpo | ConvertTo-Json -Compress
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    $Response.OutputStream.Write($bytes, 0, $bytes.Length)
}

# Genera una cuadrícula con reglas en milímetros del tamaño exacto de la
# tarjeta (AnchoMm x AltoMm), pensada para calibrar DesplazamientoXmm/Ymm sin
# necesitar un carnet real de Agento ni gastar una tarjeta PVC de verdad —
# basta con papel cortado al tamaño. El borde negro llega hasta el extremo
# de la imagen a propósito: si en la tarjeta impresa el borde no se ve
# completo por algún lado, ese lado se está recortando.
function New-ImagenCalibracion {
    param([double]$AnchoMm, [double]$AltoMm)

    $pxPorMm = 10
    $anchoPx = [int]($AnchoMm * $pxPorMm)
    $altoPx = [int]($AltoMm * $pxPorMm)
    $bitmap = New-Object System.Drawing.Bitmap($anchoPx, $altoPx)
    $graficos = [System.Drawing.Graphics]::FromImage($bitmap)

    try {
        $graficos.Clear([System.Drawing.Color]::White)
        $lapizBorde = New-Object System.Drawing.Pen([System.Drawing.Color]::Black, 3)
        $graficos.DrawRectangle($lapizBorde, 1, 1, $anchoPx - 3, $altoPx - 3)

        $lapizMarca = New-Object System.Drawing.Pen([System.Drawing.Color]::Red, 1)
        $fuente = New-Object System.Drawing.Font('Arial', 11)
        $pincelTexto = [System.Drawing.Brushes]::Blue

        for ($mm = 0; $mm -le $AnchoMm; $mm += 5) {
            $x = [int]($mm * $pxPorMm)
            $largo = if ($mm % 10 -eq 0) { 20 } else { 10 }
            $graficos.DrawLine($lapizMarca, $x, 0, $x, $largo)
            $graficos.DrawLine($lapizMarca, $x, $altoPx - $largo, $x, $altoPx)
            if ($mm % 10 -eq 0) { $graficos.DrawString("$mm", $fuente, $pincelTexto, [float]($x + 2), 22.0) }
        }
        for ($mm = 0; $mm -le $AltoMm; $mm += 5) {
            $y = [int]($mm * $pxPorMm)
            $largo = if ($mm % 10 -eq 0) { 20 } else { 10 }
            $graficos.DrawLine($lapizMarca, 0, $y, $largo, $y)
            $graficos.DrawLine($lapizMarca, $anchoPx - $largo, $y, $anchoPx, $y)
            if ($mm % 10 -eq 0) { $graficos.DrawString("$mm", $fuente, $pincelTexto, 24.0, [float]($y + 2)) }
        }

        $centroX = $anchoPx / 2.0
        $centroY = $altoPx / 2.0
        $graficos.DrawLine($lapizBorde, $centroX - 15, $centroY, $centroX + 15, $centroY)
        $graficos.DrawLine($lapizBorde, $centroX, $centroY - 15, $centroX, $centroY + 15)

        $ms = New-Object System.IO.MemoryStream
        $bitmap.Save($ms, [System.Drawing.Imaging.ImageFormat]::Png)
        return , $ms.ToArray()
    } finally {
        $graficos.Dispose()
        $bitmap.Dispose()
    }
}

function Send-CarnetsAImpresora {
    param([object[]]$Carnets, [string]$Impresora, [switch]$Simular, [switch]$PatronCalibracion)

    if (-not $Carnets -or $Carnets.Count -lt 1 -or $Carnets.Count -gt 2) {
        throw 'Se requiere uno o dos carnets.'
    }
    $ranuras = @($Carnets | ForEach-Object { [int]$_.Ranura })
    if ($ranuras | Where-Object { $_ -notin @(1, 2) }) { throw 'Las ranuras validas son 1 y 2.' }
    if (($ranuras | Select-Object -Unique).Count -ne $ranuras.Count) { throw 'No se puede usar dos veces la misma ranura.' }

    $recursos = [Collections.Generic.List[object]]::new()
    $documento = New-Object System.Drawing.Printing.PrintDocument
    try {
        foreach ($carnet in $Carnets) {
            $stream = [IO.MemoryStream]::new([byte[]]$carnet.Bytes)
            try {
                $imagen = [Drawing.Image]::FromStream($stream)
                $configRanura = [pscustomobject]@{
                    Ranura = [int]$carnet.Ranura
                    AnchoMm = $config.AnchoMm
                    AltoMm = $config.AltoMm
                    MargenSeguridadMm = $config.MargenSeguridadMm
                    DesplazamientoXmm = $config.DesplazamientoXmm
                    DesplazamientoYmm = $config.DesplazamientoYmm
                }
                $layout = Get-PvcLayout -Config $configRanura -ImagenAncho $imagen.Width -ImagenAlto $imagen.Height -SinMargen:$PatronCalibracion
                $recursos.Add([pscustomobject]@{ Stream = $stream; Imagen = $imagen; Layout = $layout })
                $stream = $null
                Write-Log "Perfil L8050 A4: ranura=$($layout.Ranura) tarjetaMm=$($layout.Tarjeta) imagen=$($imagen.Width)x$($imagen.Height)."
            } finally {
                if ($stream) { $stream.Dispose() }
            }
        }

        if (-not [string]::IsNullOrWhiteSpace($Impresora)) {
            $documento.PrinterSettings.PrinterName = $Impresora
        }
        if (-not $documento.PrinterSettings.IsValid) {
            throw "Impresora no disponible: $($documento.PrinterSettings.PrinterName)"
        }
        Assert-PvcPrinter -Nombre $documento.PrinterSettings.PrinterName
        $papelA4 = $documento.PrinterSettings.PaperSizes | Where-Object {
            $_.Kind -eq [Drawing.Printing.PaperKind]::A4
        } | Select-Object -First 1
        if (-not $papelA4) { throw 'El perfil de bandeja L8050 requiere A4.' }
        $documento.DefaultPageSettings.PaperSize = $papelA4
        $documento.DefaultPageSettings.Landscape = $false
        $documento.DefaultPageSettings.Margins = [Drawing.Printing.Margins]::new(0, 0, 0, 0)
        $documento.OriginAtMargins = $false
        $documento.PrinterSettings.Copies = 1

        $documento.add_PrintPage({
            param($sender, $e)
            $pa = $e.PageSettings.PrintableArea
            if ([Math]::Abs($e.PageBounds.Width * 0.254 - 210) -gt 1 -or
                [Math]::Abs($e.PageBounds.Height * 0.254 - 297) -gt 1) {
                $e.Cancel = $true
                throw 'El controlador no mantuvo A4 vertical. Se cancela el dibujo.'
            }
            $e.Graphics.PageUnit = [Drawing.GraphicsUnit]::Millimeter
            $e.Graphics.PageScale = 1
            $e.Graphics.TranslateTransform([single](-$pa.X * 0.254), [single](-$pa.Y * 0.254))
            foreach ($recurso in $recursos) {
                Draw-PvcImage -Graphics $e.Graphics -Imagen $recurso.Imagen -Layout $recurso.Layout
            }
            Write-Log "PrintPage: PageBounds=$($e.PageBounds) PrintableArea=$pa Ranuras=$($ranuras -join ',')."
            $e.HasMorePages = $false
        })
        if ($Simular) {
            $documento.PrintController = [Drawing.Printing.PreviewPrintController]::new()
        }
        $documento.Print()
        if ($Simular) {
            $paginas = $documento.PrintController.GetPreviewPageInfo()
            try {
                if ($paginas.Count -ne 1) { throw 'La simulacion debe generar exactamente una pagina.' }
                Write-Log "Simulacion GDI sin imprimir: paginas=$($paginas.Count), tamano=$($paginas[0].PhysicalSize)."
            } finally {
                foreach ($pagina in $paginas) { $pagina.Image.Dispose() }
            }
        }
    } finally {
        foreach ($recurso in $recursos) {
            $recurso.Imagen.Dispose()
            $recurso.Stream.Dispose()
        }
        $documento.Dispose()
    }
}

function Send-CarnetAImpresora {
    param([byte[]]$Bytes, [string]$Impresora, [switch]$Simular, [switch]$PatronCalibracion)
    $ranura = if ($null -ne $config.Ranura) { [int]$config.Ranura } else { 1 }
    Send-CarnetsAImpresora -Carnets @([pscustomobject]@{ Bytes = $Bytes; Ranura = $ranura }) `
        -Impresora $Impresora -Simular:$Simular -PatronCalibracion:$PatronCalibracion
}

if ($ValidarControlador) {
    $bytesValidacion = New-ImagenCalibracion -AnchoMm $anchoMm -AltoMm $altoMm
    Send-CarnetsAImpresora -Carnets @(
        [pscustomobject]@{ Bytes = $bytesValidacion; Ranura = 1 },
        [pscustomobject]@{ Bytes = $bytesValidacion; Ranura = 2 }
    ) -Impresora $config.NombreImpresora -Simular
    exit 0
}

if ($VistaPrevia) {
    $bytesPreview = New-ImagenCalibracion -AnchoMm $anchoMm -AltoMm $altoMm
    $streamPreview = [IO.MemoryStream]::new([byte[]]$bytesPreview)
    $imagenPreview = [Drawing.Image]::FromStream($streamPreview)
    $paginaPreview = [Drawing.Bitmap]::new(2100, 2970)
    $graficoPreview = [Drawing.Graphics]::FromImage($paginaPreview)
    try {
        $layoutPreview = Get-PvcLayout -Config $config -ImagenAncho $imagenPreview.Width -ImagenAlto $imagenPreview.Height
        $graficoPreview.Clear([Drawing.Color]::White)
        $graficoPreview.PageUnit = [Drawing.GraphicsUnit]::Pixel
        $graficoPreview.ScaleTransform(10, 10)
        Draw-PvcImage -Graphics $graficoPreview -Imagen $imagenPreview -Layout $layoutPreview
        $rutaPreview = Join-Path $carpetaLogs 'vista-previa-bandeja.png'
        $paginaPreview.Save($rutaPreview, [Drawing.Imaging.ImageFormat]::Png)
        Write-Host "Vista previa A4 (sin imprimir): $rutaPreview"
        Write-Host "Ranura $($layoutPreview.Ranura); tarjeta en mm: $($layoutPreview.Tarjeta)"
    } finally {
        $graficoPreview.Dispose(); $paginaPreview.Dispose()
        $imagenPreview.Dispose(); $streamPreview.Dispose()
    }
    exit 0
}

$listener = New-Object System.Net.HttpListener
# Solo 127.0.0.1 (no "+"/"*"): así no hace falta reserva de URL ACL ni
# ejecutar como administrador — Windows permite bindear loopback en un
# puerto no privilegiado (>1024) al usuario normal.
$listener.Prefixes.Add("http://127.0.0.1:$puerto/")
$listener.Start()
Write-Log "Agento Print Service escuchando en http://127.0.0.1:$puerto (origenes permitidos: $($origenesPermitidos -join ', '))."

try {
    while ($listener.IsListening) {
        $context = $listener.GetContext()
        $request = $context.Request
        $response = $context.Response

        $origenSolicitud = ([string]$request.Headers['Origin']).TrimEnd('/')
        $origenValido = $origenSolicitud -and $origenSolicitud -in $origenesPermitidos
        if ($origenValido) {
            $response.Headers.Add('Access-Control-Allow-Origin', $origenSolicitud)
            $response.Headers.Add('Vary', 'Origin')
            # Compatibilidad con navegadores que aplican acceso a red local.
            if ($request.Headers['Access-Control-Request-Private-Network'] -eq 'true') {
                $response.Headers.Add('Access-Control-Allow-Private-Network', 'true')
            }
        }
        $response.Headers.Add('Access-Control-Allow-Headers', 'Content-Type, X-Agento-Token')
        $response.Headers.Add('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')

        try {
            if ($request.HttpMethod -eq 'OPTIONS' -and $origenSolicitud -and -not $origenValido) {
                $response.StatusCode = 403
            }
            elseif ($origenSolicitud -and -not $origenValido) {
                Write-Log "Solicitud rechazada: origen no autorizado ($origenSolicitud)."
                Send-JsonResponse -Response $response -StatusCode 403 -Cuerpo @{ error = 'Origen no autorizado.' }
            }
            elseif ($request.HttpMethod -eq 'OPTIONS') {
                $response.StatusCode = 204
            }
            elseif ($request.HttpMethod -eq 'GET' -and $request.Url.AbsolutePath -eq '/health') {
                $predeterminada = (New-Object System.Drawing.Printing.PrinterSettings).PrinterName
                $impresoraConfigurada = if ([string]::IsNullOrWhiteSpace($config.NombreImpresora)) { $predeterminada } else { $config.NombreImpresora }
                Send-JsonResponse -Response $response -StatusCode 200 -Cuerpo @{
                    estado = 'ok'
                    version = 'bandeja-a4-v3-doble'
                    margenSeguridadMm = [double]$config.MargenSeguridadMm
                    ranura = $(if ($null -ne $config.Ranura) { [int]$config.Ranura } else { 1 })
                    impresoraConfigurada = $impresoraConfigurada
                    impresorasDisponibles = @([System.Drawing.Printing.PrinterSettings]::InstalledPrinters)
                }
            }
            elseif ($request.HttpMethod -eq 'POST' -and $request.Url.AbsolutePath -eq '/print/carnet') {
                $tokenRecibido = $request.Headers['X-Agento-Token']
                if (-not (Test-TokenValido $tokenRecibido)) {
                    Write-Log 'Solicitud rechazada: token inválido o ausente.'
                    Send-JsonResponse -Response $response -StatusCode 401 -Cuerpo @{ error = 'Token inválido.' }
                }
                else {
                    $lector = New-Object System.IO.StreamReader($request.InputStream)
                    $cuerpoJson = $lector.ReadToEnd()
                    $lector.Dispose()

                    $solicitud = $null
                    try { $solicitud = $cuerpoJson | ConvertFrom-Json } catch {}

                    $itemsSolicitud = @()
                    if ($solicitud.imagenes) {
                        $itemsSolicitud = @($solicitud.imagenes)
                    } elseif ($solicitud.imagenBase64) {
                        $itemsSolicitud = @([pscustomobject]@{
                            imagenBase64 = $solicitud.imagenBase64
                            colaboradorId = $solicitud.colaboradorId
                            cara = $solicitud.cara
                            ranura = $(if ($null -ne $config.Ranura) { [int]$config.Ranura } else { 1 })
                        })
                    }

                    $ranurasSolicitud = @($itemsSolicitud | ForEach-Object { [int]$_.ranura })
                    $solicitudValida = $solicitud -and $itemsSolicitud.Count -ge 1 -and $itemsSolicitud.Count -le 2 -and
                        -not ($itemsSolicitud | Where-Object { -not $_.imagenBase64 }) -and
                        -not ($ranurasSolicitud | Where-Object { $_ -notin @(1, 2) }) -and
                        (($ranurasSolicitud | Select-Object -Unique).Count -eq $ranurasSolicitud.Count)

                    if (-not $solicitudValida) {
                        Send-JsonResponse -Response $response -StatusCode 400 -Cuerpo @{ error = 'Envia uno o dos carnets, cada uno en una ranura distinta (1 o 2).' }
                    }
                    else {
                        $claveAntirebote = (($itemsSolicitud | ForEach-Object { "$($_.colaboradorId):$($_.cara):$($_.ranura)" }) -join '|')
                        $ahora = Get-Date
                        $esDuplicado = $ultimosEnvios.ContainsKey($claveAntirebote) -and
                            (($ahora - $ultimosEnvios[$claveAntirebote]).TotalSeconds -lt $ventanaAntireboteSegundos)

                        if ($esDuplicado) {
                            Write-Log "Solicitud duplicada ignorada: $claveAntirebote."
                            Send-JsonResponse -Response $response -StatusCode 200 -Cuerpo @{ estado = 'duplicado_ignorado' }
                        }
                        else {
                            try {
                                $carnetsImpresion = @($itemsSolicitud | ForEach-Object {
                                    $base64 = [string]$_.imagenBase64
                                    $indiceComa = $base64.IndexOf(',')
                                    if ($indiceComa -ge 0) { $base64 = $base64.Substring($indiceComa + 1) }
                                    [pscustomobject]@{
                                        Bytes = [Convert]::FromBase64String($base64)
                                        Ranura = [int]$_.ranura
                                    }
                                })
                                Send-CarnetsAImpresora -Carnets $carnetsImpresion -Impresora $config.NombreImpresora
                                $ultimosEnvios[$claveAntirebote] = Get-Date
                                Write-Log "Trabajo enviado a la cola - carnets=$($itemsSolicitud.Count), ranuras=$($ranurasSolicitud -join ',')."
                                Send-JsonResponse -Response $response -StatusCode 200 -Cuerpo @{ estado = 'enviado'; cantidad = $itemsSolicitud.Count }
                            }
                            catch [FormatException] {
                                Send-JsonResponse -Response $response -StatusCode 400 -Cuerpo @{ error = 'Alguna imagen no es un base64 valido.' }
                            }
                            catch {
                                Write-Log "Error al imprimir: $_"
                                Send-JsonResponse -Response $response -StatusCode 500 -Cuerpo @{ error = 'No se pudo enviar el trabajo a la impresora.' }
                            }
                        }
                    }
                }
            }
            elseif ($request.HttpMethod -eq 'POST' -and $request.Url.AbsolutePath -eq '/print/calibracion') {
                $tokenRecibido = $request.Headers['X-Agento-Token']
                if (-not (Test-TokenValido $tokenRecibido)) {
                    Write-Log 'Solicitud de calibración rechazada: token inválido o ausente.'
                    Send-JsonResponse -Response $response -StatusCode 401 -Cuerpo @{ error = 'Token inválido.' }
                }
                else {
                    try {
                        $bytesPatron = New-ImagenCalibracion -AnchoMm $anchoMm -AltoMm $altoMm
                        Send-CarnetAImpresora -Bytes $bytesPatron -Impresora $config.NombreImpresora -PatronCalibracion
                        Write-Log 'Patron de calibracion enviado a la cola; verificar resultado fisico.'
                        Send-JsonResponse -Response $response -StatusCode 200 -Cuerpo @{ estado = 'enviado' }
                    }
                    catch {
                        Write-Log "Error al imprimir patrón de calibración: $_"
                        Send-JsonResponse -Response $response -StatusCode 500 -Cuerpo @{ error = 'No se pudo imprimir el patrón de calibración.' }
                    }
                }
            }
            else {
                $response.StatusCode = 404
            }
        }
        catch {
            Write-Log "Error inesperado atendiendo la solicitud: $_"
            try { $response.StatusCode = 500 } catch {}
        }
        finally {
            $response.Close()
        }
    }
}
finally {
    $listener.Stop()
    $listener.Close()
}
