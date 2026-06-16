param(
    [string] $SourceSkillsDir = (Join-Path (Split-Path -Parent $PSScriptRoot) 'skills'),
    [string] $CodexSkillsDir = (Join-Path $env:USERPROFILE '.codex\skills'),
    [string[]] $SkillName = @(),
    [switch] $NoBackup,
    [switch] $WhatIf
)

$ErrorActionPreference = 'Stop'

function Get-SkillHash {
    param([string] $Path)

    $skillFile = Join-Path $Path 'SKILL.md'
    if (-not (Test-Path -LiteralPath $skillFile)) {
        return ''
    }

    return (Get-FileHash -Algorithm SHA256 -LiteralPath $skillFile).Hash
}

if (-not (Test-Path -LiteralPath $SourceSkillsDir)) {
    throw "Source skills directory not found: $SourceSkillsDir"
}

$sourceSkills = Get-ChildItem -LiteralPath $SourceSkillsDir -Directory |
    Where-Object { Test-Path -LiteralPath (Join-Path $_.FullName 'SKILL.md') }

if ($SkillName.Count -gt 0) {
    $allowed = [System.Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
    foreach ($name in $SkillName) {
        [void] $allowed.Add($name)
    }
    $sourceSkills = $sourceSkills | Where-Object { $allowed.Contains($_.Name) }
}

if (-not $sourceSkills) {
    throw 'No source skills matched.'
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$summary = @()

foreach ($source in $sourceSkills) {
    $target = Join-Path $CodexSkillsDir $source.Name
    $sourceHash = Get-SkillHash -Path $source.FullName
    $targetHash = if (Test-Path -LiteralPath $target) { Get-SkillHash -Path $target } else { '' }
    $drift = $sourceHash -ne $targetHash

    $summary += [PSCustomObject]@{
        Skill = $source.Name
        Installed = Test-Path -LiteralPath $target
        Drift = $drift
        SourceHash = $sourceHash.Substring(0, 8)
        InstalledHash = if ($targetHash -ne '') { $targetHash.Substring(0, 8) } else { 'missing' }
    }

    if (-not $drift) {
        continue
    }

    if ($WhatIf) {
        continue
    }

    New-Item -ItemType Directory -Force -Path $CodexSkillsDir | Out-Null
    if ((Test-Path -LiteralPath $target) -and -not $NoBackup) {
        Copy-Item -LiteralPath $target -Destination "$target.backup-$stamp" -Recurse
    }

    New-Item -ItemType Directory -Force -Path $target | Out-Null
    Copy-Item -Path (Join-Path $source.FullName '*') -Destination $target -Recurse -Force
}

$summary | Format-Table -AutoSize

if ($WhatIf) {
    Write-Output 'Dry run only. No files changed.'
} elseif (-not $NoBackup) {
    Write-Output "Backups use suffix: backup-$stamp"
}
