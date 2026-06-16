param(
    [string] $SourceSkillsDir = (Join-Path (Split-Path -Parent $PSScriptRoot) 'skills'),
    [string] $CodexSkillsDir = (Join-Path $env:USERPROFILE '.codex\skills'),
    [string] $BackupDir = (Join-Path $env:USERPROFILE '.codex\skill-backups\stonewright'),
    [string[]] $SkillName = @(),
    [switch] $NoBackup,
    [switch] $SkipIndexedBackupCleanup,
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

function New-UniqueBackupPath {
    param(
        [string] $Root,
        [string] $Name,
        [string] $Stamp
    )

    $candidate = Join-Path $Root "$Name.$Stamp"
    $index = 1
    while (Test-Path -LiteralPath $candidate) {
        $candidate = Join-Path $Root "$Name.$Stamp.$index"
        $index++
    }

    return $candidate
}

function Move-IndexedBackupDirs {
    param(
        [string] $SkillsDir,
        [string] $DestinationDir,
        [string] $Stamp,
        [string[]] $Names
    )

    if (-not (Test-Path -LiteralPath $SkillsDir)) {
        return @()
    }

    $nameSet = [System.Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
    foreach ($name in $Names) {
        [void] $nameSet.Add($name)
    }

    $moved = @()
    $backupDirs = Get-ChildItem -LiteralPath $SkillsDir -Directory |
        ForEach-Object {
            if ($_.Name -match '^(?<skill>.+)\.backup-\d{8}-\d{6}$' -and $nameSet.Contains($Matches['skill'])) {
                [PSCustomObject]@{
                    Directory = $_
                    Skill = $Matches['skill']
                }
            }
        }

    if (-not $backupDirs) {
        return $moved
    }

    $indexedBackupDir = Join-Path $DestinationDir "indexed-root-$Stamp"
    if (-not $WhatIf) {
        New-Item -ItemType Directory -Force -Path $indexedBackupDir | Out-Null
    }

    foreach ($dir in $backupDirs) {
        $directory = $dir.Directory
        $destination = New-UniqueBackupPath -Root $indexedBackupDir -Name $directory.Name -Stamp 'moved'
        if (-not $WhatIf) {
            Move-Item -LiteralPath $directory.FullName -Destination $destination
        }
        $moved += [PSCustomObject]@{
            Skill = $dir.Skill
            From = $directory.FullName
            To = $destination
        }
    }

    return $moved
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
$sourceSkillNames = @($sourceSkills | ForEach-Object { $_.Name })
$relocatedBackups = @()

if (-not $SkipIndexedBackupCleanup) {
    $relocatedBackups = Move-IndexedBackupDirs -SkillsDir $CodexSkillsDir -DestinationDir $BackupDir -Stamp $stamp -Names $sourceSkillNames
}

foreach ($source in $sourceSkills) {
    $target = Join-Path $CodexSkillsDir $source.Name
    $sourceHash = Get-SkillHash -Path $source.FullName
    $targetHash = if (Test-Path -LiteralPath $target) { Get-SkillHash -Path $target } else { '' }
    $staleNestedSkill = Test-Path -LiteralPath (Join-Path (Join-Path $target $source.Name) 'SKILL.md')
    $drift = $sourceHash -ne $targetHash
    $cleanupNeeded = $staleNestedSkill

    $summary += [PSCustomObject]@{
        Skill = $source.Name
        Installed = Test-Path -LiteralPath $target
        Drift = $drift
        CleanupNeeded = $cleanupNeeded
        SourceHash = $sourceHash.Substring(0, 8)
        InstalledHash = if ($targetHash -ne '') { $targetHash.Substring(0, 8) } else { 'missing' }
    }

    if (-not $drift -and -not $cleanupNeeded) {
        continue
    }

    if ($WhatIf) {
        continue
    }

    New-Item -ItemType Directory -Force -Path $CodexSkillsDir | Out-Null
    if ((Test-Path -LiteralPath $target) -and -not $NoBackup) {
        New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null
        $backupTarget = New-UniqueBackupPath -Root $BackupDir -Name $source.Name -Stamp "backup-$stamp"
        Copy-Item -LiteralPath $target -Destination $backupTarget -Recurse
    }

    if (Test-Path -LiteralPath $target) {
        Remove-Item -LiteralPath $target -Recurse -Force
    }

    New-Item -ItemType Directory -Force -Path $target | Out-Null
    Copy-Item -Path (Join-Path $source.FullName '*') -Destination $target -Recurse -Force
}

$summary | Format-Table -AutoSize

if ($relocatedBackups.Count -gt 0) {
    Write-Output "Relocated indexed backup directories: $($relocatedBackups.Count)"
    $relocatedBackups | Select-Object Skill,From,To | Format-Table -AutoSize
}

if ($WhatIf) {
    Write-Output 'Dry run only. No files changed.'
} elseif (-not $NoBackup) {
    Write-Output "Backups stored outside Codex skill root: $BackupDir"
}
