<#
.SYNOPSIS
Build the release zips of Hashy Stock Sync so they extract correctly on Linux.

Uses bsdtar (tar.exe), never Compress-Archive, which writes backslash
entry names that break extraction on Linux hosts.

.USAGE
powershell -ExecutionPolicy Bypass -File scripts\build-release.ps1 [-Directory] [-Shim] [-Publish]

Default     dist\hashy-stock-sync.zip: the GitHub edition (bundled update
            checker, updates from GitHub Releases). Top folder hashy-stock-sync/.
            This is the asset the fleet's update checker downloads.
-Directory  also dist\hashy-stock-sync-directory.zip for the WordPress.org
            submission: no includes\github-updates.php, no
            includes\lib\plugin-update-checker\, readme without the automatic
            updates text. Same top folder. Not uploaded to GitHub.
-Shim       also dist\wc-stock-sync.zip from shim\wc-stock-sync\: the 0.6.0
            migration release under the old slug (top folder wc-stock-sync/),
            which the 0.5.x installs download and which installs the GitHub
            edition. Its Version header must equal the plugin's.
-Publish    creates the GitHub release v<version> with every zip built in this
            run attached, except the directory zip (gh release create).
#>
param(
    [switch]$Directory,
    [switch]$Shim,
    [switch]$Publish
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$slug = 'hashy-stock-sync'
$oldSlug = 'wc-stock-sync'
$tar = "$env:WINDIR\System32\tar.exe"

# --- Version consistency: header, constant, readme stable tag must agree.
$main = Get-Content (Join-Path $root "$slug.php") -Raw
if ($main -notmatch '(?m)^\s*\*\s*Version:\s*([0-9.]+)') { throw 'No Version header found.' }
$verHeader = $Matches[1]
if ($main -notmatch "define\(\s*'WC_STOCK_SYNC_VERSION',\s*'([0-9.]+)'\s*\)") { throw 'No WC_STOCK_SYNC_VERSION define found.' }
$verConst = $Matches[1]
$readme = Get-Content (Join-Path $root 'readme.txt') -Raw
if ($readme -notmatch '(?m)^Stable tag:\s*([0-9.]+)') { throw 'No Stable tag found in readme.txt.' }
$verReadme = $Matches[1]

if (($verHeader -ne $verConst) -or ($verHeader -ne $verReadme)) {
    throw "Version mismatch: header=$verHeader constant=$verConst readme=$verReadme"
}
$version = $verHeader

if ($Shim) {
    $shimMain = Get-Content (Join-Path $root "shim\$oldSlug\$oldSlug.php") -Raw
    if ($shimMain -notmatch '(?m)^\s*\*\s*Version:\s*([0-9.]+)') { throw 'No Version header found in the shim.' }
    if ($Matches[1] -ne $version) { throw "Shim version $($Matches[1]) does not match plugin version $version." }
}

Write-Host "Building $slug $version"

$dist = Join-Path $root 'dist'
New-Item -ItemType Directory -Force $dist | Out-Null
$built = @()

# Zip a staged folder and verify every entry is a forward-slash path under the top folder.
function New-ReleaseZip {
    param([string]$ZipPath, [string]$StageParent, [string]$TopFolder)
    if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }
    & $tar -a -cf $ZipPath -C $StageParent $TopFolder
    if ($LASTEXITCODE -ne 0) { throw 'tar.exe failed.' }
    $entries = & $tar -tf $ZipPath
    if ($LASTEXITCODE -ne 0) { throw 'tar.exe -tf failed.' }
    $bad = @($entries | Where-Object { $_.Contains([string][char]92) -or ($_ -notmatch "^$TopFolder/") })
    if ($bad.Count -gt 0) {
        $bad | ForEach-Object { Write-Host "BAD ENTRY: $_" }
        throw 'Zip contains invalid entry names; do not ship this file.'
    }
    Write-Host ("OK: {0} entries, all under {1}/ with forward slashes." -f @($entries).Count, $TopFolder)
    Write-Host "Built: $ZipPath"
}

