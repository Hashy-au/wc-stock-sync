<#
.SYNOPSIS
Build a release zip of WC Stock Sync that extracts correctly on Linux.

Uses bsdtar (tar.exe) — NEVER Compress-Archive, which writes backslash
entry names that break extraction on Linux hosts.

.USAGE
powershell -ExecutionPolicy Bypass -File scripts\build-release.ps1 [-Publish]

-Publish  also creates a GitHub release (gh release create v<version>)
          with dist\wc-stock-sync.zip attached.
#>
param(
    [switch]$Publish
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

# --- Version consistency: header, constant, readme stable tag must agree.
$main = Get-Content (Join-Path $root 'wc-stock-sync.php') -Raw
if ($main -notmatch '(?m)^\s*\*\s*Version:\s*([0-9.]+)') { throw 'No Version header found.' }
$verHeader = $Matches[1]
if ($main -notmatch "define\('WC_STOCK_SYNC_VERSION',\s*'([0-9.]+)'\)") { throw 'No WC_STOCK_SYNC_VERSION define found.' }
$verConst = $Matches[1]
$readme = Get-Content (Join-Path $root 'readme.txt') -Raw
if ($readme -notmatch '(?m)^Stable tag:\s*([0-9.]+)') { throw 'No Stable tag found in readme.txt.' }
$verReadme = $Matches[1]

if (($verHeader -ne $verConst) -or ($verHeader -ne $verReadme)) {
    throw "Version mismatch: header=$verHeader constant=$verConst readme=$verReadme"
}
$version = $verHeader
Write-Host "Building wc-stock-sync $version"

# --- Stage a clean copy (runtime files only).
$stage = Join-Path $env:TEMP ("wcss-build-" + [guid]::NewGuid().ToString('N'))
$pkg = Join-Path $stage 'wc-stock-sync'
New-Item -ItemType Directory -Force $pkg | Out-Null

Copy-Item (Join-Path $root 'wc-stock-sync.php') $pkg
Copy-Item (Join-Path $root 'readme.txt') $pkg
Copy-Item (Join-Path $root 'uninstall.php') $pkg
Copy-Item (Join-Path $root 'includes') $pkg -Recurse

# --- Build the zip with bsdtar (forward-slash entries).
$dist = Join-Path $root 'dist'
New-Item -ItemType Directory -Force $dist | Out-Null
$zip = Join-Path $dist 'wc-stock-sync.zip'
if (Test-Path $zip) { Remove-Item $zip -Force }

& "$env:WINDIR\System32\tar.exe" -a -cf $zip -C $stage 'wc-stock-sync'
if ($LASTEXITCODE -ne 0) { throw 'tar.exe failed.' }
Remove-Item $stage -Recurse -Force

# --- Verify: every entry must start with wc-stock-sync/ and use forward slashes.
$entries = & "$env:WINDIR\System32\tar.exe" -tf $zip
if ($LASTEXITCODE -ne 0) { throw 'tar.exe -tf failed.' }
$bad = @($entries | Where-Object { $_.Contains([string][char]92) -or ($_ -notmatch '^wc-stock-sync/') })
if ($bad.Count -gt 0) {
    $bad | ForEach-Object { Write-Host "BAD ENTRY: $_" }
    throw 'Zip contains invalid entry names — do not ship this file.'
}
Write-Host ("OK: {0} entries, all under wc-stock-sync/ with forward slashes." -f @($entries).Count)
Write-Host "Built: $zip"

# --- Optional: publish a GitHub release with the zip as the update asset.
if ($Publish) {
    $tag = "v$version"
    Write-Host "Publishing release $tag"
    & gh release create $tag $zip --title $tag --notes ("WC Stock Sync {0}" -f $version)
    if ($LASTEXITCODE -ne 0) { throw 'gh release create failed.' }
}
