# Community-Hangar

Inoffizielle Community-Web-App für **Star Citizen**: Jedes Mitglied überträgt seinen Hangar von der RSI-Webseite, die
Orga sieht gemeinsam, welche Schiffe und Rüstungen vorhanden sind, und plant damit Einsätze. Anmeldung und
Mitgliedschaft laufen über **Discord**, die Einrichtung einer Orga über einen kleinen Discord-Bot.
Technik: PHP 8.3 ohne Framework, MariaDB, Docker.

**Installation Schritt für Schritt, mit Bildern:** [docs/Community-Hangar-Installation.pdf](docs/Community-Hangar-Installation.pdf)
(dieselbe Anleitung als Webseite: [docs/anleitung/installation.html](docs/anleitung/installation.html)).
Sie führt vom Discord Developer Portal über den Server und den Reverse-Proxy bis zum ersten RSI-Sync, in etwa 45 Minuten.

## Was die App kann

- **Mein Hangar:** Schiffe, Rüstungen, Upgrades und Ausrüstung, per RSI-Sync übernommen, dazu manuell Ergänzbares.
- **RSI-Sync per Lesezeichen:** Ein Lesezeichen liest die Pledge-Seiten im eigenen, angemeldeten Browser. Die App zeigt eine
  Vorschau, übernommen wird erst nach Bestätigung. Keine Browser-Erweiterung, keine RSI-Zugangsdaten.
- **Orga:** Orga Hangar (anonym gezählt, filterbar), Mitglieder und Profile, Statistik, Aktivitäten, Errungenschaften.
- **Planung:** Einsätze mit Schiffen aus dem Orga Hangar, Besatzungsplätzen, Zu- und Absagen und einem Briefing für Discord.
- **Katalog:** alle Schiffe der RSI Ship Matrix mit Daten und Bildern, nur für angemeldete Nutzer.
- **Mehrere Orgas** auf einer Instanz, voneinander getrennt. Namen zeigen den Nickname auf dem Discord-Server der Orga.

Datenquellen: Schiffskatalog und Schiffsbilder von **RSI** (Ship Matrix und Store-Seite), Ergänzungen von FleetYards,
Rüstungen aus der Star Citizen Wiki.

## Betrieb (Kurzfassung)

Die vollständige Anleitung mit allen Discord-Einstellungen steht im PDF oben. In Kurzform:

```bash
curl -O https://raw.githubusercontent.com/mackedanz/community-hangar-1/main/docker-compose.yml
curl -o .env https://raw.githubusercontent.com/mackedanz/community-hangar-1/main/.env.docker.example
nano .env                         # DOMAIN, AUTH_DISCORD_ID, AUTH_DISCORD_SECRET, DB_PASSWORD, APP_KEY (openssl rand -base64 32), Bot-Werte
docker compose up -d
docker compose exec app php bin/register-commands.php
```

Davor muss ein Reverse-Proxy HTTPS übernehmen (Port 8181, `HANGAR_PORT`). Der Container wartet auf die Datenbank,
spielt die Migrationen ein und gleicht den Katalog alle 2 Stunden ab (`CATALOG_SYNC_INTERVAL_SECONDS`; findet ein RSI-Import Schiffe, die der Katalog noch nicht kennt, werden die Schiffe sofort aktualisiert). Schiffsbilder werden beim
ersten Aufruf eines Schiffs von RSI geladen (erstes Bild der Store-Seite) und im Volume `ship-images` abgelegt (`/img/ship/{slug}`).

| Aufgabe | Befehl |
|---|---|
| Update | `docker compose pull && docker compose up -d` |
| Logs | `docker compose logs -f app` |
| Katalog sofort abgleichen | `docker compose exec app php bin/catalog-sync.php` |
| Zugangslisten sofort abgleichen | `docker compose exec app php bin/sync-allowlist.php` |
| Konten ohne Zugangsliste löschen (Vorschau, mit `--yes` wirklich) | `docker compose exec app php bin/purge-former-members.php` |
| Schiffsbilder vorab laden | `docker compose exec app php bin/warm-images.php` |
| Datenbank sichern | `docker compose exec db sh -c 'mariadb-dump -u hangar -p"$MARIADB_PASSWORD" hangar' > sicherung.sql` |

