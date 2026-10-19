<?php
declare(strict_types=1);

// The Windows worker wrapper must hand Python exact values: the PHP launcher's
// escapeshellarg() turns `set "NAME=1"` into `set NAME=1 ` (trailing space), and
// an unset optional value must stay unset instead of becoming the text " =".
if (PHP_OS_FAMILY !== 'Windows') {
    echo "AI worker wrapper test skipped (Windows only).\n";
    exit(0);
}

function wrapper_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = realpath(__DIR__ . '/..');
$probeDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aics-wrapper-' . bin2hex(random_bytes(4));
mkdir($probeDir);
$probe = $probeDir . DIRECTORY_SEPARATOR . 'python.cmd';
file_put_contents($probe, "@echo off\r\necho TEST=[%AICS_HTTP_TEST_MODE%] DB=[%AICS_TEST_DB_NAME%] CONNECT=[%ADCD_CONNECT_TIMEOUT%] READ=[%ADCD_READ_TIMEOUT%] ARGS=[%*]\r\n");

$env = getenv();
foreach (['ADCD_CONNECT_TIMEOUT', 'ADCD_READ_TIMEOUT', 'AICS_ADCD_SERVICE_URL'] as $name) unset($env[$name]);
$env['AICS_PYTHON_EXECUTABLE'] = $probe;
$env['AICS_PROJECT_ROOT'] = $root;
$env['AICS_HTTP_TEST_MODE'] = '1 ';
$env['AICS_TEST_DB_NAME'] = 'aics_http_regression ';
$process = proc_open(['cmd.exe', '/D', '/C', $root . '\\ai_processor\\run-ai-worker.bat', '--request-id', '7'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
$output = trim((string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]));
$exit = proc_close($process);
unlink($probe);
rmdir($probeDir);

wrapper_assert($exit === 0, 'Wrapper failed: ' . $output);
wrapper_assert(str_contains($output, 'TEST=[1]'), 'Test mode must reach Python as exactly 1: ' . $output);
wrapper_assert(str_contains($output, 'DB=[aics_http_regression]'), 'Test database name must not carry spaces: ' . $output);
wrapper_assert(str_contains($output, 'CONNECT=[]') && str_contains($output, 'READ=[]'), 'Unset ADCD timeouts must stay unset: ' . $output);
wrapper_assert(str_contains($output, 'ARGS=[-m ai_processor.processor ') && str_contains($output, '--request-id'), 'Worker arguments must pass through: ' . $output);
echo "AI worker wrapper test passed.\n";
