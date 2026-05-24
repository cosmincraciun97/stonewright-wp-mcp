# Live build script — uses ONLY stonewright/* abilities exposed via
# /wp-json/wp-abilities/v1/abilities/.../run on mcp-test.local.
#
# Builds:
#   1. Theme Builder template "Header"  (set conditions: include / general)
#   2. Theme Builder template "Footer"  (set conditions: include / general)
#   3. Front-end page "Homepage" with the hero section
#
# All three are populated by stonewright/elementor-v3-build-page-from-spec
# from the spec-*.json files in this dir.

$ErrorActionPreference = 'Stop'
$base = 'http://mcp-test.local'
$user = 'admin'
$pass = 'z92d xBLZ HhZy BX4Z qwH3 uWv3'
$cred = [System.Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes("${user}:${pass}"))
$headers = @{ Authorization = "Basic $cred"; 'Content-Type' = 'application/json' }

function Invoke-Ability {
    param([string]$Name, $InputData)
    $url = "$base/wp-json/wp-abilities/v1/abilities/$Name/run"
    $body = @{ input = $InputData } | ConvertTo-Json -Depth 30 -Compress
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($body)
    try {
        $r = Invoke-WebRequest -Uri $url -Method POST -Headers $headers -Body $bytes -UseBasicParsing -TimeoutSec 60
        return ($r.Content | ConvertFrom-Json)
    } catch {
        Write-Host "FAIL: $Name" -ForegroundColor Red
        Write-Host "URL : $url" -ForegroundColor Red
        Write-Host "Body: $body" -ForegroundColor Red
        if ($_.Exception.Response) {
            $sr = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
            Write-Host "Response: $($sr.ReadToEnd())" -ForegroundColor Red
        }
        throw
    }
}

function Read-Spec {
    param([string]$Path)
    # PS 5.1's Get-Content -Encoding UTF8 mis-handles Romanian diacritics;
    # use .NET directly to read the raw UTF-8 bytes correctly.
    return [System.IO.File]::ReadAllText($Path, [System.Text.UTF8Encoding]::new($false))
}

function Invoke-AbilityRawSpec {
    param([string]$Name, [int]$PostId, [string]$SpecJson)
    $url = "$base/wp-json/wp-abilities/v1/abilities/$Name/run"
    # Hand-build the JSON wrapper so the spec JSON is embedded verbatim, and
    # send the request body as UTF-8 bytes (Invoke-WebRequest otherwise
    # transcodes the body to ISO-8859-1 on PS 5.1, breaking diacritics).
    $body = '{"input":{"post_id":' + $PostId + ',"replace":true,"spec":' + $SpecJson + '}}'
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($body)
    try {
        $r = Invoke-WebRequest -Uri $url -Method POST -Headers $headers -Body $bytes -UseBasicParsing -TimeoutSec 90
        return ($r.Content | ConvertFrom-Json)
    } catch {
        Write-Host "FAIL: $Name" -ForegroundColor Red
        if ($_.Exception.Response) {
            $sr = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
            Write-Host "Response: $($sr.ReadToEnd())" -ForegroundColor Red
        }
        throw
    }
}

# ---------------------------------------------------------------------------
# 1. Header template
# ---------------------------------------------------------------------------

Write-Host "`n[1/6] Create Theme Builder template: Header" -ForegroundColor Cyan
$header = Invoke-Ability -Name 'stonewright/theme-builder-create-template' -InputData @{
    title = 'Site Header (from Figma 97:3735)'
    template_type = 'header'
}
$headerId = $header.template_id
Write-Host "  template_id = $headerId" -ForegroundColor Green

Write-Host "[2/6] Populate Header with spec-header.json" -ForegroundColor Cyan
$specJson = Read-Spec "$PSScriptRoot\spec-header.json"
$res = Invoke-AbilityRawSpec -Name 'stonewright/elementor-v3-build-page-from-spec' -PostId $headerId -SpecJson $specJson
Write-Host "  elements rendered: $($res.elements); snapshot_id: $($res.snapshot_id)" -ForegroundColor Green
if ($res.diagnostics -and $res.diagnostics.Count -gt 0) {
    Write-Host "  diagnostics:" -ForegroundColor Yellow
    $res.diagnostics | ForEach-Object { Write-Host "    - $_" -ForegroundColor Yellow }
}

