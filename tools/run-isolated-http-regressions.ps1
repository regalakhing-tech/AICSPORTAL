$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$php = 'C:\xampp\php\php.exe'

$env:AICS_DB_NAME = 'aics_http_regression'
$env:AICS_HTTP_TEST_MODE = '1'
$env:AICS_HTTP_BASE_URL = 'http://localhost/AICS-HTTP-TEST/Applicants'

try {
    & $php "$projectRoot\tests\http-regression-db.php" create
    & $php "$projectRoot\tests\application-data-flow-regression.php"
    & $php "$projectRoot\tests\applicant-edit-hydration-regression.php"
    & $php "$projectRoot\tests\applicant-submission-auth-regression.php"
} finally {
    & $php "$projectRoot\tests\http-regression-db.php" drop
}
