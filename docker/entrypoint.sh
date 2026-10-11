#!/bin/sh
# Startet den Community-Hangar: Einstellungen prüfen, Datenbank migrieren, Katalog-Abgleich im
# Hintergrund einplanen, dann Apache starten.
set -e

missing=""
for v in DB_HOST DB_NAME DB_USER DB_PASSWORD AUTH_DISCORD_ID AUTH_DISCORD_SECRET; do
  eval "val=\${$v:-}"
  [ -n "$val" ] || missing="$missing $v"
done
# Schlüssel für die Verschlüsselung der Discord-Tokens in der Datenbank (nach dem Anlegen nicht mehr ändern)
if [ -z "${APP_KEY:-}" ]; then
  missing="$missing APP_KEY(erzeugen mit: openssl rand -base64 32, in die .env eintragen)"
elif [ "${#APP_KEY}" -lt 32 ]; then
  missing="$missing APP_KEY(zu kurz, mindestens 32 Zeichen)"
fi
if [ -z "${AUTH_URL:-}" ] && [ -z "${DOMAIN:-}" ]; then missing="$missing AUTH_URL(oder DOMAIN)"; fi
if [ -n "$missing" ]; then
  echo "Fehlende Einstellungen:$missing" >&2
  exit 1
fi
[ -n "${AUTH_URL:-}" ] || export AUTH_URL="https://${DOMAIN}"

# Bilder-Volume gehört dem Webserver-Benutzer
mkdir -p /var/www/html/storage/images
chown -R www-data:www-data /var/www/html/storage

# Auf die Datenbank warten (beim ersten Start braucht MariaDB einen Moment)
i=0
until php -r '
  try {
    new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: "3306").";dbname=".getenv("DB_NAME"), getenv("DB_USER"), getenv("DB_PASSWORD"));
  } catch (Throwable $e) { exit(1); }
' 2>/dev/null; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then echo "Datenbank nicht erreichbar (DB_HOST=$DB_HOST)." >&2; exit 1; fi
  sleep 2
done

php /var/www/html/bin/migrate.php
# Noch unverschlüsselte Discord-Tokens (Altbestand) verschlüsseln; ein Fehler stoppt den Start nicht, die Tokens bleiben lesbar.
php /var/www/html/bin/encrypt-tokens.php || echo "Verschlüsselung der Tokens fehlgeschlagen, siehe Meldung oben." >&2

# Katalog (Ship Matrix, FleetYards, Wiki) und Item-Infos regelmäßig abgleichen. Fehler stoppen die App nicht.
(
  sleep 20
  while true; do
    su -s /bin/sh www-data -c "php /var/www/html/bin/catalog-sync.php" || echo "Katalog-Abgleich fehlgeschlagen, nächster Versuch beim nächsten Lauf." >&2
    su -s /bin/sh www-data -c "php /var/www/html/bin/enrich-items.php" || true
    sleep "${CATALOG_SYNC_INTERVAL_SECONDS:-7200}"
  done
) &

# Zugangslisten des Onboarding-Bots stündlich abgleichen (ohne DISCORD_BOT_TOKEN passiert nichts).
(
  sleep 60
  while true; do
    su -s /bin/sh www-data -c "php /var/www/html/bin/sync-allowlist.php" || echo "Zugangslisten-Abgleich fehlgeschlagen." >&2
    sleep 3600
  done
) &

# Discord-Server-Events als Termine übernehmen (nur Orgas mit eingeschalteter Übernahme; ohne DISCORD_BOT_TOKEN passiert nichts).
(
  sleep 45
  while true; do
    su -s /bin/sh www-data -c "php /var/www/html/bin/discord-events.php" || echo "Discord-Events-Abgleich fehlgeschlagen." >&2
    sleep "${DISCORD_EVENTS_INTERVAL_SECONDS:-300}"
  done
) &

# Mitgliederlisten der RSI-Orgas täglich abgleichen (nur Orgas mit hinterlegtem RSI-Kürzel).
(
  sleep 90
  while true; do
    su -s /bin/sh www-data -c "php /var/www/html/bin/rsi-sync.php" || echo "RSI-Abgleich fehlgeschlagen." >&2
    sleep "${RSI_SYNC_INTERVAL_SECONDS:-86400}"
  done
) &

exec "$@"
