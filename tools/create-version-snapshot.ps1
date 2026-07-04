param(
    [string]$Version = '',
    [string]$SourcePath = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$scriptPath = Join-Path $PSScriptRoot 'build-release.ps1'

if (-not (Test-Path -LiteralPath $scriptPath)) {
    throw "build-release.ps1 not found: $scriptPath"
}

& $scriptPath -Version $Version -SourcePath $SourcePath
