<#
.SYNOPSIS
  Install, verify, remove, or check Windows Task Scheduler jobs for Risk Register
  exception monitor + email sender workers.

.DESCRIPTION
  Registers two repeating tasks (names follow the install folder):
    - <Folder> - Exception Monitor  (bin/exception_monitor.php)
    - <Folder> - Email Sender       (bin/exception_email_sender.php)

.PARAMETER Action
  install | verify | status | uninstall | run-once

.PARAMETER IntervalMinutes
  How often tasks repeat (default: 60). Allowed 15-1440.
#>

[CmdletBinding()]
param(
    [ValidateSet('install', 'uninstall', 'status', 'run-once', 'verify')]
    [string]$Action = 'install',

    [string]$Root = '',

    [string]$Php = '',

    [ValidateRange(15, 1440)]
    [int]$IntervalMinutes = 60,

    [string]$TaskUser = '',

    [switch]$SkipSmoke
)

$ErrorActionPreference = 'Stop'
$script:FailCount = 0
$script:WarnCount = 0
$script:UsedInteractivePrincipal = $false
$script:phpExe = $null
$script:taskMonitor = $null
$script:taskEmail = $null

function Write-Ok([string]$Message) { Write-Host "  [OK]   $Message" -ForegroundColor Green }
function Write-WarnLine([string]$Message) {
    $script:WarnCount++
    Write-Host "  [WARN] $Message" -ForegroundColor Yellow
}
function Write-Fail([string]$Message) {
    $script:FailCount++
    Write-Host "  [FAIL] $Message" -ForegroundColor Red
}
function Write-Info([string]$Message) { Write-Host "  [INFO] $Message" -ForegroundColor Cyan }
function Write-Section([string]$Title) {
    Write-Host ""
    Write-Host "=== $Title ===" -ForegroundColor White
}

function Resolve-AppRoot {
    param([string]$Explicit)
    if ($Explicit -and (Test-Path -LiteralPath $Explicit)) {
        return (Resolve-Path -LiteralPath $Explicit).Path
    }
    $here = $PSScriptRoot
    if (-not $here) { $here = Split-Path -Parent $MyInvocation.MyCommand.Path }
    return (Resolve-Path -LiteralPath (Join-Path $here '..')).Path
}

function Test-UsablePhpExe {
    param([string]$Path)
    if (-not $Path) { return $false }
    if (-not (Test-Path -LiteralPath $Path)) { return $false }
    try {
        $out = & $Path -v 2>&1 | Out-String
        return ($LASTEXITCODE -eq 0 -and $out -match 'PHP')
    } catch {
        return $false
    }
}

function Resolve-PhpExe {
    param([string]$Preferred)
    $candidates = @()
    if ($Preferred) { $candidates += $Preferred }
    $candidates += @(
        'C:\xampp\php\php.exe',
        (Join-Path $env:ProgramFiles 'php\php.exe'),
        'php.exe'
    )
    foreach ($c in $candidates) {
        if ($c -eq 'php.exe') {
            $cmd = Get-Command php.exe -ErrorAction SilentlyContinue
            if ($cmd -and (Test-UsablePhpExe $cmd.Source)) {
                return $cmd.Source
            }
            continue
        }
        if (Test-UsablePhpExe $c) {
            return (Resolve-Path -LiteralPath $c).Path
        }
    }
    throw "php.exe not found. Install XAMPP PHP or pass -Php with a full path."
}

function Get-TaskSafe {
    param([string]$Name)
    try {
        return Get-ScheduledTask -TaskName $Name -ErrorAction Stop
    } catch {
        return $null
    }
}

function Get-TaskNamesFile {
    return (Join-Path $root 'database\exception_worker_tasks.json')
}

