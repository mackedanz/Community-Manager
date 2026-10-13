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
| `/termine` | Mitglieder der Orga | kommende Termine ansehen und mit „Dabei“, „Vielleicht“, „Kann nicht“ antworten (nur für einen selbst sichtbar) |
| `/termine-kanal` | Server-Admins | Kanal für Terminankündigungen setzen, anzeigen oder mit `aus:True` ausschalten |
| `/ausbildung` | Mitglieder der Orga | eigene bestandene Ausbildungen; Admins und Ausbilder auch die eines Mitglieds |
| `/hangar` | Mitglieder der Orga | eigener Hangar (Schiffe und Zahl der übrigen Einträge); mit `mitglied:@…` die Schiffe eines Mitglieds, wenn freigegeben |
| `/flotte` | Mitglieder der Orga | alle Schiffe der Orga mit Anzahl, anonym; `suche:` filtert nach Name oder Hersteller |
| `/wer-hat` | Mitglieder der Orga | wer ein Schiff hat (Vorschläge aus dem Katalog beim Tippen); nur freigegebene Hangars |
| `/profil` | Mitglieder der Orga | RSI-Handle, Schiffe, Errungenschaften, Ausbildungen, letzter RSI-Sync |
| `/termin-neu` | Planer und Admins | Termin anlegen: Optionen für Ausbildung, Abteilung, Entwurf und Discord-Event, danach ein Fenster für Titel, Beginn, Ende, Ort und Beschreibung (Zeiten in deutscher Zeit, z. B. `14.10.2026 20:00`) |
| `/orga` | Server-Admins | Einstellungen der Orga ansehen; `name:` benennt sie um, `discord_events:` schaltet die Übernahme der Discord-Events |
| `/betreiber` | nur `SERVER_ADMIN_DISCORD_ID` | `orgas` (alle Orgas und Sperren), `sperren`, `entsperren`, `loeschen` (mit Rückfrage) |
| `/creator` | Server-Admins | YouTube-Creator: `hinzufuegen` (Link oder @Name, Discord-Kanal, optional Rolle zum Erwähnen und ohne Shorts), `entfernen`, `liste` |
| `/mod` | Moderatoren und Admins | `verwarnen`, `timeout`, `timeout-aufheben`, `kick`, `bann`, `entbannen`, `warnungen`, `warnung-entfernen`, `loeschen` (siehe Abschnitt Moderation) |
| `/mod-einstellungen` | Server-Admins | Moderatoren-Rollen und Protokoll-Kanal festlegen |
| `/automod` | Server-Admins | automatische Moderation: Spam, Einladungslinks, Links, verbotene Wörter, Massen-Erwähnungen |
| `/serverregeln` | Server-Admins | Text nach Wahl mit „Bestätigen“-Schaltfläche in einem Kanal veröffentlichen; „Bestätigen“ vergibt die gewählte Rolle |
| `/ping` | alle | Verbindung zu Discord und zur Datenbank prüfen |
| `/hilfe`, `/help` | alle | Übersicht aller Befehle, erzeugt aus der Befehlsliste; Admin-Befehle sehen nur Admins |

Sichtbarkeit wie auf der Webseite: Den eigenen Hangar sieht man immer. Schiffe und Errungenschaften anderer nur, wenn
sie das in den Einstellungen der Web-App für Mitglieder freigegeben haben und Mitglied dieser Orga sind. `/flotte`
ist anonym und zählt alle Schiffe der Mitglieder, ohne zu zeigen, wem sie gehören.

Antworten über `/termine` brauchen ein Konto in der Web-App (einmal dort mit Discord anmelden). Für
Ausbildungstermine gilt wie auf der Webseite: erst nach bestandener Vorstufe (Admins und Ausbilder ausgenommen).

Planer und Admins sehen in `/termine` auch Entwürfe und unter jedem Termin „Absagen“ bzw. „Wieder aktivieren“ und
„Löschen“ (mit Rückfrage). Schiffe und Plätze eines Termins sowie das Bearbeiten bleiben auf der Webseite.

Admins und Ausbilder sehen bei Ausbildungsterminen in `/termine` zusätzlich „Teilnahme bestätigen“: eine Auswahl
der Zusagen, in der sie markieren, wer bestanden hat (wie auf der Webseite).

## Ankündigungen und Erinnerungen

Ist mit `/termine-kanal` ein Kanal gesetzt, kündigt der Bot dort jeden geplanten Termin der nächsten 30 Tage an,
mit Knöpfen für „Dabei“, „Vielleicht“ und „Kann nicht“ (die Rückmeldung sieht nur, wer drückt). Ändert sich der
Termin oder eine Antwort, passt der Bot die Nachricht an; wird der Termin gelöscht oder wieder Entwurf, verschwindet
sie. Löscht jemand die Nachricht in Discord, postet der Bot sie nicht erneut.

24 Stunden und 1 Stunde vor Beginn erinnert der Bot alle mit „Dabei“ oder „Vielleicht“ per Direktnachricht und
mit einem Hinweis unter der Ankündigung. Der Bot prüft das jede Minute. Beides braucht Migration `020_bot.sql`,
die die Web-App beim Start einspielt.

Für `/serverregeln` braucht der Bot das Recht „Rollen verwalten“, und seine Rolle muss in den Servereinstellungen
über der Rolle stehen, die er vergeben soll. Der Befehl prüft das vorher und sagt, was fehlt.

