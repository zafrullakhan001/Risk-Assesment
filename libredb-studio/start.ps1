# Start LibreDB Studio (local standalone, no Docker).
# Open http://localhost:3000 after the server prints "Ready".

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Payload = Join-Path $Root 'payload'
$NodeExe = Join-Path $Payload 'node\node.exe'
$ServerJs = Join-Path $Payload 'server.js'
$LauncherExe = Join-Path $Payload 'libredb-studio.exe'

if (-not (Test-Path $ServerJs)) {
    Write-Error "Payload not found. Run: powershell -ExecutionPolicy Bypass -File setup.ps1"
    exit 1
}

$env:PORT = if ($env:PORT) { $env:PORT } else { '3000' }
$env:HOSTNAME = if ($env:HOSTNAME) { $env:HOSTNAME } else { '127.0.0.1' }
$env:STORAGE_PROVIDER = 'sqlite'
$env:STORAGE_SQLITE_PATH = Join-Path $Root 'data\libredb-storage.db'
$env:SEED_CONFIG_PATH = Join-Path $Root 'config\seed-connections.yaml'
$env:SQLITE_EMBEDDED_SAMPLE = 'false'
$env:LIBREDB_EMBEDDED_SAMPLE = 'false'

New-Item -ItemType Directory -Force -Path (Join-Path $Root 'data') | Out-Null

Write-Host "LibreDB Studio -> http://$($env:HOSTNAME):$($env:PORT)"
Write-Host "SQLite: database/risk_assessment.sqlite"
Write-Host ""

if (Test-Path $LauncherExe) {
    & $LauncherExe
} elseif (Test-Path $NodeExe) {
    & $NodeExe $ServerJs
} else {
    Write-Error 'Neither libredb-studio.exe nor bundled node.exe found in payload.'
    exit 1
}
