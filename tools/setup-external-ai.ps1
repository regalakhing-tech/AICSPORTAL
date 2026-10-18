[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$privateDirectory = 'C:\xampp\aics_private'
$destination = Join-Path $privateDirectory 'aics-ai.env'
$secureKey = Read-Host 'Paste the rotated Groq API key' -AsSecureString
$keyPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureKey)
try {
    $plainKey = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($keyPointer)
    if ([string]::IsNullOrWhiteSpace($plainKey)) {
        throw 'The API key cannot be blank.'
    }
    if ($plainKey.Contains("`r") -or $plainKey.Contains("`n")) {
        throw 'The API key must be a single line.'
    }
    [IO.Directory]::CreateDirectory($privateDirectory) | Out-Null
    $lines = @(
        'AICS_EXTERNAL_AI_ENABLED=1',
        'AICS_EXTERNAL_AI_ENDPOINT=https://api.groq.com/openai/v1/chat/completions',
        'AICS_EXTERNAL_AI_MODEL=openai/gpt-oss-20b',
        ('AICS_EXTERNAL_AI_API_KEY=' + $plainKey),
        'AICS_EXTERNAL_AI_TIMEOUT_SECONDS=12',
        'AICS_EXTERNAL_AI_IMAGE_ENABLED=0',
        'AICS_EXTERNAL_AI_STATE_ROOT=C:\xampp\aics_private\external_ai'
    )
    [IO.File]::WriteAllLines($destination, $lines, [Text.UTF8Encoding]::new($false))
} finally {
    if ($keyPointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($keyPointer)
    }
    $plainKey = $null
    $secureKey = $null
}

Write-Host ('External AI configuration saved to ' + $destination)
Write-Host 'Next: python -m ai_processor.external_ai.probe --config-json'
