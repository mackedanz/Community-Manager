<?php

declare(strict_types=1);

namespace Hangar;

/**
 * Rollen und Rechte in der Web-App: neue Rollen mit Rechten auf dem Discord-Server anlegen. Zur Auswahl steht ein fester Katalog;
 * gefährliche Rechte (Administrator, Server verwalten, Rollen verwalten, Webhooks) fehlen mit Absicht. Der Bot kann nur Rechte
 * vergeben, die er selbst hat; plan() lehnt andere ab, bevor etwas angelegt wird. Vorhandene Rollen werden nie geändert.
 */
final class DiscordRoles
{
    public const MAX_ROLES = 10;
    public const MAX_NAME = 100;

    /** Rechte zur Auswahl: Bit => [Titel, Gruppe]. Bits laut Discord-Dokumentation. */
    public const PERMISSIONS = [
        10 => ['Kanäle ansehen', 'Allgemein'],
        0 => ['Einladungen erstellen', 'Allgemein'],
        26 => ['Eigenen Nickname ändern', 'Allgemein'],
        27 => ['Nicknames verwalten', 'Allgemein'],
        11 => ['Nachrichten senden', 'Text'],
        38 => ['In Threads schreiben', 'Text'],
        35 => ['Öffentliche Threads erstellen', 'Text'],
        14 => ['Links einbetten', 'Text'],
        15 => ['Dateien anhängen', 'Text'],
        6 => ['Reaktionen hinzufügen', 'Text'],
        18 => ['Externe Emojis nutzen', 'Text'],
        16 => ['Nachrichtenverlauf lesen', 'Text'],
        17 => ['@everyone und @here erwähnen', 'Text'],
        13 => ['Nachrichten verwalten', 'Text'],
        34 => ['Threads verwalten', 'Text'],
        20 => ['Sprachkanäle betreten', 'Sprache'],
        21 => ['Sprechen', 'Sprache'],
        9 => ['Video und Bildschirm teilen', 'Sprache'],
        25 => ['Sprachaktivierung', 'Sprache'],
        22 => ['Mitglieder stummschalten', 'Sprache'],
        23 => ['Mitglieder taub schalten', 'Sprache'],
        24 => ['Mitglieder verschieben', 'Sprache'],
        44 => ['Events erstellen', 'Events'],
        33 => ['Events verwalten', 'Events'],
        1 => ['Mitglieder kicken', 'Moderation'],
        2 => ['Mitglieder bannen', 'Moderation'],
        40 => ['Mitglieder timeouten', 'Moderation'],
        7 => ['Audit-Log ansehen', 'Moderation'],
        4 => ['Kanäle verwalten', 'Moderation'],
    ];

    /** Vorlagen für die Schnellauswahl: Titel => Bits. */
    public const PRESETS = [
        'Gast (lesen und zuhören)' => [10, 16, 20],
        'Mitglied' => [10, 16, 20, 11, 38, 35, 14, 15, 6, 18, 21, 9, 25, 26],
        'Moderator' => [10, 16, 20, 11, 38, 35, 14, 15, 6, 18, 21, 9, 25, 26, 13, 34, 27, 22, 23, 24, 1, 2, 40, 7],
    ];

    /** Titel der Rechte, die in der Zahl gesetzt sind (nur die aus dem Katalog; Administrator allein). @return list<string> */
    public static function labels(int $permissions): array
    {
        if (($permissions & (1 << 3)) !== 0) {
            return ['Administrator'];
        }
        $out = [];
        foreach (self::PERMISSIONS as $bit => [$title]) {
            if (($permissions & (1 << $bit)) !== 0) {
                $out[] = $title;
            }
        }
        return $out;
    }

    /** "#aabbcc" => Zahl; leer => 0 (keine Farbe). @throws OrgError */
    public static function color(mixed $raw): int
    {
        $c = is_string($raw) ? trim($raw) : '';
        if ($c === '') {
            return 0;
        }
        if (preg_match('/^#([0-9a-fA-F]{6})$/', $c, $m) !== 1) {
            throw new OrgError("„{$c}“ ist keine Farbe (Beispiel: #3366ff).");
        }
        return (int) hexdec($m[1]);
    }

