# Discord-Bot des Community-Managers

Ein dauerhaft laufender Discord-Bot (Node.js, discord.js), der direkt auf die MariaDB des Community-Managers zugreift.
Er soll nach und nach alle Funktionen des Community-Managers in Discord bedienbar machen und die Slash-Befehle der
PHP-App (`/einrichten`, `/design`, `/abgleichen`) ablösen.

Die Tabellen gehören weiter den PHP-Migrationen im Ordner `migrations/` des Hauptprojekts. Der Bot legt keine Tabellen
an und ändert sie nicht.

## Stand

Phase 1 (Grundgerüst): Verbindung zu Discord und zur Datenbank, Befehl `/ping`.

## Lokal starten

Voraussetzung: Node.js 22.9 oder neuer und die lokale Datenbank (`start-db.ps1` oder `dev.ps1` im Hauptordner).

```
npm install
copy .env.example .env      # ausfüllen
npm run register            # Befehle auf dem Testserver anmelden
npm start
```

Tests: `npm test`

## Im Docker-Stack

Der Dienst `bot` in der `docker-compose.yml` startet nur, wenn in der `.env` `COMPOSE_PROFILES=bot` steht.
Er nutzt dieselben Werte wie die App (`AUTH_DISCORD_ID`, `DISCORD_BOT_TOKEN`, `DB_PASSWORD`).

## Befehle anmelden

- `npm run register` meldet die Befehle nur auf dem Server `BOT_DEV_GUILD_ID` an.
- `npm run register -- --global` meldet sie für alle Server an und **ersetzt dabei alle globalen Befehle der
  Anwendung**, auch die der PHP-App. Erst nutzen, wenn der Bot `/einrichten`, `/design` und `/abgleichen` selbst kann.

## Wichtig beim Umstieg

Solange im Developer Portal eine "Interactions Endpoint URL" eingetragen ist, schickt Discord alle Slash-Befehle dieser
Anwendung an die PHP-App und nicht an den Bot. Zum Entwickeln deshalb eine eigene Test-Anwendung nutzen. Beim Umstieg
die URL im Developer Portal leeren.