function Save-WorkerTaskNames {
    $path = Get-TaskNamesFile
    $dir = Split-Path -Parent $path
    if (-not (Test-Path -LiteralPath $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    @{
        monitor = $script:taskMonitor
        email = $script:taskEmail
        root = $root
        updated_at = (Get-Date).ToString('o')
        interval_minutes = $IntervalMinutes
    } | ConvertTo-Json | Set-Content -LiteralPath $path -Encoding UTF8
}

function Load-WorkerTaskNames {
    $path = Get-TaskNamesFile
    if (Test-Path -LiteralPath $path) {
        try {
            $json = Get-Content -LiteralPath $path -Raw | ConvertFrom-Json
            if ($json.monitor) { $script:taskMonitor = [string]$json.monitor }
            if ($json.email) { $script:taskEmail = [string]$json.email }
        } catch { }
    }
}

function Resolve-TaskNames {
    $leaf = Split-Path -Leaf $root
    if (-not $leaf) { $leaf = 'RiskRegister' }
    $baseMonitor = "$leaf - Exception Monitor"
    $baseEmail = "$leaf - Email Sender"

    $hash = ([System.BitConverter]::ToString(
        [System.Security.Cryptography.SHA1]::Create().ComputeHash(
            [System.Text.Encoding]::UTF8.GetBytes($root.ToLowerInvariant())
        )
    )).Replace('-', '').Substring(0, 6).ToLowerInvariant()

    $existingMonitor = Get-TaskSafe -Name $baseMonitor
    if ($existingMonitor) {
        $wd = ''
        try { $wd = [string]$existingMonitor.Actions[0].WorkingDirectory } catch { }
        if ($wd -and ($wd.TrimEnd('\') -ne $root.TrimEnd('\'))) {
            $baseMonitor = "$leaf-$hash - Exception Monitor"
            $baseEmail = "$leaf-$hash - Email Sender"
        }
    }

    $script:taskMonitor = $baseMonitor
    $script:taskEmail = $baseEmail
    Load-WorkerTaskNames
    if (-not $script:taskMonitor) { $script:taskMonitor = $baseMonitor }
    if (-not $script:taskEmail) { $script:taskEmail = $baseEmail }
}

function Resolve-TaskRunAsUser {
    try {
        $id = [System.Security.Principal.WindowsIdentity]::GetCurrent()
        if ($id -and $id.Name) { return $id.Name }
    } catch { }
    return $null
}

function Get-WorkerPrincipalAttempts {
    $attempts = [System.Collections.Generic.List[object]]::new()

    if ($TaskUser) {
        $attempts.Add(@{
            Label = "user $TaskUser (S4U, runs whether logged on)"
            Principal = (New-ScheduledTaskPrincipal -UserId $TaskUser -LogonType S4U -RunLevel Limited)
        })
    }

    $attempts.Add(@{
        Label = 'SYSTEM (runs whether anyone is logged on)'
        Principal = (New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest)
    })

    $runAs = Resolve-TaskRunAsUser
    if ($runAs -and (-not $TaskUser -or ($runAs.ToLowerInvariant() -ne $TaskUser.Trim().ToLowerInvariant()))) {
        $attempts.Add(@{
            Label = "user $runAs (S4U, runs whether logged on)"
            Principal = (New-ScheduledTaskPrincipal -UserId $runAs -LogonType S4U -RunLevel Limited)
        })
        $attempts.Add(@{
            Label = "user $runAs (Interactive, only while logged on)"
            Principal = (New-ScheduledTaskPrincipal -UserId $runAs -LogonType Interactive -RunLevel Limited)
        })
    }

    return $attempts
}

function Test-TaskBelongsToThisRoot {
    param($Task)
    try {
        $wd = [string]$Task.Actions[0].WorkingDirectory
        if (-not $wd) { return $true }
        return ($wd.TrimEnd('\') -eq $root.TrimEnd('\'))
    } catch {
        return $true
    }
}

function Register-WorkerTask {
    param(
        [string]$Name,
        [string]$Arguments,
        [string]$Description
    )

    $existing = Get-TaskSafe -Name $Name
    if ($existing) {
        if (-not (Test-TaskBelongsToThisRoot $existing)) {
            throw "Task '$Name' already belongs to another folder ($($existing.Actions[0].WorkingDirectory))."
        }
        Unregister-ScheduledTask -TaskName $Name -Confirm:$false
        Write-Info "Replacing existing task for this folder: $Name"
    }

    $action = New-ScheduledTaskAction -Execute $script:phpExe -Argument $Arguments -WorkingDirectory $root
    $start = Get-Date
    $repeatTrigger = New-ScheduledTaskTrigger -Once -At $start `
        -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) `
        -RepetitionDuration (New-TimeSpan -Days 3650)

    $settings = New-ScheduledTaskSettingsSet `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries `
        -StartWhenAvailable `
        -MultipleInstances IgnoreNew `
        -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

    $attempts = @(Get-WorkerPrincipalAttempts)
    $registered = $false
    $lastError = $null
    foreach ($attempt in $attempts) {
        try {
            Register-ScheduledTask `
                -TaskName $Name `
                -Description $Description `
                -Action $action `
                -Trigger $repeatTrigger `
                -Settings $settings `
                -Principal $attempt.Principal | Out-Null
            Write-Ok "Registered: $Name as $($attempt.Label) (every $IntervalMinutes min)"
            if ($attempt.Label -like '*Interactive*') {
                $script:UsedInteractivePrincipal = $true
                Write-WarnLine "This task runs only while that user is logged on."
            }
            $registered = $true
            break
        } catch {
            $lastError = $_
            Write-Info "Could not register as $($attempt.Label): $($_.Exception.Message)"
            $existingAfter = Get-TaskSafe -Name $Name
            if ($existingAfter) {
                Unregister-ScheduledTask -TaskName $Name -Confirm:$false -ErrorAction SilentlyContinue
            }
        }
    }
    if (-not $registered) {
        throw "Failed to register $Name. $($lastError.Exception.Message)"
    }

    Write-Info "Program: $script:phpExe"
    Write-Info "Args:    $Arguments"
    Write-Info "Start in: $root"

    try {
        Start-ScheduledTask -TaskName $Name -ErrorAction Stop
        Write-Ok "Started $Name now"
    } catch {
        $runOut = & schtasks.exe /Run /TN $Name 2>&1
        if ($LASTEXITCODE -eq 0) {
            Write-Ok "Started $Name now via schtasks"
        } else {
            Write-WarnLine "Could not start $Name immediately: $runOut"
        }
    }
}

function Invoke-Preflight {
    Write-Section "Preflight"
    Write-Info "Root: $root"
    if (-not (Get-Command Get-ScheduledTask -ErrorAction SilentlyContinue)) {
        throw "Get-ScheduledTask not available (install RSAT / use Windows with Task Scheduler)."
    }
    Write-Ok "ScheduledTasks cmdlets available"

    $script:phpExe = Resolve-PhpExe -Preferred $Php
    Write-Ok "PHP: $script:phpExe"

    $monitor = Join-Path $root 'bin\exception_monitor.php'
    $sender = Join-Path $root 'bin\exception_email_sender.php'
    if (-not (Test-Path -LiteralPath $monitor)) { throw "Missing $monitor" }
    if (-not (Test-Path -LiteralPath $sender)) { throw "Missing $sender" }
    Write-Ok "Worker scripts present"

    Resolve-TaskNames
    Write-Info "Task names: $($script:taskMonitor) | $($script:taskEmail)"
}

function Show-OneTaskStatus {
    param([string]$Name, [string]$Label)
    $t = Get-TaskSafe -Name $Name
    if (-not $t) {
        Write-Fail "$Label missing ($Name)"
        return
    }
    $info = Get-ScheduledTaskInfo -TaskName $Name -ErrorAction SilentlyContinue
    $state = [string]$t.State
    $enabled = [bool]$t.Settings.Enabled
    $last = if ($info) { [string]$info.LastRunTime } else { '?' }
    $next = if ($info) { [string]$info.NextRunTime } else { '?' }
    $flag = if ($enabled) { 'ENABLED' } else { 'DISABLED' }
    Write-Host "  $Label : $flag / $state"
    Write-Host "           Last=$last  Next=$next"
    Write-Host "           Name=$Name"
}

function Show-Status {
    Write-Section "Task status"
    Resolve-TaskNames
    Show-OneTaskStatus -Name $script:taskMonitor -Label 'Exception Monitor'
    Show-OneTaskStatus -Name $script:taskEmail -Label 'Email Sender'
    if ($script:FailCount -gt 0) { return 1 }
    return 0
}

function Invoke-Verify {
    param([switch]$QuietHeader)
    if (-not $QuietHeader) {
        Write-Section "Verify"
    }
    Resolve-TaskNames
    $ok = $true
    foreach ($pair in @(
        @{ Name = $script:taskMonitor; Label = 'Exception Monitor'; Script = 'exception_monitor.php' },
        @{ Name = $script:taskEmail; Label = 'Email Sender'; Script = 'exception_email_sender.php' }
    )) {
        $t = Get-TaskSafe -Name $pair.Name
        if (-not $t) {
            Write-Fail "$($pair.Label) task missing: $($pair.Name)"
            $ok = $false
            continue
        }
        if (-not (Test-TaskBelongsToThisRoot $t)) {
            Write-Fail "$($pair.Label) points at another folder"
            $ok = $false
            continue
        }
        $exe = ''
        $args = ''
        try {
            $exe = [string]$t.Actions[0].Execute
            $args = [string]$t.Actions[0].Arguments
        } catch { }
        if ($exe -notmatch 'php(\.exe)?$' -and -not (Test-Path -LiteralPath $exe)) {
            Write-WarnLine "$($pair.Label) execute path looks odd: $exe"
        }
        if ($args -notmatch [regex]::Escape($pair.Script)) {
            Write-Fail "$($pair.Label) args do not include $($pair.Script): $args"
            $ok = $false
            continue
        }
        $en = if ($t.Settings.Enabled) { 'enabled' } else { 'disabled' }
        Write-Ok "$($pair.Label) OK ($en)"
    }
    if ($ok) { return 0 }
    return 1
}

function Remove-WorkerTasks {
    Write-Section "Uninstall"
    Resolve-TaskNames
    foreach ($name in @($script:taskMonitor, $script:taskEmail)) {
        $t = Get-TaskSafe -Name $name
        if ($t) {
            Unregister-ScheduledTask -TaskName $name -Confirm:$false
            Write-Ok "Removed $name"
        } else {
            Write-Info "Not present: $name"
        }
    }
    $path = Get-TaskNamesFile
    if (Test-Path -LiteralPath $path) {
        Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue
    }
}

function Invoke-RunOnce {
    if (-not $script:phpExe) {
        $script:phpExe = Resolve-PhpExe -Preferred $Php
    }
    $monitor = Join-Path $root 'bin\exception_monitor.php'
    $sender = Join-Path $root 'bin\exception_email_sender.php'

    Write-Host "Running exception monitor once..."
    & $script:phpExe $monitor
    if ($LASTEXITCODE -ne 0) { throw "exception_monitor exited with code $LASTEXITCODE" }

    Write-Host ""
    Write-Host "Running email sender once..."
    & $script:phpExe $sender
    if ($LASTEXITCODE -ne 0) { throw "exception_email_sender exited with code $LASTEXITCODE" }

    Write-Host ""
    Write-Ok "run-once completed."
}

function Install-WorkerTasks {
    Write-Section "Install Task Scheduler jobs"

    $monitorArgs = "`"$(Join-Path $root 'bin\exception_monitor.php')`""
    $emailArgs = "`"$(Join-Path $root 'bin\exception_email_sender.php')`""

    Register-WorkerTask -Name $script:taskMonitor -Arguments $monitorArgs `
        -Description "Risk Register: detect due exceptions, in-app notices, queue emails"
    Register-WorkerTask -Name $script:taskEmail -Arguments $emailArgs `
        -Description "Risk Register: send queued exception emails via SMTP"

    Save-WorkerTaskNames

    $script:FailCount = 0
    $script:WarnCount = 0
    $verifyCode = Invoke-Verify -QuietHeader
    if ($verifyCode -ne 0) {
        throw "Tasks were registered but verification failed. See [FAIL] lines above."
    }

    if (-not $SkipSmoke) {
        Write-Section "Smoke test (run-once)"
        try {
            Invoke-RunOnce
            Write-Ok "Smoke test completed"
        } catch {
            Write-WarnLine "Smoke test failed: $($_.Exception.Message)"
            Write-WarnLine "Tasks are still scheduled; fix PHP/DB then run: -Action run-once"
        }
    } else {
        Write-Info "SkipSmoke set - not running workers now"
    }

    Write-Section "Summary"
    Write-Ok "Install finished."
    if ($script:UsedInteractivePrincipal) {
        Write-WarnLine "Tasks are Interactive (run while this Windows user is logged on)."
    } else {
        Write-Info "Tasks run whether or not a user is logged on (SYSTEM or S4U)."
    }
    Write-Info "Task Scheduler names:"
    Write-Host "    $($script:taskMonitor)"
    Write-Host "    $($script:taskEmail)"
    Write-Info "Useful commands:"
    Write-Host "    scripts\schedule-exception-workers.bat status"
    Write-Host "    scripts\schedule-exception-workers.bat verify"
    Write-Host "    scripts\schedule-exception-workers.bat run-once"
}

# --- main ---
$root = Resolve-AppRoot -Explicit $Root
$logDir = Join-Path $root 'database'
if (-not (Test-Path -LiteralPath $logDir)) {
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
}
$logPath = Join-Path $logDir 'exception_scheduler_install.log'
Start-Transcript -Path $logPath -Append -ErrorAction SilentlyContinue | Out-Null

$exitCode = 0
try {
    switch ($Action) {
        'install' {
            Invoke-Preflight
            Install-WorkerTasks
        }
        'verify' {
            Invoke-Preflight
            $exitCode = Invoke-Verify
        }
        'uninstall' {
            Invoke-Preflight
            Remove-WorkerTasks
            if ($script:FailCount -gt 0) { $exitCode = 1 }
        }
        'status' {
            Write-Section "Preflight (light)"
            Write-Info "Root: $root"
            if (-not (Get-Command Get-ScheduledTask -ErrorAction SilentlyContinue)) {
                throw "Get-ScheduledTask not available"
            }
            Write-Ok "ScheduledTasks cmdlets available"
            $exitCode = Show-Status
        }
        'run-once' {
            Invoke-Preflight
            Invoke-RunOnce
        }
    }
} catch {
    Write-Host ""
    Write-Fail $_.Exception.Message
    $exitCode = 1
}

Write-Host ""
if ($exitCode -eq 0) {
    Write-Host "Result: SUCCESS" -ForegroundColor Green
} else {
    Write-Host "Result: FAILED (exit $exitCode)" -ForegroundColor Red
}

Stop-Transcript -ErrorAction SilentlyContinue | Out-Null
exit $exitCode