**Datenbank ansehen:** Sie ist von außen nicht erreichbar. Auf dem Server:
`docker compose exec db sh -c 'mariadb -u hangar -p"$MARIADB_PASSWORD" hangar'`. Für ein Programm wie HeidiSQL legst du neben der
`docker-compose.yml` eine `docker-compose.override.yml` mit `services: db: ports: ["<interne Server-IP>:3307:3306"]` an
(nur im internen Netz, nie ins Internet).

### Übernahme der Daten aus der alten Version (Next.js + SQLite)

```bash
docker compose exec app php bin/catalog-sync.php                      # Katalog zuerst aufbauen
docker cp hangar.db <container>:/tmp/hangar.db                         # KOPIE der alten SQLite-Datei
docker compose exec app php bin/migrate-from-sqlite.php /tmp/hangar.db --dry-run   # nur prüfen
docker compose exec app php bin/migrate-from-sqlite.php /tmp/hangar.db             # übernehmen
```

Sitzungen werden nicht übernommen (alle melden sich einmal neu an), Hangar-Einträge werden über den Namen mit dem neuen
Katalog verknüpft. Schiffe, die es in der Ship Matrix nicht gibt, bleiben als freier Name erhalten. E-Mail-Adressen
aus der alten Datenbank werden nicht übernommen.

## Onboarding-Bot

Der Bot ist ein Discord-„Interactions Endpoint“ (`POST /discord/interactions`, Ed25519-signiert, kein Dauerprozess) und
braucht keine Rechte auf dem Server (`permissions=0`).

| Befehl | Wer darf | Was er tut |
|---|---|---|
| `/einrichten` | Server-Admins | legt die Orga an, zwei Rollenauswahlen (nutzen / planen, je bis 10), gleicht die Mitglieder ab |
| `/abgleichen` | Mitglieder mit Nutzungs-Rolle, Server-Admins | gleicht sofort ab (nie eine neue Orga), mindestens 30 Sekunden Abstand |

1. Developer Portal → eure Anwendung: *Öffentlicher Schlüssel* → `DISCORD_PUBLIC_KEY`, *Bot → Token* → `DISCORD_BOT_TOKEN`,
   *Installation → Installations-Link* auf „Keine“, *Server Members Intent* einschalten, *Öffentlicher Bot* ausschalten,
   *Interaktions-Endpunkt-URL* = `https://<DOMAIN>/discord/interactions`.
2. Bot mit Scope `applications.commands` + `bot` auf den Server einladen, dann `php bin/register-commands.php`.
3. Der Abgleich schreibt Discord-ID, Name und Avatar aller Rolleninhaber in `org_allowed_members`, danach stündlich
   (`bin/sync-allowlist.php`), per Knopf in den Orga-Einstellungen und per `/abgleichen`.
4. `LOGIN_REQUIRES_ALLOWLIST=1`: Nur wer auf einer Zugangsliste steht (oder `SERVER_ADMIN_DISCORD_ID` ist), kann sich anmelden.
   Wer beim Abgleich auf keiner Liste mehr steht, verliert die laufenden Sitzungen und sein Konto samt Hangar wird gelöscht
   (nicht: Server-Admins, fest eingetragene Personen, eine leere Mitgliederliste von Discord). **Sicherheitsbremse:** Fallen auf
   einmal mindestens 5 Personen und mehr als die Hälfte der Liste weg, wird nichts gelöscht; nach Prüfung löscht
   `bin/purge-former-members.php --yes`. Erst einschalten, wenn die Liste gefüllt ist.
5. `ONBOARDING_GUILD_IDS`: nur diese Discord-Server dürfen per `/einrichten` eine Orga anlegen. Immer setzen.

Lokal lässt sich der Endpunkt nur mit einem Tunnel von Discord aus erreichen; die Logik ist mit gefälschten Interaktionen
getestet (`tests/OnboardingTest.php`).

## Lizenz

Community-Hangar ist urheberrechtlich geschützt und wird nur an freigeschaltete Betreiber weitergegeben. Die
Nutzungsbedingungen stehen in [LIZENZ.md](LIZENZ.md) (in der App unter `/lizenz`), eine kurze englische Fassung in
[LICENSE.txt](LICENSE.txt). Die Kontaktangabe ist dort noch ein Platzhalter.

