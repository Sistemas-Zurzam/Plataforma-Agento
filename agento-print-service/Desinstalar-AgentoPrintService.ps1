#Requires -Version 5.1
$ErrorActionPreference = 'Stop'
$nombreTarea = 'Agento Print Service'
$tarea = Get-ScheduledTask -TaskName $nombreTarea -ErrorAction SilentlyContinue
if ($tarea) {
    Stop-ScheduledTask -TaskName $nombreTarea -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $nombreTarea -Confirm:$false
    Write-Host 'Tarea automatica eliminada. Los archivos y config.json se conservaron.'
} else {
    Write-Host 'La tarea automatica no estaba instalada.'
}
