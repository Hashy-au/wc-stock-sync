<#
.SYNOPSIS
    Two-site Playground test: what a Host push changes on an Agent.

.DESCRIPTION
    Boots two WordPress Playground servers with WooCommerce and this plugin,
    one in Host mode and one in Agent mode, with real signed HTTP pushes
    between them, then checks which fields each kind of push changes on the
    Agent. Written for 0.7.1, after 0.7.0 was found writing the Host's regular,
    sale and active price onto every Agent on every stock push.

    Scenarios (the Agent is reseeded before each):
      1. Stock push, Sync Prices off: stock only.
      2. Stock push, Sync Prices on at 125: stock plus regular price x 1.25;
         the Agent's own sales, scheduled sales and dates untouched.
      3. Sync stock button with Sync Prices on: stock only, every shared SKU.
      4. Sync prices button: regular price x 1.25 only, stock untouched.
      5. A 0.7.0-shaped payload sent straight to the Agent: price and
         sale_price ignored.
      6. A 0.7.0-shaped row in the Host retry queue, Sync Prices off: sent
         with stock only.

    Needs Node (npx fetches @wp-playground/cli) and network access for the
    WooCommerce download. Takes about 4 minutes. Writes two-site-result.txt
    beside this script and exits 1 on any failure.

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File tests\playground\two-site\run-two-site.ps1
#>
param(
    [int]$HubPort = 9531,
    [int]$AgentPort = 9532
)

$ErrorActionPreference = 'Stop'
$here = $PSScriptRoot
$plugin = (Resolve-Path (Join-Path $here '..\..\..')).Path
$mu = Join-Path $here 'mu'
$blueprint = Join-Path $here 'blueprint.json'
$logDir = Join-Path $env:TEMP ("hashy-two-site-" + $PID)
New-Item -ItemType Directory -Force -Path $logDir | Out-Null

$hub = "http://127.0.0.1:$HubPort"
$agent = "http://127.0.0.1:$AgentPort"
$script:pass = 0
$script:fail = 0
$script:lines = New-Object System.Collections.Generic.List[string]
$script:procs = @()

function Say([string]$text) {
    $script:lines.Add($text)
    Write-Host $text
}