    /**
     * Prüft die Eingabe der Seite und macht daraus einen Plan.
     * @param array<string,mixed> $post
     * @param list<array{id:string,name:string}> $existing vorhandene Rollen (auch @everyone)
     * @param ?int $botPermissions Rechte des Bots (-1 = alle, null = unbekannt, dann entscheidet Discord)
     * @return list<array{name:string,color:int,hoist:bool,mentionable:bool,permissions:int}>
     * @throws OrgError
     */
    public static function plan(array $post, array $existing, ?int $botPermissions): array
    {
        $rows = is_array($post['roles'] ?? null) ? array_values($post['roles']) : [];
        if (count($rows) > self::MAX_ROLES * 2) {
            throw new OrgError('Zu viele Rollen auf einmal.');
        }
        $taken = ['everyone' => true];
        foreach ($existing as $r) {
            $taken[DiscordSetup::baseName($r['name'])] = true;
        }
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $name = is_string($r['name'] ?? null) ? trim($r['name']) : '';
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > self::MAX_NAME) {
                throw new OrgError('Der Rollenname „' . mb_substr($name, 0, 20) . '…“ ist zu lang (höchstens ' . self::MAX_NAME . ' Zeichen).');
            }
            $key = DiscordSetup::baseName($name);
            if ($key === '' || isset($taken[$key])) {
                throw new OrgError("Die Rolle „{$name}“ gibt es schon oder der Name ist ungültig.");
            }
            $taken[$key] = true;
            $bits = 0;
            foreach (is_array($r['perms'] ?? null) ? $r['perms'] : [] as $p) {
                $bit = filter_var($p, FILTER_VALIDATE_INT);
                if ($bit === false || !isset(self::PERMISSIONS[$bit])) {
                    throw new OrgError("Unbekanntes Recht bei der Rolle „{$name}“.");
                }
                $bits |= 1 << $bit;
            }
            if ($botPermissions !== null) {
                $missing = $bits & ~$botPermissions;
                if ($missing !== 0) {
                    throw new OrgError("Der Bot besitzt „" . implode('“, „', self::labels($missing)) . "“ selbst nicht und kann es nicht vergeben (Rolle „{$name}“). Dann diese Rechte abwählen oder dem Bot geben.");
                }
            }
            $out[] = [
                'name' => $name, 'color' => self::color($r['color'] ?? ''), 'permissions' => $bits,
                'hoist' => ($r['hoist'] ?? '') === '1', 'mentionable' => ($r['mentionable'] ?? '') === '1',
            ];
        }
        if ($out === []) {
            throw new OrgError('Es ist keine Rolle eingetragen.');
        }
        if (count($out) > self::MAX_ROLES) {
            throw new OrgError('Höchstens ' . self::MAX_ROLES . ' Rollen auf einmal.');
        }
        return $out;
    }

    /**
     * Legt die Rollen an. Bei einem Fehler bleibt das bis dahin Angelegte bestehen; die Meldung nennt die Zahl.
     * @param list<array{name:string,color:int,hoist:bool,mentionable:bool,permissions:int}> $plan
     * @throws OrgError
     */
    public static function run(string $guildId, array $plan): int
    {
        $done = 0;
        try {
            foreach ($plan as $r) {
                DiscordBot::createRole($guildId, [
                    'name' => $r['name'], 'permissions' => (string) $r['permissions'], 'color' => $r['color'],
                    'hoist' => $r['hoist'], 'mentionable' => $r['mentionable'],
                ]);
                $done++;
            }
        } catch (DiscordAuthError | DiscordUnavailableError $e) {
            throw new OrgError(rtrim($e->getMessage(), '.') . ". Bis dahin angelegt: $done Rollen.");
        }
        return $done;
    }
}