## Datenschutz und Sicherheit

Gespeichert werden die Discord-ID, der Anzeigename, der Avatar und der Nickname auf dem Server der Orga, der RSI-Handle
und der Besitz. Die **E-Mail-Adresse wird nicht abgefragt und nicht gespeichert.** Konto und Daten lassen sich in den
Einstellungen löschen.

- Anmeldung über Discord (OAuth2 Code-Flow mit `state`), Sitzungs-Cookie `HttpOnly`/`SameSite=Lax`, in der Datenbank nur der Hash.
- Alle POST-Formulare tragen ein CSRF-Token; die Import-API akzeptiert nur Bearer-Token oder Sitzung mit `application/json`.
- Nicht-Mitglieder bekommen für Orga-Seiten 404, `/admin` ist für alle außer `SERVER_ADMIN_DISCORD_ID` ein 404.
- Der Orga-Hangar ist anonym (keine Nutzer-IDs/Namen in der Abfrage).
- Bot-Anfragen werden per Ed25519-Signatur geprüft; Anfragen, die älter als 5 Minuten sind, werden abgelehnt.
- Bilder werden nur von einer festen Host-Liste (RSI-Bildspeicher) geladen, geprüft (Typ, Größe) und atomar gespeichert.

## Aufbau

| Pfad | Inhalt |
|---|---|
| `public/index.php` | Front-Controller, `public/js/` Skripte (Vanilla), `public/css/app.css` (gebaut) |
| `src/` | Logik (`Hangar\…`): `Orgs`, `Discord`, `Onboarding`, `Auth`, `Hangar`, `Community`, `Events`, `Catalog\*`, `Import\*`, … |
| `src/Controllers/` + `views/` | Seiten (PHP-Templates, immer mit `e()` escapen) |
| `src/App.php` | alle Routen |
| `migrations/` | nummerierte SQL-Migrationen (`bin/migrate.php`) |
| `bin/` | `migrate.php`, `catalog-sync.php`, `enrich-items.php`, `warm-images.php`, `register-commands.php`, `sync-allowlist.php`, `purge-former-members.php`, `migrate-from-sqlite.php` |
| `tests/` | PHPUnit (Datenbank `hangar_test`, nie die Entwicklungsdatenbank) |
| `bookmarklet/rsi-sync.js` | Quelle des RSI-Lesezeichens |
| `docs/` | Installationsanleitung (PDF und HTML mit Bildern) |
| `Dockerfile`, `docker-compose.yml`, `docker/` | Betrieb (Apache + MariaDB) |

## Lokal entwickeln (Windows, ohne Installation)

Die portable Umgebung liegt in `tools/` (PHP, Composer, MariaDB; nicht im Repository).

```powershell
powershell -ExecutionPolicy Bypass -File start-db.ps1   # MariaDB auf Port 3307 starten
powershell -ExecutionPolicy Bypass -File dev.ps1        # Migrationen + Server auf http://localhost:8080
powershell -ExecutionPolicy Bypass -File test.ps1       # PHPUnit
node build-css.mjs                                       # CSS neu bauen (nach Änderungen an views/); vorher einmal: npm install
tools\php\php.exe bin\catalog-sync.php                   # Katalog laden (Ship Matrix, FleetYards, Wiki)
```

Lokale Einstellungen stehen in `.env` (siehe `.env.docker.example`). Mit `APP_ENV=local` gibt es unter
`/dev/login` eine Anmeldung ohne Discord für Tests (nur über localhost; in Produktion nicht gesetzt).

Hinweis zu Netzlaufwerken: Auf einem SMB-Laufwerk meldet PHP neue Dateien als „nicht lesbar“, und InnoDB ist dort
sehr langsam. `test.ps1` setzt die Leserechte, die MariaDB-Daten liegen deshalb auf `C:`. Der PHP-Entwicklungsserver
bearbeitet nur eine Anfrage gleichzeitig und kann unter Windows hängen bleiben; ein Neustart (`dev.ps1`) behebt das.
