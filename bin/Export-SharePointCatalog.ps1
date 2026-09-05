# Export-SharePointCatalog.ps1
#
# Walk a locally synced or mapped SharePoint folder and write a CSV that the
# Risk Register "SharePoint catalog" page can import (Name, Path, Type, URL).
#
# Examples:
#   .\Export-SharePointCatalog.ps1 -LocalPath "S:\Architectural Projects [Public]"
#   .\Export-SharePointCatalog.ps1 -LocalPath "$env:USERPROFILE\OneDrive - AdventHealth\Architectural Projects [Public]"
#   .\Export-SharePointCatalog.ps1 -LocalPath "Z:\Shared Documents\Architectural Projects [Public]" -OutFile ".\sp-catalog.csv"
#
# Requires: PowerShell 5.1+ (Windows). No Graph / app registration needed for this export.

[CmdletBinding()]
param(
    # Root of the Architectural Projects [Public] folder on disk (mapped drive or OneDrive sync).
    [Parameter(Mandatory = $true)]
    [string] $LocalPath,

    # Where to write the CSV (default: Desktop\sharepoint-catalog-YYYYMMDD-HHmmss.csv).
    [Parameter(Mandatory = $false)]
    [string] $OutFile = "",

    # SharePoint host (no https://).
    [Parameter(Mandatory = $false)]
    [string] $SiteHost = "ahsonline.sharepoint.com",

    # Site path starting with /.
    [Parameter(Mandatory = $false)]
    [string] $SitePath = "/teams/AITTechnologyEngagement",

    # Folder name under Shared Documents (must match the catalog setting in the app).
    [Parameter(Mandatory = $false)]
    [string] $LibraryFolder = "Architectural Projects [Public]",

    # Document library segment in URLs (usually "Shared Documents").
    [Parameter(Mandatory = $false)]
    [string] $LibraryName = "Shared Documents",

    # Max recursion depth under LocalPath (project folders + nested files).
    [Parameter(Mandatory = $false)]
    [int] $MaxDepth = 8
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Encode-SharePointPathSegment([string] $segment) {
    return [Uri]::EscapeDataString($segment)
}

function Build-SharePointUrl {
    param(
        [string] $RelativePath,  # e.g. "My Project/docs/file.pdf" relative to LibraryFolder
        [bool] $IsFolder
    )

    $site = $SitePath.TrimEnd("/")
    if (-not $site.StartsWith("/")) {
        $site = "/" + $site
    }

    $parts = @()
    if ($LibraryFolder -ne "") {
        $parts += $LibraryFolder
    }
    if ($RelativePath -ne "") {
        foreach ($p in ($RelativePath -replace "\\", "/").Split("/") | Where-Object { $_ -ne "" }) {
            $parts += $p
        }
    }

    $encodedSegments = foreach ($p in $parts) { Encode-SharePointPathSegment $p }
    $encodedPath = ($encodedSegments -join "/")

    # Server-relative id path used by Forms/AllItems.aspx for folders
    $idPath = "$site/$LibraryName/" + (($parts -join "/"))

    if ($IsFolder) {
        $encodedId = [Uri]::EscapeDataString($idPath)
        return "https://$SiteHost$site/$([Uri]::EscapeDataString($LibraryName).Replace('%20','%20'))/Forms/AllItems.aspx?id=$encodedId"
    }

    return "https://$SiteHost$site/$([Uri]::EscapeUriString($LibraryName))/$encodedPath"
}

# Fix folder URL builder - use consistent encoding
function Build-ItemUrl {
    param(
        [string] $RelativePath,
        [bool] $IsFolder
    )

    $site = $SitePath.Trim().TrimEnd("/")
    if (-not $site.StartsWith("/")) {
        $site = "/" + $site
    }

    $relParts = @()
    if ($LibraryFolder -ne "") { $relParts += $LibraryFolder }
    foreach ($p in ($RelativePath -replace "\\", "/").Split("/") | Where-Object { $_ -ne "" }) {
        $relParts += $p
    }

    $serverRelative = $site + "/" + $LibraryName + "/" + ($relParts -join "/")

    if ($IsFolder) {
        $formsBase = "https://{0}{1}/{2}/Forms/AllItems.aspx" -f $SiteHost, $site, ($LibraryName -replace " ", "%20")
        return $formsBase + "?id=" + [Uri]::EscapeDataString($serverRelative)
    }

    $fileSegments = foreach ($p in $relParts) { [Uri]::EscapeDataString($p) }
    return ("https://{0}{1}/{2}/{3}" -f $SiteHost, $site, ($LibraryName -replace " ", "%20"), ($fileSegments -join "/"))
}

if (-not (Test-Path -LiteralPath $LocalPath)) {
    throw "LocalPath not found: $LocalPath"
}

$root = (Resolve-Path -LiteralPath $LocalPath).Path
if (-not (Test-Path -LiteralPath $root -PathType Container)) {
    throw "LocalPath must be a folder: $root"
}

if ([string]::IsNullOrWhiteSpace($OutFile)) {
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $OutFile = Join-Path ([Environment]::GetFolderPath("Desktop")) "sharepoint-catalog-$stamp.csv"
}

Write-Host "Scanning: $root"
Write-Host "Output:   $OutFile"

$rows = New-Object System.Collections.Generic.List[object]

function Walk-Folder {
    param(
        [string] $AbsoluteDir,
        [string] $RelativeFromRoot,  # "" at root; "ProjectName/sub" deeper
        [int] $Depth
    )

    if ($Depth -gt $MaxDepth) {
        return
    }

    Get-ChildItem -LiteralPath $AbsoluteDir -Force -ErrorAction SilentlyContinue | ForEach-Object {
        $item = $_
        # Skip OneDrive / Office temp junk
        if ($item.Name -in @(".tmp", "~`$") -or $item.Name.StartsWith("~`$")) {
            return
        }
        if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {
            # Still include OneDrive cloud placeholders; they are valid listing entries
        }

        $rel = if ($RelativeFromRoot -eq "") { $item.Name } else { "$RelativeFromRoot/$($item.Name)" }
        $isDir = $item.PSIsContainer

        $rows.Add([pscustomobject]@{
            Name = $item.Name
            Path = $rel
            Type = if ($isDir) { "Folder" } else { "File" }
            URL  = (Build-ItemUrl -RelativePath $rel -IsFolder:$isDir)
        }) | Out-Null

        if ($isDir) {
            Walk-Folder -AbsoluteDir $item.FullName -RelativeFromRoot $rel -Depth ($Depth + 1)
        }
    }
}

Walk-Folder -AbsoluteDir $root -RelativeFromRoot "" -Depth 1

if ($rows.Count -eq 0) {
    throw "No files or folders found under $root. If this is a OneDrive cloud-only folder, open it in Explorer first so items are visible, or use Files On-Demand listing (items should still appear)."
}

# UTF-8 with BOM helps Excel open the CSV cleanly
$utf8Bom = New-Object System.Text.UTF8Encoding $true
$csvText = ($rows | ConvertTo-Csv -NoTypeInformation) -join "`r`n"
[System.IO.File]::WriteAllText($OutFile, $csvText + "`r`n", $utf8Bom)

$projects = ($rows | Where-Object { $_.Type -eq "Folder" -and ($_.Path -notmatch "/") }).Count
Write-Host ""
Write-Host ("Exported {0} item(s) ({1} top-level project folder(s))." -f $rows.Count, $projects)
Write-Host "Import this file in the app: SharePoint catalog → Import Excel / CSV"
Write-Host $OutFile
