# Startet die lokale Testumgebung (portable MariaDB + PHP-Entwicklungsserver).
# Nutzung:  powershell -ExecutionPolicy Bypass -File dev.ps1
$ErrorActionPreference = "Stop"
$root  = $PSScriptRoot
$tools = Join-Path $root "tools"
$php   = Join-Path $tools "php\php.exe"
$maria = Join-Path $tools "mariadb\bin"
# Die Datenbankdateien liegen bewusst auf der lokalen Platte: auf einem Netzlaufwerk ist InnoDB sehr langsam.
$data  = Join-Path $env:LOCALAPPDATA "community-hangar\mariadb-data"
$port  = 3307

function Test-Port($p) {
    try { $c = New-Object Net.Sockets.TcpClient; $c.Connect("127.0.0.1", $p); $c.Close(); return $true } catch { return $false }
}

if (-not (Test-Port $port)) {
    Write-Host "Starte MariaDB auf Port $port ..."
    Start-Process -FilePath (Join-Path $maria "mariadbd.exe") `
        -ArgumentList "--datadir=`"$data`"", "--port=$port", "--bind-address=127.0.0.1", "--skip-name-resolve", "--innodb-flush-log-at-trx-commit=2", "--console" `
        -WindowStyle Hidden -RedirectStandardError (Join-Path $tools "mariadb.log") | Out-Null
    for ($i = 0; $i -lt 40 -and -not (Test-Port $port); $i++) { Start-Sleep -Milliseconds 500 }
    if (-not (Test-Port $port)) { throw "MariaDB startet nicht, siehe tools\mariadb.log" }
}

$sql = "CREATE DATABASE IF NOT EXISTS hangar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; " +
       "CREATE USER IF NOT EXISTS 'hangar'@'127.0.0.1' IDENTIFIED BY 'hangar'; " +
       "CREATE USER IF NOT EXISTS 'hangar'@'localhost' IDENTIFIED BY 'hangar'; " +
       "GRANT ALL ON hangar.* TO 'hangar'@'127.0.0.1'; GRANT ALL ON hangar.* TO 'hangar'@'localhost'; " +
       "CREATE DATABASE IF NOT EXISTS hangar_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; " +
       "GRANT ALL ON hangar_test.* TO 'hangar'@'127.0.0.1'; GRANT ALL ON hangar_test.* TO 'hangar'@'localhost';"
& (Join-Path $maria "mariadb.exe") -u root -h 127.0.0.1 -P $port -e $sql

$env:DB_HOST = "127.0.0.1"; $env:DB_PORT = "$port"; $env:DB_NAME = "hangar"
$env:DB_USER = "hangar"; $env:DB_PASSWORD = "hangar"
# Nur für die lokale Entwicklung: Schlüssel zur Verschlüsselung der Discord-Tokens (im Betrieb kommt APP_KEY aus der .env)
if (-not $env:APP_KEY) { $env:APP_KEY = "lokaler-entwicklungsschluessel-nur-zum-testen-123456" }
Set-Location $root
& $php bin/migrate.php
Write-Host "Server: http://localhost:8080  (Strg+C beendet den PHP-Server; MariaDB mit stop.ps1)"
& $php -S 127.0.0.1:8080 -t public public/router.php
