# Führt die PHPUnit-Tests aus (MariaDB muss laufen: dev.ps1 oder start-db.ps1).
# Nutzung:  powershell -ExecutionPolicy Bypass -File test.ps1 [phpunit-Argumente]
$root = $PSScriptRoot
Set-Location $root
# Auf dem Netzlaufwerk meldet PHP neue Dateien als "nicht lesbar"; PHPUnit prüft das.
icacls * /grant "Jeder:(R)" /T /C /Q 2>&1 | Out-Null
& (Join-Path $root "tools\php\php.exe") vendor\bin\phpunit --no-coverage @args