function Start-Site([int]$port, [int]$workers, [string]$name) {
    $log = Join-Path $logDir "$name.log"
    $cmd = "npx -y @wp-playground/cli@latest server --port=$port --workers=$workers --blueprint=`"$blueprint`" --mount-dir `"$plugin`" /wordpress/wp-content/plugins/hashy-stock-sync --mount-dir `"$mu`" /wordpress/wp-content/mu-plugins > `"$log`" 2>&1"
    $p = Start-Process -FilePath 'cmd.exe' -ArgumentList '/c', $cmd -PassThru -WindowStyle Hidden
    $script:procs += $p
    $deadline = (Get-Date).AddMinutes(10)
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 5
        try {
            Invoke-RestMethod -Uri "http://127.0.0.1:$port/wp-json/hss-probe/v1/state" -TimeoutSec 20 | Out-Null
            Say "  $name ready on port $port"
            return
        } catch {
            if ($p.HasExited) { throw "$name exited early; see $log" }
        }
    }
    throw "$name did not come up within 10 minutes; see $log"
}

function Post([string]$base, [string]$route, $body) {
    $json = if ($null -eq $body) { '{}' } else { $body | ConvertTo-Json -Depth 6 -Compress }
    return Invoke-RestMethod -Method Post -Uri "$base/wp-json/hss-probe/v1/$route" -ContentType 'application/json' -Body $json -TimeoutSec 300
}

function Get-AgentState {
    return Invoke-RestMethod -Uri "$agent/wp-json/hss-probe/v1/state" -TimeoutSec 60
}

function Reset-Agent {
    Post $agent 'setup' @{ role = 'agent'; peer = $hub; seed = 1 } | Out-Null
    # The Agent drops a payload whose ts is not newer than the last applied one.
    Start-Sleep -Seconds 1
}

function Set-HubAgentRow([string]$syncPrices, [string]$pct) {
    Post $hub 'setup' @{ role = 'host'; peer = $agent; seed = 0; sync_prices = $syncPrices; price_pct = $pct } | Out-Null
}

# $expect: SKU => 'stock|regular|sale|price_meta|dates'.
function Check-Agent([string]$label, [hashtable]$expect) {
    $state = Get-AgentState
    foreach ($sku in ($expect.Keys | Sort-Object)) {
        $row = $state.$sku
        $got = "{0}|{1}|{2}|{3}|{4}" -f $row.stock, $row.regular, $row.sale, $row.price_meta, $row.dates
        if ($got -eq $expect[$sku]) {
            $script:pass++
            Say ("  ok    {0}: {1} {2}" -f $label, $sku, $got)
        } else {
            $script:fail++
            Say ("  FAIL  {0}: {1} got {2}, expected {3}" -f $label, $sku, $got, $expect[$sku])
        }
    }
}

try {
    Say 'Hashy Stock Sync two-site test'
    Say "  plugin: $plugin"
    Start-Site $HubPort 6 'hub'
    Start-Site $AgentPort 2 'agent'

    $hubSetup = Post $hub 'setup' @{ role = 'host'; peer = $agent; seed = 1; sync_prices = 'no'; price_pct = '' }
    Say ("  plugin version {0}, hub mode {1}" -f $hubSetup.version, $hubSetup.mode)
    Reset-Agent
    $seed = Get-AgentState
    $d = @{}
    foreach ($sku in 'HSS-A', 'HSS-C', 'HSS-D-1', 'HSS-E') { $d[$sku] = $seed.$sku.dates }
    $none = '-..-'

    Say ''
    Say '# 1. stock push, Sync Prices off'
    Post $hub 'set-stock' @{ skus = @{ 'HSS-A' = 9; 'HSS-B' = 9; 'HSS-C' = 9; 'HSS-D-1' = 9 } } | Out-Null
    Check-Agent 'push, box off' @{
        'HSS-A'   = "9|120|99|99|$($d['HSS-A'])"
        'HSS-B'   = "9|120||120|$none"
        'HSS-C'   = "9|120|99|120|$($d['HSS-C'])"
        'HSS-D-1' = "9|60|45|45|$($d['HSS-D-1'])"
        'HSS-E'   = "10|120|99|99|$($d['HSS-E'])"
    }

    Say ''
    Say '# 2. stock push, Sync Prices on at 125'
    Reset-Agent
    $seed = Get-AgentState
    foreach ($sku in 'HSS-A', 'HSS-C', 'HSS-D-1', 'HSS-E') { $d[$sku] = $seed.$sku.dates }
    Set-HubAgentRow 'yes' '125'
    Post $hub 'set-stock' @{ skus = @{ 'HSS-A' = 8; 'HSS-B' = 8; 'HSS-C' = 8; 'HSS-D-1' = 8 } } | Out-Null
    Check-Agent 'push, box on' @{
        'HSS-A'   = "8|125.00|99|99|$($d['HSS-A'])"
        'HSS-B'   = "8|125.00||125.00|$none"
        'HSS-C'   = "8|125.00|99|125.00|$($d['HSS-C'])"
        'HSS-D-1' = "8|62.50|45|45|$($d['HSS-D-1'])"
        'HSS-E'   = "10|120|99|99|$($d['HSS-E'])"
    }

    Say ''
    Say '# 3. Sync stock button, Sync Prices on at 125'
    Reset-Agent
    $seed = Get-AgentState
    foreach ($sku in 'HSS-A', 'HSS-C', 'HSS-D-1', 'HSS-E') { $d[$sku] = $seed.$sku.dates }
    Post $hub 'batch' @{ kind = 'stock' } | Out-Null
    Check-Agent 'sync stock' @{
        'HSS-A'   = "8|120|99|99|$($d['HSS-A'])"
        'HSS-B'   = "8|120||120|$none"
        'HSS-C'   = "8|120|99|120|$($d['HSS-C'])"
        'HSS-D-1' = "8|60|45|45|$($d['HSS-D-1'])"
        'HSS-E'   = "10|120|99|99|$($d['HSS-E'])"
    }

    Say ''
    Say '# 4. Sync prices button, Sync Prices on at 125'
    Reset-Agent
    $seed = Get-AgentState
    foreach ($sku in 'HSS-A', 'HSS-C', 'HSS-D-1', 'HSS-E') { $d[$sku] = $seed.$sku.dates }
    Post $hub 'batch' @{ kind = 'prices' } | Out-Null
    Check-Agent 'sync prices' @{
        'HSS-A'   = "10|125.00|99|99|$($d['HSS-A'])"
        'HSS-B'   = "10|125.00||125.00|$none"
        'HSS-C'   = "10|125.00|99|125.00|$($d['HSS-C'])"
        'HSS-D-1' = "10|62.50|45|45|$($d['HSS-D-1'])"
        'HSS-E'   = "10|125.00|99|99|$($d['HSS-E'])"
    }

    Say ''
    Say '# 5. a 0.7.0-shaped payload straight to the Agent'
    Set-HubAgentRow 'no' ''
    Reset-Agent
    $seed = Get-AgentState
    $d['HSS-A'] = $seed.'HSS-A'.dates
    Post $hub 'legacy-push' @{ sku = 'HSS-A'; qty = 7; price = @{ price = '100'; regular_price = '100'; sale_price = '' } } | Out-Null
    Check-Agent 'legacy payload' @{
        'HSS-A' = "7|100|99|99|$($d['HSS-A'])"
    }

    Say ''
    Say '# 6. a 0.7.0-shaped retry-queue row, Sync Prices off'
    Reset-Agent
    $left = Post $hub 'legacy-queue' @{ sku = 'HSS-B'; qty = 6; price = @{ price = '80'; regular_price = '100'; sale_price = '80' } }
    Check-Agent 'legacy queue row' @{
        'HSS-B' = "6|120||120|$none"
    }
    if ([int]$left.left -eq 0) {
        $script:pass++
        Say '  ok    legacy queue row: sent and removed from the queue'
    } else {
        $script:fail++
        Say ("  FAIL  legacy queue row: {0} row(s) left in the queue" -f $left.left)
    }
} catch {
    $script:fail++
    Say ("  FAIL  run aborted: {0}" -f $_.Exception.Message)
} finally {
    foreach ($p in $script:procs) {
        if ($p -and -not $p.HasExited) { & taskkill.exe /PID $p.Id /T /F | Out-Null }
    }
}

Say ''
Say ("HASHY_TWO_SITE {0} passed, {1} failed" -f $script:pass, $script:fail)
$script:lines | Set-Content -Encoding utf8 -Path (Join-Path $here 'two-site-result.txt')
if ($script:fail -gt 0) { exit 1 }
exit 0
