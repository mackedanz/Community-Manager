# Startet nur die lokale MariaDB (ohne Webserver), z. B. für die Tests.
$ErrorActionPreference = "Stop"
$tools = Join-Path $PSScriptRoot "tools"
$data  = Join-Path $env:LOCALAPPDATA "community-hangar\mariadb-data"
$port  = 3307
function Test-Port($p) { try { $c = New-Object Net.Sockets.TcpClient; $c.Connect("127.0.0.1", $p); $c.Close(); $true } catch { $false } }
if (Test-Port $port) { Write-Host "MariaDB läuft schon."; return }
Start-Process -FilePath (Join-Path $tools "mariadb\bin\mariadbd.exe") `
    -ArgumentList "--datadir=`"$data`"", "--port=$port", "--bind-address=127.0.0.1", "--skip-name-resolve", "--innodb-flush-log-at-trx-commit=2", "--console" `
    -WindowStyle Hidden -RedirectStandardError (Join-Path $tools "mariadb.log") | Out-Null
for ($i = 0; $i -lt 40 -and -not (Test-Port $port); $i++) { Start-Sleep -Milliseconds 500 }
if (-not (Test-Port $port)) { throw "MariaDB startet nicht, siehe tools\mariadb.log" }
Write-Host "MariaDB läuft auf Port $port."
