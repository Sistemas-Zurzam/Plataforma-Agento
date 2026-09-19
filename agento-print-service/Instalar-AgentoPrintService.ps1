#Requires -Version 5.1
[CmdletBinding()]
param(
    [string]$DominioAgento = 'https://dev.agento.com.pe',
    [string]$NombreImpresora = 'EPSON L8050 Series'
)

$ErrorActionPreference = 'Stop'
$nombreTarea = 'Agento Print Service'
$rutaServicio = Join-Path $PSScriptRoot 'AgentoPrintService.ps1'
$rutaConfig = Join-Path $PSScriptRoot 'config.json'
$rutaEjemplo = Join-Path $PSScriptRoot 'config.example.json'

if (-not (Test-Path $rutaServicio)) { throw "No se encontro $rutaServicio" }
if (-not (Test-Path $rutaConfig)) {
    Copy-Item -LiteralPath $rutaEjemplo -Destination $rutaConfig
    $config = Get-Content $rutaConfig -Raw | ConvertFrom-Json
    $bytesToken = New-Object byte[] 32
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytesToken)
    $config.Token = [Convert]::ToBase64String($bytesToken)
    $config.NombreImpresora = $NombreImpresora
    $config.OrigenesPermitidos = @($DominioAgento.TrimEnd('/'), 'http://localhost:5173')
    $config.PSObject.Properties.Remove('OrigenPermitido')
    $config | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath $rutaConfig -Encoding UTF8
    Write-Host 'Se creo config.json con un token local nuevo.' -ForegroundColor Green
} else {
    Write-Host 'Se conserva el config.json existente.' -ForegroundColor Yellow
}

$powershell = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
$argumentos = "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$rutaServicio`""
$accion = New-ScheduledTaskAction -Execute $powershell -Argument $argumentos -WorkingDirectory $PSScriptRoot
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Limited
$ajustes = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask -TaskName $nombreTarea -Action $accion -Trigger $trigger -Principal $principal -Settings $ajustes -Description 'Puente local de Agento para imprimir carnets PVC en Epson L8050.' -Force | Out-Null
Start-ScheduledTask -TaskName $nombreTarea
Start-Sleep -Seconds 2

try {
    $salud = Invoke-RestMethod -Uri 'http://127.0.0.1:5588/health' -TimeoutSec 5
    Write-Host "Instalado y activo: $($salud.version), impresora $($salud.impresoraConfigurada)." -ForegroundColor Green
} catch {
    Write-Warning 'La tarea se creo, pero /health no respondio. Revisa config.json y el Visor de eventos/Tareas programadas.'
}

Write-Host 'La tarea arrancara automáticamente al iniciar sesion. No hace falta dejar PowerShell abierto.'