Die früheren Slash-Befehle der PHP-App (Interactions Endpoint `POST /discord/interactions`) gibt es nicht mehr; alle
Befehle laufen über diesen Bot.

## Neue Videos von Creatorn

Ersatz für die YouTube-Benachrichtigungen von MEE6: Alle 10 Minuten liest der Bot den öffentlichen Feed jedes mit
`/creator hinzufuegen` eingetragenen YouTube-Kanals (ohne API-Schlüssel) und postet neue Videos in den gewählten Kanal,
auf Wunsch mit Erwähnung einer Rolle. Beim Hinzufügen gelten alle vorhandenen Videos als bekannt, gepostet wird erst das
nächste. Jedes Video erscheint nur einmal, auch nach einem Neustart. Braucht Migration `021_bot_creators.sql`.
Twitch-Livestreams sind noch nicht dabei (bräuchten einen Twitch-API-Zugang).

## Moderation

`/mod-einstellungen rolle-hinzufuegen` legt Moderatoren-Rollen fest, `protokoll-kanal` den Kanal für das Protokoll. Moderieren dürfen
Server-Admins und Mitglieder dieser Rollen; Moderatoren können keine Admins, keine anderen Moderatoren und niemanden mit gleich hoher
oder höherer Rolle moderieren. Jede Aktion wird als Fall gespeichert (Tabelle `bot_mod_cases`), der Person per Direktnachricht
mitgeteilt und im Protokoll-Kanal gezeigt, dort auch Beitritte und Austritte. Die Rolle des Bots muss in der Rollenliste über den
Rollen stehen, die er moderieren soll.

**Automod und Nachrichten-Protokoll** brauchen den Intent "Message Content": im Developer Portal unter Bot einschalten, in der `.env`
`BOT_MESSAGE_CONTENT=1` setzen und den Bot neu starten. Ohne diesen Schalter startet der Bot wie bisher, nur ohne diese beiden Funktionen.
`/automod` schaltet die Regeln einzeln ein (alle sind zunächst aus). Bei einem Verstoß wird die Nachricht gelöscht, ein kurzer Hinweis
erscheint im Kanal, der Fall wird gespeichert; ab 3 Verstößen in einer Stunde gibt es 10 Minuten Timeout. Das Nachrichten-Protokoll
meldet gelöschte und geänderte Nachrichten, die der Bot seit seinem Start gesehen hat (je Kanal die letzten 200); gespeichert wird
nichts in der Datenbank.

Zusätzliche Rechte für die Moderation: Mitglieder kicken, Mitglieder bannen, Mitglieder moderieren, Nachrichten verwalten
(`permissions=19009928424518`).

## Automatischer Abgleich

Tritt jemand dem Server bei oder verlässt ihn, bekommt oder verliert jemand eine Rolle der Orga oder ändert seinen
Namen, gleicht der Bot die Zugangsliste etwa 15 Sekunden später ab. Viele Änderungen kurz hintereinander werden zu
einem Abgleich zusammengefasst (spätestens nach einer Minute), damit die Sicherheitsbremse gegen das Löschen vieler
Konten auch hier greift. Nach dem Start gleicht der Bot alle Server einmal ab. Wessen Rollen sich ändern, dessen
Rechte (Planen, Ausbilden usw.) prüft die Web-App beim nächsten Seitenaufruf neu. Der stündliche Abgleich der
PHP-App läuft zur Sicherheit weiter.

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

Im Developer Portal muss unter "Bot" der Intent **Server Members** eingeschaltet sein ,
sonst startet der Bot nicht.

Der Bot muss mit den Scopes `bot` und `applications.commands` eingeladen sein. Fehlen die Befehle in Discord, obwohl
sie angemeldet sind, den Bot neu einladen (OAuth2 → URL Generator, beide Scopes, Rechte "Kanäle ansehen",
"Nachrichten senden", "Links einbetten", "Rollen verwalten" und "Events verwalten"):
`https://discord.com/oauth2/authorize?client_id=<AUTH_DISCORD_ID>&scope=bot+applications.commands&permissions=17910416788544`

Die Rechte darin: Kanäle ansehen, Nachrichten senden, Nachrichten in Threads senden, öffentliche Threads erstellen,
Links einbetten, Dateien anhängen, Nachrichtenverlauf lesen, Reaktionen hinzufügen, externe Emojis, @everyone und alle
Rollen erwähnen, Rollen verwalten, Spitznamen verwalten, Events erstellen und verwalten. Bewusst nicht: Administrator,
Kanäle oder Server verwalten. Für die Moderation kommen Kicken, Bannen, Timeout und Nachrichten verwalten dazu (siehe Abschnitt Moderation).

## Einrichten auf dem Server

1. Bot starten: `COMPOSE_PROFILES=bot` in die `.env`, dann `docker compose up -d`.
2. Befehle für alle Server anmelden: `docker compose exec bot node src/register.js --global`
   (nach Updates mit neuen Befehlen noch einmal).
3. Im Developer Portal unter "General Information" muss die "Interactions Endpoint URL" **leer** sein. Steht dort eine
   Adresse, schickt Discord die Befehle dorthin und nicht an den Bot.

## Befehle anmelden

- `npm run register` meldet die Befehle nur auf dem Server `BOT_DEV_GUILD_ID` an.
- `npm run register -- --global` meldet sie für alle Server an und **ersetzt dabei alle globalen Befehle der
  Anwendung**.
