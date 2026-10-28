[CmdletBinding()]
param(
    [switch]$RunPythonTests,
    [switch]$CheckLocalServices
)

$ErrorActionPreference = 'Continue'
$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$script:Failures = 0
$script:Warnings = 0

function Write-Check {
    param([ValidateSet('PASS', 'FAIL', 'WARN', 'INFO')][string]$Status, [string]$Message)
    $color = switch ($Status) {
        'PASS' { 'Green' }
        'FAIL' { 'Red' }
        'WARN' { 'Yellow' }
        default { 'Cyan' }
    }
    Write-Host ('[{0}] {1}' -f $Status, $Message) -ForegroundColor $color
    if ($Status -eq 'FAIL') { $script:Failures++ }
    if ($Status -eq 'WARN') { $script:Warnings++ }
}

$excluded = @('\.git\', '\.venv\', '\venv\', '\__pycache__\', '\.pytest_cache\', '\node_modules\')
$sourceFiles = Get-ChildItem -LiteralPath $ProjectRoot -Recurse -File -ErrorAction SilentlyContinue |
    Where-Object {
        $full = $_.FullName
        -not ($excluded | Where-Object { $full -match [regex]::Escape($_) })
    }
$phpFiles = @($sourceFiles | Where-Object Extension -eq '.php')
$pythonFiles = @($sourceFiles | Where-Object Extension -eq '.py' | Where-Object { $_.FullName -notmatch '\\tests\\' -or $_.Name -notlike 'test_*' })

Write-Host "MedConnect verification: $ProjectRoot" -ForegroundColor White
Write-Check INFO ("Source inventory: {0} files (excluding virtual environments, caches, Git and node_modules); {1} PHP, {2} Python." -f $sourceFiles.Count, $phpFiles.Count, $pythonFiles.Count)

# Locate PHP without printing environment values.
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$phpExe = if (Test-Path 'C:\xampp\php\php.exe') { 'C:\xampp\php\php.exe' } elseif ($phpCommand) { $phpCommand.Source } else { $null }
if (-not $phpExe) {
    Write-Check WARN 'PHP CLI not found; PHP syntax checks were not run.'
} else {
    foreach ($file in $phpFiles) {
        & $phpExe -l $file.FullName *> $null
        if ($LASTEXITCODE -eq 0) { Write-Check PASS ("PHP syntax: {0}" -f $file.FullName.Substring($ProjectRoot.Length + 1)) }
        else { Write-Check FAIL ("PHP syntax error: {0}" -f $file.FullName.Substring($ProjectRoot.Length + 1)) }
    }
}

# Resolve common static PHP include forms. Dynamic expressions are not guessed.
$includePattern = '(?is)\b(?:require|include)(?:_once)?\s*\(?\s*(?:__DIR__\s*\.\s*|dirname\(__DIR__,\s*(?<levels>\d+)\)\s*\.\s*)["''](?<path>[^"'']+)["'']'
$includeCount = 0
foreach ($file in $phpFiles) {
    $content = Get-Content -LiteralPath $file.FullName -Raw -ErrorAction SilentlyContinue
    if (-not $content) { continue }
    foreach ($match in [regex]::Matches($content, $includePattern)) {
        $includeCount++
        $base = $file.DirectoryName
        if ($match.Groups['levels'].Success) {
            for ($i = 0; $i -lt [int]$match.Groups['levels'].Value; $i++) { $base = Split-Path -Parent $base }
        }
        $target = [IO.Path]::GetFullPath((Join-Path $base ($match.Groups['path'].Value -replace '/', '\')))
        if (Test-Path -LiteralPath $target -PathType Leaf) {
            Write-Check PASS ("Include: {0} -> {1}" -f $file.FullName.Substring($ProjectRoot.Length + 1), $target.Substring($ProjectRoot.Length + 1))
        } else {
            Write-Check FAIL ("Missing include: {0} -> {1}" -f $file.FullName.Substring($ProjectRoot.Length + 1), $match.Groups['path'].Value)
        }
    }
}
if ($includeCount -eq 0) { Write-Check WARN 'No supported static include expressions found; review dynamic include statements manually.' }

# Resolve simple local browser href/src/action attributes from their page URL.
$browserFiles = @($sourceFiles | Where-Object { $_.Extension -in @('.php', '.html', '.htm') })
$attributePattern = '(?is)\b(?:href|src|action)\s*=\s*(["''])(?<url>[^"'']+)\1'
$includedAdminImplementations = @(
    'admin\dashboard.php', 'admin\login.php',
    'admin\actions\admin-action.php', 'admin\actions\delete-patient.php'
)
$browserReferenceCount = 0
foreach ($file in $browserFiles) {
    $content = Get-Content -LiteralPath $file.FullName -Raw -ErrorAction SilentlyContinue
    if (-not $content) { continue }
    foreach ($match in [regex]::Matches($content, $attributePattern)) {
        $reference = $match.Groups['url'].Value.Trim()
        if (-not $reference -or $reference -match '^(?i)(https?:|mailto:|tel:|data:|javascript:|//)' -or $reference.StartsWith('#')) { continue }
        if ($reference -match '<\?|\?>|\$\{|\$[A-Za-z_]' ) { continue } # PHP/JS-generated URL.
        $pathPart = ($reference -split '[?#]', 2)[0]
        if (-not $pathPart) { continue }
        $browserReferenceCount++
        if ($pathPart.StartsWith('/')) {
            $pathPart = $pathPart.TrimStart('/')
            if ($pathPart.StartsWith('medconnect/')) { $pathPart = $pathPart.Substring('medconnect/'.Length) }
            $base = $ProjectRoot
        } else {
            $sourceRelativePath = $file.FullName.Substring($ProjectRoot.Length + 1)
            if ($includedAdminImplementations -contains $sourceRelativePath) {
                # These implementation files emit markup through root compatibility URLs.
                $base = $ProjectRoot
            } else {
                $base = $file.DirectoryName
            }
        }
        try { $target = [IO.Path]::GetFullPath((Join-Path $base ([Uri]::UnescapeDataString($pathPart) -replace '/', '\'))) }
        catch { Write-Check WARN ("Unresolved browser URL in {0}: {1}" -f $file.Name, $reference); continue }
        if (Test-Path -LiteralPath $target -PathType Leaf) {
            Write-Check PASS ("Browser reference: {0} -> {1}" -f $file.FullName.Substring($ProjectRoot.Length + 1), $reference)
        } else {
            Write-Check FAIL ("Missing browser target: {0} -> {1}" -f $file.FullName.Substring($ProjectRoot.Length + 1), $reference)
        }
    }
}
if ($browserReferenceCount -eq 0) { Write-Check WARN 'No static browser references were found to verify.' }

# Check literal local fetch paths in standalone JavaScript modules.
$fetchPattern = '(?is)\bfetch\(\s*["''](?<url>[^"'']+)["'']'
foreach ($file in @($sourceFiles | Where-Object Extension -eq '.js')) {
    $content = Get-Content -LiteralPath $file.FullName -Raw -ErrorAction SilentlyContinue
    foreach ($match in [regex]::Matches($content, $fetchPattern)) {
        $reference = ($match.Groups['url'].Value -split '[?#]', 2)[0]
        if (-not $reference -or $reference -match '^(?i)(https?:|//)' -or $reference -match '[{}$]') { continue }
        $target = Join-Path $ProjectRoot ($reference -replace '^/', '' -replace '/', '\')
        if (Test-Path -LiteralPath $target -PathType Leaf) { Write-Check PASS ("JavaScript fetch target: {0} -> {1}" -f $file.Name, $reference) }
        else { Write-Check FAIL ("Missing JavaScript fetch target: {0} -> {1}" -f $file.Name, $reference) }
    }
}

$requiredPaths = @(
    'includes\env.php', 'includes\db_connection.php', 'config\database.php',
    'includes\.htaccess', 'config\.htaccess', 'admin\.htaccess', 'database\setup.sql', 'ml\api.py', 'python-rag\app.py', 'python-rag\config.py',
    '.env.example', 'requirements.txt', 'python-rag\requirements.txt'
)
foreach ($relative in $requiredPaths) {
    if (Test-Path -LiteralPath (Join-Path $ProjectRoot $relative) -PathType Leaf) { Write-Check PASS "Required file: $relative" }
    else { Write-Check FAIL "Required file missing: $relative" }
}

# Report configuration names only; never read or print .env values.
$templatePath = Join-Path $ProjectRoot '.env.example'
$templateNames = @()
if (Test-Path $templatePath) {
    $templateNames = @(Get-Content -LiteralPath $templatePath | ForEach-Object {
        if ($_ -match '^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=') { $Matches[1] }
    } | Sort-Object -Unique)
}
$neededNames = @('DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'RAG_SERVICE_TOKEN', 'RAG_URL', 'ML_API_TOKEN', 'ML_API_URL', 'DEVICE_INGEST_TOKEN', 'DEVICE_PATIENT_ID')
foreach ($name in $neededNames) {
    if ($templateNames -contains $name) { Write-Check PASS "Environment name documented in .env.example: $name" }
    else { Write-Check WARN "Environment name absent from .env.example: $name" }
}

# Compile application Python files without traversing installed packages.
$pythonCommand = Get-Command python -ErrorAction SilentlyContinue
$pythonExe = if (Test-Path (Join-Path $ProjectRoot 'ml\.venv\Scripts\python.exe')) { Join-Path $ProjectRoot 'ml\.venv\Scripts\python.exe' } elseif (Test-Path (Join-Path $ProjectRoot 'python-rag\.venv\Scripts\python.exe')) { Join-Path $ProjectRoot 'python-rag\.venv\Scripts\python.exe' } elseif ($pythonCommand) { $pythonCommand.Source } else { $null }
if (-not $pythonExe) {
    Write-Check WARN 'Python was not found; Python compilation and tests were not run.'
} else {
    $pythonCompile = @'
import pathlib, py_compile, sys
root = pathlib.Path(sys.argv[1])
paths = [p for folder in (root / "ml", root / "python-rag") for p in folder.rglob("*.py")
         if not any(part in {".venv", "venv", "__pycache__", ".pytest_cache"} for part in p.parts)]
bad = []
for path in paths:
    try: py_compile.compile(str(path), doraise=True, cfile=str(path.with_suffix(".pyc")) + ".verify")
    except Exception as exc: bad.append((path, str(exc)))
for path, error in bad: print(f"FAIL {path.relative_to(root)}: {error}")
print(f"Compiled {len(paths) - len(bad)}/{len(paths)} Python files")
sys.exit(bool(bad))
'@
    $pythonEncoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($pythonCompile))
    $pythonRunner = "import base64; exec(base64.b64decode('$pythonEncoded'))"
    & $pythonExe -c $pythonRunner $ProjectRoot
    if ($LASTEXITCODE -eq 0) { Write-Check PASS 'Python application compilation completed.' }
    else { Write-Check FAIL 'Python application compilation failed.' }
    Get-ChildItem -Path (Join-Path $ProjectRoot 'ml'), (Join-Path $ProjectRoot 'python-rag') -Recurse -Filter '*.pyc.verify' -File -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue
    if ($RunPythonTests) {
        & $pythonExe -m pytest (Join-Path $ProjectRoot 'ml\tests') (Join-Path $ProjectRoot 'python-rag\tests') -q
        if ($LASTEXITCODE -eq 0) { Write-Check PASS 'Python test suites completed.' }
        else { Write-Check FAIL 'Python test suites reported failures.' }
    }
}

# Check model artifacts only when paths referenced by the current ML code exist.
$modelFiles = @($sourceFiles | Where-Object { $_.FullName -match '\\ml\\models\\' -and $_.Extension -in @('.joblib', '.pkl', '.pickle') })
if ($modelFiles.Count) { Write-Check PASS ("Found {0} saved model artifact(s) under ml/models." -f $modelFiles.Count) }
else { Write-Check WARN 'No saved model artifact was found under ml/models; prediction availability depends on configured/current model paths.' }

if ($CheckLocalServices) {
    foreach ($endpoint in @('http://127.0.0.1:8000/health', 'http://127.0.0.1:8001/health')) {
        try {
            $response = Invoke-RestMethod -Uri $endpoint -TimeoutSec 3 -ErrorAction Stop
            Write-Check PASS ("Local service responded: {0}" -f $endpoint)
        } catch { Write-Check WARN ("Local service unavailable or unhealthy: {0}" -f $endpoint) }
    }
} else {
    Write-Check INFO 'Local service checks skipped; rerun with -CheckLocalServices when RAG and ML APIs are started.'
}

Write-Host ("Summary: {0} failure(s), {1} warning(s)." -f $script:Failures, $script:Warnings) -ForegroundColor White
if ($script:Failures -gt 0) { exit 1 }
exit 0
