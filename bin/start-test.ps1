<#
  Avvia l'ambiente locale di LogiDock (senza Docker):
    1. accende MySQL se non e' gia' acceso
    2. applica le migrazioni
    3. avvia il server PHP su http://localhost:8000

  Uso:
    .\bin\start-test.ps1            # database di TEST (logidock_test)
    .\bin\start-test.ps1 -Dev       # database di sviluppo (logidock)
    .\bin\start-test.ps1 -Port 8080

  Nota: logidock_test non ha dati demo (non si fa il seed): serve per le prove
  HTTP che creano arrivi/viaggi, perche' i movimenti dei bancali non si cancellano.
#>
param(
    [switch]$Dev,
    [int]$Port = 8000
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$mysqlBin = 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe'
$mysqlIni = Join-Path $env:LOCALAPPDATA 'LogiDockMySQL\my.ini'

# Le variabili d'ambiente vincono sul file .env
$env:DB_NAME = if ($Dev) { 'logidock' } else { 'logidock_test' }
Write-Host "Database: $($env:DB_NAME)" -ForegroundColor Cyan

function Test-MySqlUp {
    [bool](Get-NetTCPConnection -LocalPort 3306 -State Listen -ErrorAction SilentlyContinue)
}

# 1. MySQL
if (Test-MySqlUp) {
    Write-Host 'MySQL gia'' acceso.'
} else {
    Write-Host 'Avvio MySQL in una nuova finestra (non chiuderla finche'' lavori)...'
    Start-Process -FilePath $mysqlBin -ArgumentList "--defaults-file=`"$mysqlIni`"", '--console'
    for ($i = 0; $i -lt 30 -and -not (Test-MySqlUp); $i++) { Start-Sleep -Seconds 1 }
    if (-not (Test-MySqlUp)) { throw 'MySQL non e'' partito entro 30 secondi.' }
    Write-Host 'MySQL pronto.'
}

# 2. Migrazioni
Set-Location $root
php bin/migrate.php
if ($LASTEXITCODE -ne 0) { throw 'Migrazioni fallite.' }

# 3. Server PHP (Ctrl+C per fermarlo)
Write-Host "Server su http://localhost:$Port  (Ctrl+C per fermare)" -ForegroundColor Green
php -S "localhost:$Port" -t public
