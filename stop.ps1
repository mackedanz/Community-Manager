# Beendet die lokale MariaDB der Testumgebung.
$maria = Join-Path $PSScriptRoot "tools\mariadb\bin\mariadb-admin.exe"
& $maria -u root -h 127.0.0.1 -P 3307 shutdown
