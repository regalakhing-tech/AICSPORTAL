param(
    [string]$Php = 'C:\xampp\php\php.exe',
    [int]$Port = 8091
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$phpPath = (Resolve-Path $Php).Path
$baseUrl = "http://127.0.0.1:$Port/Applicants"
$healthUrl = "http://127.0.0.1:$Port/tests/http-regression-health.php"
$env:AICS_HTTP_TEST_MODE = '1'
$env:AICS_TEST_DB_HOST = if ($env:AICS_TEST_DB_HOST) { $env:AICS_TEST_DB_HOST } else { 'localhost' }
$env:AICS_TEST_DB_PORT = if ($env:AICS_TEST_DB_PORT) { $env:AICS_TEST_DB_PORT } else { '3306' }
$env:AICS_TEST_DB_NAME = if ($env:AICS_TEST_DB_NAME) { $env:AICS_TEST_DB_NAME } else { 'aics_http_regression' }
$env:AICS_TEST_DB_USER = if ($env:AICS_TEST_DB_USER) { $env:AICS_TEST_DB_USER } else { 'root' }
$env:AICS_TEST_DB_PASSWORD = if ($null -ne $env:AICS_TEST_DB_PASSWORD) { $env:AICS_TEST_DB_PASSWORD } else { '' }
$env:AICS_HTTP_BASE_URL = $BaseUrl

function Get-ListenerPids([int]$ListenerPort) {
    $pattern = "127.0.0.1:$ListenerPort\s+\S+\s+LISTENING\s+(\d+)"
    @(& netstat -ano -p tcp) | ForEach-Object { if ($_ -match $pattern) { [int]$matches[1] } }
}
function Show-ServerLogTail([string]$Path, [string]$Label) {
    if (Test-Path $Path) { Write-Host "--- PHP server $Label (tail) ---"; Get-Content $Path -Tail 40 | ForEach-Object { Write-Host $_ } }
}

if ($env:AICS_TEST_DB_NAME -ne 'aics_http_regression') { throw 'Refusing a non-isolated HTTP test database.' }
if (-not (Test-Path $phpPath -PathType Leaf)) { throw 'Configured PHP executable is unavailable.' }
if (Get-ListenerPids $Port) { throw "HTTP regression port $Port is already occupied; no process was stopped." }
& $phpPath '-v' | Select-Object -First 2
Write-Host "PHP executable: $phpPath"
Write-Host "Document root: $projectRoot"
Write-Host "HTTP test mode present: $($env:AICS_HTTP_TEST_MODE -eq '1')"
Write-Host "Test DB host configured: $([bool]$env:AICS_TEST_DB_HOST)"
Write-Host "Test DB name: $($env:AICS_TEST_DB_NAME)"
Write-Host "Test DB user configured: $([bool]$env:AICS_TEST_DB_USER)"
& $phpPath '-r' "echo 'allow_url_fopen=' . ini_get('allow_url_fopen') . PHP_EOL;"
& $phpPath 'tests/http-regression-db.php' 'create'
if ($LASTEXITCODE -ne 0) { throw 'Isolated HTTP regression database setup failed.' }
$runId = [guid]::NewGuid().ToString('N')
$stdout = Join-Path ([IO.Path]::GetTempPath()) "aics-http-server-$runId.out.log"
$stderr = Join-Path ([IO.Path]::GetTempPath()) "aics-http-server-$runId.err.log"
$phpTemporaryDirectory = Join-Path ([IO.Path]::GetTempPath()) "aics-http-runtime-$runId"
$null = New-Item -ItemType Directory -Path $phpTemporaryDirectory -Force
$server = $null
try {
    # XAMPP's shared temp directory can be unwritable to the isolated CLI
    # server. Keep sessions and upload scratch data private to this run.
    $server = Start-Process -FilePath $phpPath -ArgumentList @('-d', "session.save_path=$phpTemporaryDirectory", '-d', "upload_tmp_dir=$phpTemporaryDirectory", '-S', "127.0.0.1:$Port", '-t', $projectRoot) -WorkingDirectory $projectRoot -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru -WindowStyle Hidden
    $ready = $false
    for ($attempt = 1; $attempt -le 20; $attempt++) {
        if ($server.HasExited) { throw "HTTP regression server exited before readiness (exit code $($server.ExitCode))." }
        if (@(Get-ListenerPids $Port) -notcontains $server.Id) { Start-Sleep -Milliseconds 250; continue }
        try {
            $response = Invoke-WebRequest -UseBasicParsing -TimeoutSec 2 $healthUrl
            $health = $response.Content | ConvertFrom-Json
            if ($response.StatusCode -eq 200 -and $health.ok -eq $true -and $health.test_db_name -eq 'aics_http_regression') {
                $ready = $true
                Write-Host "HTTP server ready: pid=$($server.Id) port=$Port database=$($health.test_db_name)"
                break
            }
        } catch { }
        Start-Sleep -Milliseconds 250
    }
    if (-not $ready) { throw 'HTTP regression server failed readiness check.' }
    foreach ($test in @('applicant-edit-hydration-regression.php', 'applicant-submission-auth-regression.php', 'application-data-flow-regression.php')) {
        & $phpPath (Join-Path 'tests' $test)
        if ($LASTEXITCODE -ne 0) { throw "HTTP regression failed: $test" }
        Write-Output "Passed: $test"
    }
} catch {
    Show-ServerLogTail $stdout 'stdout'
    Show-ServerLogTail $stderr 'stderr'
    throw
} finally {
    if ($null -ne $server -and -not $server.HasExited) { Stop-Process -Id $server.Id -Force }
    Remove-Item -LiteralPath $stdout,$stderr -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $phpTemporaryDirectory -Recurse -Force -ErrorAction SilentlyContinue
}
