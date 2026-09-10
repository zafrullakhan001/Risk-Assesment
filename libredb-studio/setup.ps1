# One-time setup: extract the standalone Windows release into ./payload
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Zip = Join-Path $Root 'libredb-studio-standalone-0.15.0-win32-x64.zip'
$Payload = Join-Path $Root 'payload'
$Url = 'https://github.com/libredb/libredb-studio/releases/download/0.15.0/libredb-studio-standalone-0.15.0-win32-x64.zip'

New-Item -ItemType Directory -Force -Path $Payload, (Join-Path $Root 'config'), (Join-Path $Root 'data') | Out-Null

if (-not (Test-Path $Zip)) {
    Write-Host "Downloading LibreDB Studio 0.15.0..."
    Invoke-WebRequest -Uri $Url -OutFile $Zip -UseBasicParsing
}

Write-Host "Extracting to $Payload ..."
$Tar = Join-Path $env:SystemRoot 'System32\tar.exe'
& $Tar -xf $Zip -C $Payload

Write-Host "Done. Start with: powershell -ExecutionPolicy Bypass -File start.ps1"
