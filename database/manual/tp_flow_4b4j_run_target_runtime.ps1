# TP-FLOW-4B4J guarded target runtime launcher.
# Loads database values from the existing .env into the child process only.
# Never prints passwords and never mutates the parent process or .env.

[CmdletBinding()]
param(
    [switch]$Preflight,
    [string]$ConfirmTargetDatabase = '',
    [string]$Php = 'php',
    [string]$RuntimeScript = ''
)

$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$envFile = Join-Path $repo '.env'
$targetDatabase = 'r_erp_db_core_0_test'

if (-not $Preflight -and [string]::IsNullOrWhiteSpace($RuntimeScript)) {
    throw 'TARGET_RUNTIME_LAUNCHER_REQUIRES_PREFLIGHT_OR_RUNTIME_SCRIPT'
}
if ([string]::IsNullOrWhiteSpace($ConfirmTargetDatabase) -or $ConfirmTargetDatabase -ne $targetDatabase) {
    throw 'TARGET_DATABASE_CONFIRMATION_REQUIRED'
}
if (-not (Test-Path -LiteralPath $envFile)) {
    throw 'TARGET_ENV_FILE_MISSING'
}

$envValues = @{}
foreach ($line in Get-Content -LiteralPath $envFile) {
    $trimmed = $line.Trim()
    if ($trimmed -eq '' -or $trimmed.StartsWith('#')) { continue }
    if ($trimmed.StartsWith('export ')) { $trimmed = $trimmed.Substring(7).Trim() }
    $parts = $trimmed -split '=', 2
    if ($parts.Count -ne 2) { continue }
    $name = $parts[0].Trim()
    $value = $parts[1].Trim()
    if ($value.Length -ge 2 -and (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'")))) {
        $value = $value.Substring(1, $value.Length - 2)
    }
    $envValues[$name] = $value
}

$required = @('APP_DB_HOST', 'APP_DB_PORT', 'APP_DB_NAME', 'APP_DB_USER', 'APP_DB_PASSWORD', 'APP_DB_WRITE_USER', 'APP_DB_WRITE_PASSWORD')
foreach ($name in $required) {
    if (-not $envValues.ContainsKey($name) -or [string]::IsNullOrWhiteSpace([string]$envValues[$name])) {
        throw "TARGET_CREDENTIAL_OR_CONFIG_MISSING:$name"
    }
}
if ($envValues['APP_DB_NAME'] -ne $targetDatabase) {
    throw 'TARGET_ENV_DATABASE_MISMATCH'
}
if ($envValues['APP_DB_USER'] -eq 'root' -or $envValues['APP_DB_WRITE_USER'] -eq 'root') {
    throw 'ROOT_RUNTIME_FORBIDDEN'
}
$forbiddenQaSetupAccount = 'r_erp_' + 'tickets_qa_' + 'setup'
if ($envValues['APP_DB_USER'] -eq $forbiddenQaSetupAccount -or $envValues['APP_DB_WRITE_USER'] -eq $forbiddenQaSetupAccount) {
    throw 'QA_SETUP_RUNTIME_FORBIDDEN'
}

# Read credentials are explicit for the child; existing project fallback is
# safe here because APP_DB_READ_USER/PASSWORD are derived only from the target
# .env values, never from the parent QA process environment.
$env:APP_ENV = if ($envValues.ContainsKey('APP_ENV')) { $envValues['APP_ENV'] } else { 'qa' }
$env:APP_DB_HOST = $envValues['APP_DB_HOST']
$env:APP_DB_PORT = $envValues['APP_DB_PORT']
$env:APP_DB_NAME = $envValues['APP_DB_NAME']
$env:APP_DB_USER = $envValues['APP_DB_USER']
$env:APP_DB_PASSWORD = $envValues['APP_DB_PASSWORD']
$env:APP_DB_READ_USER = $envValues['APP_DB_USER']
$env:APP_DB_READ_PASSWORD = $envValues['APP_DB_PASSWORD']
$env:APP_DB_WRITE_USER = $envValues['APP_DB_WRITE_USER']
$env:APP_DB_WRITE_PASSWORD = $envValues['APP_DB_WRITE_PASSWORD']

if ($Preflight) {
    & $Php (Join-Path $repo 'database/tp_flow_4b4j_target_runtime_preflight.php') '--preflight'
    exit $LASTEXITCODE
}

$runtimePath = (Resolve-Path (Join-Path $repo $RuntimeScript)).Path
& $Php $runtimePath
exit $LASTEXITCODE
