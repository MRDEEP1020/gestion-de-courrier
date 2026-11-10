# Taches automatiques de GEC sur un poste Windows (2026-11-09, voir
# ARCHITECTURE.md > "Taches automatiques en local (Windows)").
#
# Installe deux taches du Planificateur de taches Windows, pour l'utilisateur
# courant, qui tournent en arriere-plan sans fenetre (php-win.exe) des que la
# session est ouverte, et reprennent apres un redemarrage :
#   - "GEC - planificateur" : php artisan schedule:run chaque minute (le meme
#     geste que le cron de production) ;
#   - "GEC - file d'attente" : php artisan queue:work sur toutes les files
#     (ocr, indexation, replication, default), relance chaque minute, ce qui
#     recharge aussi le code modifie.
#
# Usage (depuis la racine du projet) :
#   powershell -ExecutionPolicy Bypass -File .\taches-windows.ps1
#   powershell -ExecutionPolicy Bypass -File .\taches-windows.ps1 -Desinstaller
#
# En production (serveur Linux), utiliser le cron et Horizon decrits dans
# ARCHITECTURE.md, pas ce script.

param([switch]$Desinstaller)

$ErrorActionPreference = 'Stop'
$projet = $PSScriptRoot
$noms = @('GEC - planificateur', "GEC - file d'attente")

if ($Desinstaller) {
    foreach ($nom in $noms) {
        if (Get-ScheduledTask -TaskName $nom -ErrorAction SilentlyContinue) {
            Unregister-ScheduledTask -TaskName $nom -Confirm:$false
            Write-Output "Tache supprimee : $nom"
        }
    }
    return
}

# php-win.exe : meme PHP que la ligne de commande, sans fenetre de console.
$php = (& php -r 'echo PHP_BINARY;')
$phpWin = Join-Path (Split-Path $php) 'php-win.exe'
if (-not (Test-Path $phpWin)) {
    throw "php-win.exe introuvable a cote de $php"
}

$taches = @(
    @{ Nom = $noms[0]; Arguments = 'artisan schedule:run'; Limite = (New-TimeSpan -Minutes 30) },
    @{ Nom = $noms[1]; Arguments = 'artisan queue:work --queue=ocr,indexation,replication,default --max-time=55 --sleep=3 --tries=3'; Limite = (New-TimeSpan -Hours 2) }
)

foreach ($tache in $taches) {
    $action = New-ScheduledTaskAction -Execute $phpWin -Argument $tache.Arguments -WorkingDirectory $projet

    # Chaque minute, sans fin ; plus un declenchement a l'ouverture de session
    # pour repartir tout de suite apres un redemarrage.
    $chaqueMinute = New-ScheduledTaskTrigger -Once -At (Get-Date).AddSeconds(5) -RepetitionInterval (New-TimeSpan -Minutes 1)
    $ouverture = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME

    # IgnoreNew : jamais deux exemplaires en meme temps (un long job de la
    # file d'attente fait simplement sauter les declenchements suivants).
    $reglages = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable `
        -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit $tache.Limite

    Register-ScheduledTask -TaskName $tache.Nom -Action $action -Trigger @($chaqueMinute, $ouverture) `
        -Settings $reglages -Description 'GEC - voir taches-windows.ps1 a la racine du projet' -Force | Out-Null

    Write-Output "Tache installee : $($tache.Nom) -> $phpWin $($tache.Arguments)"
}
