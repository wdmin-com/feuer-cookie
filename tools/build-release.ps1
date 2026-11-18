param(
    [string]$Version = '',
    [string]$SourcePath = '',
    [string]$BaseUrl = 'https://wdmin.com/plugins/feuer-cookie/',
    [switch]$SkipZip
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Assert-WithinRoot {
    param(
        [string]$Candidate,
        [string]$Root
    )

    $candidatePath = [System.IO.Path]::GetFullPath($Candidate)
    $rootPath = [System.IO.Path]::GetFullPath($Root)

    if (-not $candidatePath.StartsWith($rootPath, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Path escapes the workspace root: $candidatePath"
    }
}

function Remove-WorkspacePath {
    param(
        [string]$TargetPath,
        [string]$RootPath
    )

    if (-not (Test-Path -LiteralPath $TargetPath)) {
        return
    }

    Assert-WithinRoot -Candidate $TargetPath -Root $RootPath
    Remove-Item -LiteralPath $TargetPath -Recurse -Force
}

function Get-PluginVersion {
    param([string]$PluginFile)

    $pluginContent = Get-Content -LiteralPath $PluginFile -Raw -Encoding UTF8
    $versionMatch = [regex]::Match($pluginContent, "define\('FEUER_COOKIE_VERSION',\s*'([^']+)'\)")

    if (-not $versionMatch.Success) {
        $versionMatch = [regex]::Match($pluginContent, 'Version:\s*([0-9][0-9A-Za-z\.\-_]*)')
    }

    if (-not $versionMatch.Success) {
        throw 'Could not determine plugin version automatically.'
    }

    return $versionMatch.Groups[1].Value
}

function Should-ExcludePackageItem {
    param([System.IO.FileSystemInfo]$Item)

    $excludedNames = @(
        '.gitattributes',
        '.gitignore',
        '.git',
        '.github',
        '.agents',
        '.codex',
        'development',
        'release',
        'tools',
        'TECHNICAL_SPEC_FEUER_ANALYTICS.md'
    )

    if ($excludedNames -contains $Item.Name) {
        return $true
    }

    if (-not $Item.PSIsContainer -and $Item.Extension -ieq '.zip') {
        return $true
    }

    return $false
}

function New-PluginZip {
    param(
        [string]$SourceRoot,
        [string]$ZipPath
    )

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem

    if (Test-Path -LiteralPath $ZipPath) {
        Remove-Item -LiteralPath $ZipPath -Force
    }

    $sourceFullPath = [System.IO.Path]::GetFullPath($SourceRoot)
    $zip = [System.IO.Compression.ZipFile]::Open($ZipPath, [System.IO.Compression.ZipArchiveMode]::Create)

    try {
        Get-ChildItem -LiteralPath $SourceRoot -Recurse -File -Force | ForEach-Object {
            $fileFullPath = [System.IO.Path]::GetFullPath($_.FullName)
            $relativePath = $fileFullPath.Substring($sourceFullPath.Length).TrimStart([System.IO.Path]::DirectorySeparatorChar, [System.IO.Path]::AltDirectorySeparatorChar)
            $entryName = $relativePath.Replace([System.IO.Path]::DirectorySeparatorChar, '/').Replace([System.IO.Path]::AltDirectorySeparatorChar, '/')

            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $zip,
                $fileFullPath,
                $entryName,
                [System.IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    } finally {
        $zip.Dispose()
    }
}

function Write-ReleaseAccessGuard {
    param([string]$ReleaseRoot)

    $rules = @'
Options -Indexes

<FilesMatch "\.(json|txt|zip)$">
    Require all granted
</FilesMatch>
'@

    Set-Content -LiteralPath (Join-Path $ReleaseRoot '.htaccess') -Value $rules -Encoding ASCII
}

function Should-ExcludeStatsPath {
    param(
        [string]$Path,
        [string]$RootPath
    )

    $fullPath = [System.IO.Path]::GetFullPath($Path)
    $releasePath = Join-Path $RootPath 'release'
    $gitPath = Join-Path $RootPath '.git'

    if ($fullPath.StartsWith([System.IO.Path]::GetFullPath($releasePath), [System.StringComparison]::OrdinalIgnoreCase)) {
        return $true
    }

    if ($fullPath.StartsWith([System.IO.Path]::GetFullPath($gitPath), [System.StringComparison]::OrdinalIgnoreCase)) {
        return $true
    }

    if ([System.IO.Path]::GetExtension($fullPath) -ieq '.zip') {
        return $true
    }

    return $false
}

function Copy-PackageContents {
    param(
        [string]$SourceRoot,
        [string]$TargetRoot
    )

    Get-ChildItem -LiteralPath $SourceRoot -Force | ForEach-Object {
        if (Should-ExcludePackageItem -Item $_) {
            return
        }

        $targetPath = Join-Path $TargetRoot $_.Name
        Copy-Item -LiteralPath $_.FullName -Destination $targetPath -Recurse -Force
    }
}

function Get-ProjectStats {
    param([string]$RootPath)

    $directoryCount = 0
    $fileCount = 0
    $lineCount = 0
    $nonEmptyLineCount = 0
    $characterCount = 0
    $byteCount = 0
    $byExtension = @{}

    Get-ChildItem -LiteralPath $RootPath -Recurse -Force | ForEach-Object {
        if (Should-ExcludeStatsPath -Path $_.FullName -RootPath $RootPath) {
            return
        }

        if ($_.PSIsContainer) {
            $directoryCount++
            return
        }

        $fileCount++
        $byteCount += [int64]$_.Length

        $extension = if ([string]::IsNullOrWhiteSpace($_.Extension)) { '[no_ext]' } else { $_.Extension.TrimStart('.').ToLowerInvariant() }

        if (-not $byExtension.ContainsKey($extension)) {
            $byExtension[$extension] = 0
        }

        $byExtension[$extension]++

        $content = Get-Content -LiteralPath $_.FullName -Raw -ErrorAction SilentlyContinue

        if ($null -eq $content) {
            return
        }

        $characterCount += $content.Length
        $lines = [regex]::Split($content, "`r?`n")
        $lineCount += $lines.Count
        $nonEmptyLineCount += @($lines | Where-Object { $_.Trim().Length -gt 0 }).Count
    }

    return [ordered]@{
        generated_at = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
        files = $fileCount
        directories = $directoryCount
        lines = $lineCount
        non_empty_lines = $nonEmptyLineCount
        characters = $characterCount
        bytes = $byteCount
        by_extension = [ordered]@{} + $byExtension
    }
}

function Get-LatestChangelogHtml {
    param(
        [string]$HistoryPath,
        [string]$Version
    )

    if (-not (Test-Path -LiteralPath $HistoryPath)) {
        return '<p>Changelog is not available yet.</p>'
    }

    $content = Get-Content -LiteralPath $HistoryPath -Raw -Encoding UTF8
    $pattern = "(?ms)^## Version $([regex]::Escape($Version)).*?(?=^## Version |\z)"
    $match = [regex]::Match($content, $pattern)

    if (-not $match.Success) {
        $match = [regex]::Match($content, '(?ms)^## Version .*?(?=^## Version |\z)')
    }

    if (-not $match.Success) {
        return '<p>Changelog is not available yet.</p>'
    }

    $lines = $match.Value -split "`r?`n" |
        ForEach-Object { $_.Trim() } |
        Where-Object { $_ -and -not $_.StartsWith('## ') -and -not $_.StartsWith('### ') }

    if (-not $lines) {
        return '<p>Changelog is not available yet.</p>'
    }

    $items = foreach ($line in $lines) {
        if ($line.StartsWith('- ')) {
            '<li>' + [System.Net.WebUtility]::HtmlEncode($line.Substring(2).Trim()) + '</li>'
        }
    }

    if ($items) {
        return '<ul>' + ($items -join '') + '</ul>'
    }

    return '<p>' + [System.Net.WebUtility]::HtmlEncode(($lines -join ' ')) + '</p>'
}

function New-Manifest {
    param(
        [string]$Version,
        [string]$BaseUrl,
        [string]$DownloadUrl,
        [string]$PackageHash,
        [string]$ChangelogHtml
    )

    return [ordered]@{
        name = 'Feuer-Cookie'
        slug = 'feuer-cookie'
        version = $Version
        homepage = $BaseUrl
        details_url = $BaseUrl
        download_url = $DownloadUrl
        package_url = $DownloadUrl
        author = ('Walter F' + [char]0x00E4 + 'rber')
        author_homepage = 'https://wdmin.com'
        requires = '6.9'
        tested = '6.9'
        requires_php = '8.0'
        last_updated = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
        autoupdate = -not [string]::IsNullOrWhiteSpace($DownloadUrl)
        package_hash = $PackageHash
        package_hash_algorithm = 'sha256'
        sections = [ordered]@{
            description = '<p>Feuer-Cookie is a WordPress consent and privacy plugin with service-based blocking, diagnostics and a verified update channel.</p><ul><li>Granular consent by category and service</li><li>Opt-in consent records without visitor analytics</li><li>Google Consent Mode v2 support</li><li>Optional consent bridge for Feuer Analytics</li><li>Release delivery from wdmin.com with SHA-256 verification</li></ul>'
            installation = '<ol><li>Upload the plugin folder to <code>wp-content/plugins/feuer-cookie/</code>.</li><li>Activate the plugin in WordPress.</li><li>Run the diagnostics once and review detected services.</li><li>Set privacy, imprint and support links in the plugin settings.</li><li>Build and publish the signed release package to wdmin.com.</li></ol>'
            changelog = $ChangelogHtml
        }
    }
}

function Write-JsonFile {
    param(
        [string]$Path,
        [object]$Data
    )

    $json = $Data | ConvertTo-Json -Depth 12
    Set-Content -LiteralPath $Path -Value $json -Encoding UTF8
}

if ([string]::IsNullOrWhiteSpace($SourcePath)) {
    $SourcePath = Join-Path $PSScriptRoot '..'
}

$resolvedSource = (Resolve-Path -LiteralPath $SourcePath).Path
$pluginFile = Join-Path $resolvedSource 'feuer-cookie.php'
$historyFile = Join-Path $resolvedSource 'HISTORIE.md'

if (-not (Test-Path -LiteralPath $pluginFile)) {
    throw "feuer-cookie.php not found in source path: $resolvedSource"
}

if ([string]::IsNullOrWhiteSpace($Version)) {
    $Version = Get-PluginVersion -PluginFile $pluginFile
}

$normalizedBaseUrl = $BaseUrl.TrimEnd('/') + '/'
$releaseRoot = Join-Path $resolvedSource 'release'
$versionRoot = Join-Path $releaseRoot $Version
$latestRoot = Join-Path $releaseRoot 'latest'
$buildRoot = Join-Path $releaseRoot '.build'
$stagingRoot = Join-Path $buildRoot ('feuer-cookie-' + $Version)
$packageRoot = Join-Path $stagingRoot 'feuer-cookie'
$versionPackageRoot = Join-Path $versionRoot 'feuer-cookie'
$latestPackageRoot = Join-Path $latestRoot 'feuer-cookie'
$versionZip = Join-Path $versionRoot 'feuer-cookie.zip'
$latestZip = Join-Path $latestRoot 'feuer-cookie.zip'
$versionChecksum = Join-Path $versionRoot 'checksum-sha256.txt'
$latestChecksum = Join-Path $latestRoot 'checksum-sha256.txt'

New-Item -ItemType Directory -Path $releaseRoot -Force | Out-Null
New-Item -ItemType Directory -Path $versionRoot -Force | Out-Null
New-Item -ItemType Directory -Path $latestRoot -Force | Out-Null
New-Item -ItemType Directory -Path $buildRoot -Force | Out-Null

Set-Content -LiteralPath (Join-Path $releaseRoot 'index.php') -Value "<?php`n// Silence is golden." -Encoding ASCII
Set-Content -LiteralPath (Join-Path $versionRoot 'index.php') -Value "<?php`n// Silence is golden." -Encoding ASCII
Set-Content -LiteralPath (Join-Path $latestRoot 'index.php') -Value "<?php`n// Silence is golden." -Encoding ASCII
Write-ReleaseAccessGuard -ReleaseRoot $releaseRoot
Write-ReleaseAccessGuard -ReleaseRoot $versionRoot
Write-ReleaseAccessGuard -ReleaseRoot $latestRoot

Remove-WorkspacePath -TargetPath $stagingRoot -RootPath $resolvedSource
Remove-WorkspacePath -TargetPath $versionPackageRoot -RootPath $resolvedSource
Remove-WorkspacePath -TargetPath $latestPackageRoot -RootPath $resolvedSource
New-Item -ItemType Directory -Path $packageRoot -Force | Out-Null
Copy-PackageContents -SourceRoot $resolvedSource -TargetRoot $packageRoot
Copy-Item -LiteralPath $packageRoot -Destination $versionPackageRoot -Recurse -Force
Copy-Item -LiteralPath $packageRoot -Destination $latestPackageRoot -Recurse -Force

$stats = Get-ProjectStats -RootPath $resolvedSource
$stats.version = $Version
$stats.release_base_url = $normalizedBaseUrl

$versionHash = ''
$latestHash = ''

if (-not $SkipZip) {
    Remove-WorkspacePath -TargetPath $versionZip -RootPath $resolvedSource
    Remove-WorkspacePath -TargetPath $latestZip -RootPath $resolvedSource
    New-PluginZip -SourceRoot $stagingRoot -ZipPath $versionZip
    Copy-Item -LiteralPath $versionZip -Destination $latestZip -Force

    $versionHash = (Get-FileHash -LiteralPath $versionZip -Algorithm SHA256).Hash.ToLowerInvariant()
    $latestHash = (Get-FileHash -LiteralPath $latestZip -Algorithm SHA256).Hash.ToLowerInvariant()
    Set-Content -LiteralPath $versionChecksum -Value $versionHash -Encoding ASCII
    Set-Content -LiteralPath $latestChecksum -Value $latestHash -Encoding ASCII
}

$changelogHtml = Get-LatestChangelogHtml -HistoryPath $historyFile -Version $Version
$versionDownloadUrl = $normalizedBaseUrl + 'release/' + $Version + '/feuer-cookie.zip'
$latestDownloadUrl = $normalizedBaseUrl + 'release/latest/feuer-cookie.zip'

if ($SkipZip) {
    throw 'A release manifest cannot be generated without the signed ZIP package. Remove -SkipZip.'
}

$versionManifest = New-Manifest -Version $Version -BaseUrl $normalizedBaseUrl -DownloadUrl $versionDownloadUrl -PackageHash $versionHash -ChangelogHtml $changelogHtml
$latestManifest = New-Manifest -Version $Version -BaseUrl $normalizedBaseUrl -DownloadUrl $latestDownloadUrl -PackageHash $latestHash -ChangelogHtml $changelogHtml

Write-JsonFile -Path (Join-Path $versionRoot 'update.json') -Data $versionManifest
Write-JsonFile -Path (Join-Path $latestRoot 'update.json') -Data $latestManifest
Write-JsonFile -Path (Join-Path $versionRoot 'project-stats.json') -Data $stats
Write-JsonFile -Path (Join-Path $latestRoot 'project-stats.json') -Data $stats

Remove-WorkspacePath -TargetPath $stagingRoot -RootPath $resolvedSource

Write-Output "Release build ready: $versionRoot"
Write-Output "Latest channel updated: $latestRoot"
