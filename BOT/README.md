# Discord-Bot des Community-Managers

Ein dauerhaft laufender Discord-Bot (Node.js, discord.js), der direkt auf die MariaDB des Community-Managers zugreift.
Er soll nach und nach alle Funktionen des Community-Managers in Discord bedienbar machen.

Die Tabellen gehören weiter den PHP-Migrationen im Ordner `migrations/` des Hauptprojekts. Der Bot legt keine Tabellen
an und ändert sie nicht.

## Befehle

| Befehl | Wer darf | Was passiert |
| --- | --- | --- |
| `/einrichten` | Server-Admins | Orga anlegen, RSI-Orga verbinden, Rollen wählen; jede Rollenänderung gleicht die Zugangsliste ab |
| `/design` | Server-Admins | Logo, Hintergrundbild und Deckkraft der Web-App setzen oder zurücksetzen |
| `/abgleichen` | Server-Admins und Mitglieder mit Nutzungs-Rolle | Zugangsliste mit den Discord-Rollen abgleichen |
| `/ping` | alle | Verbindung zu Discord und zur Datenbank prüfen |

Die Befehle verhalten sich wie die gleichnamigen Befehle der PHP-App (`src/Controllers/DiscordController.php`).

## Lokal starten

Voraussetzung: Node.js 22.9 oder neuer und die lokale Datenbank (`start-db.ps1` oder `dev.ps1` im Hauptordner).

```
npm install
copy .env.example .env      # ausfüllen
npm run register            # Befehle auf dem Testserver anmelden
npm start
```

## Tests

`npm test` führt alle Tests aus. Die Datenbanktests laufen nur, wenn `TEST_DB_HOST` gesetzt ist; sie brauchen eine
Datenbank mit dem Schema der PHP-Migrationen (`php bin/migrate.php` im Hauptordner mit `DB_NAME` der Testdatenbank).

```
$env:TEST_DB_HOST="127.0.0.1"; $env:TEST_DB_PORT="3307"; $env:TEST_DB_NAME="hangar_test"; npm test
```

## Im Docker-Stack

Der Dienst `bot` in der `docker-compose.yml` startet nur, wenn in der `.env` `COMPOSE_PROFILES=bot` steht.
Er nutzt dieselben Werte wie die App (`DOMAIN`, `AUTH_DISCORD_ID`, `DISCORD_BOT_TOKEN`, `DB_PASSWORD` usw.) und
dasselbe Bilder-Volume, damit `/design` die Bilder dort ablegt, wo die App sie ausliefert.

Im Developer Portal muss unter "Bot" der Intent **Server Members** eingeschaltet sein (wie schon für die PHP-App).

## Umstieg von der PHP-App auf den Bot

Solange im Developer Portal eine "Interactions Endpoint URL" eingetragen ist, schickt Discord alle Slash-Befehle an die
PHP-App und nicht an den Bot. Zum Entwickeln deshalb eine eigene Test-Anwendung nutzen.

Umstieg auf dem Server:

1. Bot starten: `COMPOSE_PROFILES=bot` in die `.env`, dann `docker compose up -d`.
2. Befehle des Bots für alle Server anmelden (ersetzt die Befehle der PHP-App):
   `docker compose exec bot node src/register.js --global`
3. Im Developer Portal unter "General Information" die "Interactions Endpoint URL" leeren und speichern.

Danach `bin/register-commands.php` der PHP-App nicht mehr ausführen: es würde die Befehle des Bots wieder
überschreiben. Zurück geht es in umgekehrter Reihenfolge (URL wieder eintragen, `php bin/register-commands.php`).

## Befehle anmelden

- `npm run register` meldet die Befehle nur auf dem Server `BOT_DEV_GUILD_ID` an.
- `npm run register -- --global` meldet sie für alle Server an und **ersetzt dabei alle globalen Befehle der
  Anwendung**.