# Stage the plugin's runtime files into <stage>\<slug>.
function New-PluginStage {
    $stage = Join-Path $env:TEMP ("hss-build-" + [guid]::NewGuid().ToString('N'))
    $pkg = Join-Path $stage $slug
    New-Item -ItemType Directory -Force $pkg | Out-Null
    Copy-Item (Join-Path $root "$slug.php") $pkg
    Copy-Item (Join-Path $root 'readme.txt') $pkg
    Copy-Item (Join-Path $root 'uninstall.php') $pkg
    Copy-Item (Join-Path $root 'LICENSE') $pkg
    Copy-Item (Join-Path $root 'includes') $pkg -Recurse
    return $stage
}

# --- GitHub edition (default).
$stage = New-PluginStage
$zip = Join-Path $dist "$slug.zip"
New-ReleaseZip -ZipPath $zip -StageParent $stage -TopFolder $slug
Remove-Item $stage -Recurse -Force
$built += $zip

# --- Directory build: no updater, readme without the automatic updates text.
if ($Directory) {
    $stage = New-PluginStage
    $pkg = Join-Path $stage $slug
    Remove-Item (Join-Path $pkg 'includes\github-updates.php') -Force
    Remove-Item (Join-Path $pkg 'includes\lib\plugin-update-checker') -Recurse -Force

    $readmePath = Join-Path $pkg 'readme.txt'
    $text = Get-Content $readmePath -Raw
    # The "== Automatic updates ==" section up to the next section heading.
    $text = [regex]::Replace($text, '(?ms)^== Automatic updates ==\r?\n.*?(?=^== )', '')
    # The feature bullet and the bundled-library bullet that describe the updater.
    $text = [regex]::Replace($text, '(?m)^\* Automatic plugin updates from the GitHub repository[^\r\n]*\r?\n', '')
    $text = [regex]::Replace($text, '(?m)^\* Plugin Update Checker [^\r\n]*\r?\n', '')
    [System.IO.File]::WriteAllText($readmePath, $text, (New-Object System.Text.UTF8Encoding($false)))

    # Nothing the directory's Plugin Check flags as an updater may remain.
    $leftovers = Get-ChildItem $pkg -Recurse -File | Select-String -Pattern 'PucFactory|plugin-update-checker|YahnisElsts' -List
    if ($leftovers) {
        $leftovers | ForEach-Object { Write-Host "UPDATER REFERENCE: $($_.Path):$($_.LineNumber)" }
        throw 'The directory build still references the updater.'
    }

    $dirZip = Join-Path $dist "$slug-directory.zip"
    New-ReleaseZip -ZipPath $dirZip -StageParent $stage -TopFolder $slug
    Remove-Item $stage -Recurse -Force
}

# --- Shim: the migration release under the old slug.
if ($Shim) {
    $stage = Join-Path $env:TEMP ("hss-shim-" + [guid]::NewGuid().ToString('N'))
    $pkg = Join-Path $stage $oldSlug
    New-Item -ItemType Directory -Force $pkg | Out-Null
    Copy-Item (Join-Path $root "shim\$oldSlug\$oldSlug.php") $pkg
    Copy-Item (Join-Path $root 'LICENSE') $pkg
    $shimZip = Join-Path $dist "$oldSlug.zip"
    New-ReleaseZip -ZipPath $shimZip -StageParent $stage -TopFolder $oldSlug
    Remove-Item $stage -Recurse -Force
    $built += $shimZip
}

# --- Optional: publish a GitHub release with the update assets attached.
if ($Publish) {
    $tag = "v$version"
    Write-Host "Publishing release $tag with: $($built -join ', ')"
    $notes = "Hashy Stock Sync $version. hashy-stock-sync.zip is the plugin; wc-stock-sync.zip, where present, is the migration release that moves WC Stock Sync installs to it."
    & gh release create $tag @built --title $tag --notes $notes
    if ($LASTEXITCODE -ne 0) { throw 'gh release create failed.' }
}