Write-Host "[3/6] Set Header conditions: include/general (site-wide)" -ForegroundColor Cyan
$cond = Invoke-Ability -Name 'stonewright/theme-builder-set-conditions' -InputData @{
    template_id = $headerId
    conditions = @( @{ type = 'include'; name = 'general' } )
}
Write-Host "  updated: $($cond.updated)" -ForegroundColor Green

# ---------------------------------------------------------------------------
# 2. Footer template
# ---------------------------------------------------------------------------

Write-Host "`n[4/6] Create Theme Builder template: Footer" -ForegroundColor Cyan
$footer = Invoke-Ability -Name 'stonewright/theme-builder-create-template' -InputData @{
    title = 'Site Footer (from Figma 97:3646)'
    template_type = 'footer'
}
$footerId = $footer.template_id
Write-Host "  template_id = $footerId" -ForegroundColor Green

Write-Host "[5/6] Populate Footer with spec-footer.json + set conditions" -ForegroundColor Cyan
$specJson = Read-Spec "$PSScriptRoot\spec-footer.json"
$res = Invoke-AbilityRawSpec -Name 'stonewright/elementor-v3-build-page-from-spec' -PostId $footerId -SpecJson $specJson
Write-Host "  elements rendered: $($res.elements); snapshot_id: $($res.snapshot_id)" -ForegroundColor Green
$cond = Invoke-Ability -Name 'stonewright/theme-builder-set-conditions' -InputData @{
    template_id = $footerId
    conditions = @( @{ type = 'include'; name = 'general' } )
}
Write-Host "  conditions updated: $($cond.updated)" -ForegroundColor Green

# ---------------------------------------------------------------------------
# 3. Homepage page with Hero section
# ---------------------------------------------------------------------------

Write-Host "`n[6/6] Create Homepage page + populate with Hero spec" -ForegroundColor Cyan
$page = Invoke-Ability -Name 'stonewright/content-create-page' -InputData @{
    title = 'Homepage (nZeb Expo Bucuresti)'
    status = 'publish'
    slug = 'homepage'
}
$pageId = $page.id
Write-Host "  page_id = $pageId  preview: $($page.preview)" -ForegroundColor Green

$specJson = Read-Spec "$PSScriptRoot\spec-hero.json"
$res = Invoke-AbilityRawSpec -Name 'stonewright/elementor-v3-build-page-from-spec' -PostId $pageId -SpecJson $specJson
Write-Host "  hero elements rendered: $($res.elements); snapshot_id: $($res.snapshot_id)" -ForegroundColor Green

# ---------------------------------------------------------------------------
# 4. Promote to homepage + clear ProElements condition cache
# ---------------------------------------------------------------------------

Write-Host "`n[7/7] Promote page to homepage + clean up older templates" -ForegroundColor Cyan
$url = "$base/stonewright-promote.php?t=sw-promote-26052022&page=$pageId&header=$headerId&footer=$footerId"
try {
    $r = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 30
    $r.Content | ConvertFrom-Json | ConvertTo-Json -Depth 3
} catch {
    Write-Host "Promote failed: $($_.Exception.Message)" -ForegroundColor Yellow
    Write-Host "(NB: stonewright-promote.php self-destructs after one call. Recreate it to re-run.)"
}

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

Write-Host "`n=== Build complete ===" -ForegroundColor Green
Write-Host "Header template_id : $headerId"
Write-Host "Footer template_id : $footerId"
Write-Host "Homepage page_id   : $pageId"
Write-Host ""
Write-Host "Visit the rendered page:"
Write-Host "  $base/                  (now the front page)"
Write-Host "  $base/?page_id=$pageId  (direct id)"
Write-Host ""
Write-Host "All abilities run via stonewright/ exposed on"
Write-Host "  $base/wp-json/wp-abilities/v1/abilities/.../run"
