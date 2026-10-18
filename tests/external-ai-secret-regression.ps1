$ErrorActionPreference = 'Stop'

$matches = & git grep -n -E 'sk-[A-Za-z0-9_-]{20,}|Authorization:[[:space:]]*Bearer[[:space:]]+[A-Za-z0-9_-]{12,}' -- . ':!Applicants/uploads/**' ':!output/**' ':!scratch/**' 2>$null
if ($LASTEXITCODE -eq 0 -and $matches) {
    Write-Error "Live-looking external AI credential found in tracked files.`n$matches"
    exit 1
}
if ($LASTEXITCODE -notin @(0, 1)) {
    Write-Error 'Unable to scan tracked files for external AI credentials.'
    exit 2
}

Write-Output 'External AI secret regression passed.'
