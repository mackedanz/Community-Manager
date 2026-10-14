<?php

declare(strict_types=1);

namespace Hangar;

/**
 * Discord-Setup in der Web-App: Kategorien und Kanäle (Text, Sprache, Ankündigung) mit Rechten auf dem Server anlegen.
 * plan() prüft die Eingabe vollständig, bevor etwas angelegt wird; run() legt dann an. Es ändert nie, was schon da ist.
 * Rechte: Sichtbarkeit für alle oder nur für bestimmte Rollen, Kanäle wahlweise „nur lesen“ (der Bot und Admins schreiben).
 */
final class DiscordSetup
{
    public const VIEW = 1 << 10;
    public const SEND = 1 << 11;
    public const EMBED = 1 << 14;
    public const HISTORY = 1 << 16;
    public const CONNECT = 1 << 20;
    /** Schreiben im Kanal und in Threads: das nimmt „nur lesen“ weg. */
    public const WRITE = (1 << 11) | (1 << 35) | (1 << 36) | (1 << 38);

    public const MAX_BLOCKS = 10;
    public const MAX_PER_BLOCK = 30;
    public const MAX_TOTAL = 60;
    /** Mehr Kanäle fasst eine Kategorie in Discord nicht. */
    public const MAX_IN_CATEGORY = 50;
    public const MAX_NAME = 100;
    public const MAX_TOPIC = 1024;
    public const MAX_PREFIX = 3;
    public const DEFAULT_PREFIX = '┋';

    /** Kanalarten der Eingabe => Discord-Typ. */
    public const TYPES = ['text' => 0, 'voice' => 2, 'announce' => 5];
    public const ACCESS = ['inherit', 'all', 'roles'];

    /** Name ohne Zeichen wie „┋“ oder „!“ vorn und hinten, klein, Leerzeichen als „-“ (so vergleicht auch der Bot). */
    public static function baseName(string $name): string
    {
        $n = (string) preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', trim($name));
        return mb_strtolower((string) preg_replace('/\s+/u', '-', $n));
    }

    public static function prefix(mixed $raw): string
    {
        $p = is_string($raw) ? (string) preg_replace('/[\x00-\x1F\x7F]/u', '', trim($raw)) : self::DEFAULT_PREFIX;
        return mb_substr($p, 0, self::MAX_PREFIX);
    }

    /**
     * Prüft die Eingabe der Seite und macht daraus einen Plan.
     * @param array<string,mixed> $post
     * @param array{categories:list<array<string,mixed>>,loose:list<array<string,mixed>>} $structure Aufbau des Servers (DiscordBot::structure)
     * @param list<string> $roleIds Rollen, die es auf dem Server gibt
     * @return array{blocks:list<array<string,mixed>>,categories:int,channels:int}
     * @throws OrgError
     */
    public static function plan(array $post, array $structure, array $roleIds): array
    {
        $prefix = self::prefix($post['prefix'] ?? self::DEFAULT_PREFIX);
        $input = is_array($post['blocks'] ?? null) ? array_values($post['blocks']) : [];
        if (count($input) > self::MAX_BLOCKS) {
            throw new OrgError('Höchstens ' . self::MAX_BLOCKS . ' Kategorien auf einmal.');
        }
        $existing = [];
        $takenCategories = [];
        foreach ($structure['categories'] as $c) {
            $existing[(string) $c['id']] = $c;
            $takenCategories[self::baseName((string) $c['name'])] = true;
        }

        $blocks = [];
        $newCategories = 0;
        $total = 0;
        foreach ($input as $i => $b) {
            if (!is_array($b)) {
                continue;
            }
            $target = is_string($b['target'] ?? null) ? trim($b['target']) : '';
            $name = is_string($b['name'] ?? null) ? trim($b['name']) : '';
            $channels = self::channels($b['channels'] ?? null, $prefix, $roleIds);
            $category = null;
            $taken = [];
            if ($target === '') {
                if ($name === '') {
                    if ($channels === []) {
                        continue;
                    }
                    throw new OrgError('Eine neue Kategorie braucht einen Namen (Block ' . ($i + 1) . ').');
                }
                if (mb_strlen($name) > self::MAX_NAME) {
                    throw new OrgError('Der Name der Kategorie „' . mb_substr($name, 0, 20) . '…“ ist zu lang (höchstens ' . self::MAX_NAME . ' Zeichen).');
                }
                $key = self::baseName($name);
                if ($key === '' || isset($takenCategories[$key])) {
                    throw new OrgError("Die Kategorie „{$name}“ gibt es schon oder der Name ist ungültig. Wähle die vorhandene Kategorie unter „Kategorie“ aus.");
                }
                $takenCategories[$key] = true;
                $category = ['name' => $name, 'access' => self::categoryAccess($b, $roleIds, $name)];
                $newCategories++;
            } elseif ($target !== '-') {
                if (!isset($existing[$target])) {
                    throw new OrgError('Diese Kategorie gibt es auf dem Server nicht mehr. Bitte die Seite neu laden.');
                }
                if ($channels === []) {
                    continue;
                }
                foreach ($existing[$target]['channels'] as $c) {
                    $taken[self::channelKey((int) $c['type'], (string) $c['name'])] = true;
                }
                $category = ['id' => $target, 'overwrites' => $existing[$target]['overwrites'], 'name' => (string) $existing[$target]['name']];
            } else {
                foreach ($structure['loose'] as $c) {
                    $taken[self::channelKey((int) $c['type'], (string) $c['name'])] = true;
                }
            }
            foreach ($channels as $c) {
                $key = self::channelKey($c['type'], $c['name']);
                if (isset($taken[$key])) {
                    throw new OrgError("Den Kanal „{$c['name']}“ gibt es in dieser Kategorie schon.");
                }
                $taken[$key] = true;
            }
            if (count($taken) > self::MAX_IN_CATEGORY && $target !== '-') {
                throw new OrgError('Eine Kategorie fasst höchstens ' . self::MAX_IN_CATEGORY . ' Kanäle' . ($category !== null ? ' („' . $category['name'] . '“)' : '') . '.');
            }
            $total += count($channels);
            $blocks[] = ['category' => $category, 'channels' => $channels];
        }
        if ($blocks === []) {
            throw new OrgError('Es ist nichts zum Anlegen eingetragen.');
        }
        if ($total > self::MAX_TOTAL) {
            throw new OrgError('Höchstens ' . self::MAX_TOTAL . ' Kanäle auf einmal.');
        }
        return ['blocks' => $blocks, 'categories' => $newCategories, 'channels' => $total];
    }

    private static function channelKey(int $type, string $name): string
    {
        return ($type === 2 ? 'v:' : 't:') . self::baseName($name);
    }

    /**
     * @param list<string> $roleIds
     * @return list<array{name:string,type:int,topic:string,access:string,roles:list<string>,readonly:bool}>
     * @throws OrgError
     */
    private static function channels(mixed $rows, string $prefix, array $roleIds): array
    {
        $rows = is_array($rows) ? array_values($rows) : [];
        if (count($rows) > self::MAX_PER_BLOCK * 2) {
            throw new OrgError('Zu viele Kanäle in einer Kategorie.');
        }
        $out = [];
        foreach ($rows as $r) {
            $raw = is_array($r) && is_string($r['name'] ?? null) ? trim($r['name']) : '';
            if ($raw === '') {
                continue;
            }
            $type = self::TYPES[is_string($r['type'] ?? null) ? $r['type'] : 'text'] ?? null;
            if ($type === null) {
                throw new OrgError("Unbekannte Kanalart bei „{$raw}“.");
            }
            $name = $prefix !== '' && !str_starts_with($raw, $prefix) ? $prefix . $raw : $raw;
            if (self::baseName($name) === '' || mb_strlen($name) > self::MAX_NAME) {
                throw new OrgError("Der Kanalname „{$raw}“ ist ungültig oder zu lang (höchstens " . self::MAX_NAME . ' Zeichen).');
            }
            $topic = $type === 2 || !is_string($r['topic'] ?? null) ? '' : trim($r['topic']);
            if (mb_strlen($topic) > self::MAX_TOPIC) {
                throw new OrgError("Das Thema von „{$raw}“ ist zu lang (höchstens " . self::MAX_TOPIC . ' Zeichen).');
            }
            $access = is_string($r['access'] ?? null) && in_array($r['access'], self::ACCESS, true) ? $r['access'] : 'inherit';
            $roles = self::roles($r['roles'] ?? null, $roleIds);
            if ($access === 'roles' && $roles === []) {
                throw new OrgError("Bei „{$raw}“ ist „nur bestimmte Rollen“ gewählt, aber keine Rolle angekreuzt.");
            }
            $out[] = [
                'name' => $name, 'type' => $type, 'topic' => $topic, 'access' => $access, 'roles' => $access === 'roles' ? $roles : [],
                'readonly' => $type !== 2 && ($r['readonly'] ?? '') === '1',
            ];
        }
        if (count($out) > self::MAX_PER_BLOCK) {
            throw new OrgError('Höchstens ' . self::MAX_PER_BLOCK . ' Kanäle je Kategorie auf einmal.');
        }
        return $out;
    }

    /** @param list<string> $roleIds @return array{mode:string,roles:list<string>} @throws OrgError */
    private static function categoryAccess(array $b, array $roleIds, string $name): array
    {
        if (($b['access'] ?? '') !== 'roles') {
            return ['mode' => 'all', 'roles' => []];
        }
        $roles = self::roles($b['roles'] ?? null, $roleIds);
        if ($roles === []) {
            throw new OrgError("Bei der Kategorie „{$name}“ ist „nur bestimmte Rollen“ gewählt, aber keine Rolle angekreuzt.");
        }
        return ['mode' => 'roles', 'roles' => $roles];
    }

    /** @param list<string> $roleIds @return list<string> @throws OrgError */
    private static function roles(mixed $ids, array $roleIds): array
    {
        $out = [];
        foreach (is_array($ids) ? $ids : [] as $id) {
            $id = trim((string) $id);
            if (!in_array($id, $roleIds, true)) {
                throw new OrgError('Diese Rolle gibt es auf dem Server nicht (mehr). Bitte die Seite neu laden.');
            }
            if (!in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * Setzt Bits für einen Eintrag (Rolle oder Mitglied); was erlaubt wird, wird nicht mehr verboten und umgekehrt.
     * @param array<string,array{id:string,type:int,allow:int,deny:int}> $map
     */
    private static function bits(array &$map, string $id, int $type, int $allow, int $deny): void
    {
        $e = $map[$id] ?? ['id' => $id, 'type' => $type, 'allow' => 0, 'deny' => 0];
        $e['allow'] = ($e['allow'] | $allow) & ~$deny;
        $e['deny'] = ($e['deny'] | $deny) & ~$allow;
        $map[$id] = $e;
    }

    /**
     * @param array<string,array{id:string,type:int,allow:int,deny:int}> $map
     * @return list<array{id:string,type:int,allow:string,deny:string}>
     */
    private static function export(array $map): array
    {
        $out = [];
        foreach ($map as $e) {
            if ($e['allow'] !== 0 || $e['deny'] !== 0) {
                $out[] = ['id' => $e['id'], 'type' => $e['type'], 'allow' => (string) $e['allow'], 'deny' => (string) $e['deny']];
            }
        }
        return $out;
    }

    /**
     * Rechte einer neuen Kategorie: alle sehen sie (keine Einträge) oder nur die Rollen. Der Bot behält Zugriff.
     * @param array{mode:string,roles:list<string>} $access
     * @return list<array{id:string,type:int,allow:string,deny:string}>
     */
    public static function categoryOverwrites(array $access, string $guildId, ?string $botId): array
    {
        if ($access['mode'] !== 'roles') {
            return [];
        }
        $map = [];
        self::bits($map, $guildId, 0, 0, self::VIEW);
        foreach ($access['roles'] as $id) {
            self::bits($map, $id, 0, self::VIEW | self::HISTORY | self::CONNECT, 0);
        }
        if ($botId !== null) {
            self::bits($map, $botId, 1, self::VIEW | self::SEND | self::EMBED | self::HISTORY, 0);
        }
        return self::export($map);
    }

    /**
     * Rechte eines neuen Kanals. inherit = die der Kategorie, all = für alle sichtbar, roles = nur diese Rollen; readonly = niemand
     * außer dem Bot (und Admins) schreibt.
     * @param array{type:int,access:string,roles:list<string>,readonly:bool} $ch
     * @param list<array{id:string,type:int,allow:string,deny:string}> $base Rechte der Kategorie
     * @return list<array{id:string,type:int,allow:string,deny:string}>
     */
    public static function channelOverwrites(array $ch, array $base, string $guildId, ?string $botId): array
    {
        $voice = $ch['type'] === 2;
        $map = [];
        if ($ch['access'] === 'all') {
            self::bits($map, $guildId, 0, self::VIEW | self::HISTORY, 0);
        } elseif ($ch['access'] === 'roles') {
            self::bits($map, $guildId, 0, 0, self::VIEW);
            foreach ($ch['roles'] as $id) {
                self::bits($map, $id, 0, self::VIEW | self::HISTORY | ($voice ? self::CONNECT : 0), 0);
            }
        } else {
            foreach ($base as $o) {
                $map[$o['id']] = ['id' => $o['id'], 'type' => $o['type'], 'allow' => (int) $o['allow'], 'deny' => (int) $o['deny']];
            }
        }
        if ($ch['readonly'] && !$voice) {
            if (!isset($map[$guildId])) {
                self::bits($map, $guildId, 0, 0, 0);
            }
            foreach (array_keys($map) as $key) {
                $id = (string) $key; // ganzzahlige Schlüssel wie "20" macht PHP zu int
                if ($id !== $botId) {
                    self::bits($map, $id, $map[$key]['type'], 0, self::WRITE);
                }
            }
        }
        if ($botId !== null && !$voice && ($map !== [] || $ch['readonly'])) {
            self::bits($map, $botId, 1, self::VIEW | self::SEND | self::EMBED | self::HISTORY, 0);
        }
        return self::export($map);
    }

    /**
     * Legt den Plan auf dem Server an. Bei einem Fehler bleibt das bis dahin Angelegte bestehen; die Meldung nennt es.
     * @param array{blocks:list<array<string,mixed>>} $plan
     * @return array{categories:int,channels:int}
     * @throws OrgError
     */
    public static function run(string $guildId, array $plan): array
    {
        $botId = DiscordBot::selfId();
        $cats = 0;
        $channels = 0;
        try {
            foreach ($plan['blocks'] as $block) {
                $cat = $block['category'];
                $parent = null;
                $base = [];
                if ($cat !== null && isset($cat['id'])) {
                    $parent = (string) $cat['id'];
                    $base = $cat['overwrites'];
                } elseif ($cat !== null) {
                    $base = self::categoryOverwrites($cat['access'], $guildId, $botId);
                    $payload = ['name' => $cat['name'], 'type' => 4];
                    if ($base !== []) {
                        $payload['permission_overwrites'] = $base;
                    }
                    $parent = DiscordBot::createChannel($guildId, $payload);
                    $cats++;
                }
                foreach ($block['channels'] as $c) {
                    $payload = ['name' => $c['name'], 'type' => $c['type']];
                    if ($parent !== null) {
                        $payload['parent_id'] = $parent;
                    }
                    if ($c['topic'] !== '') {
                        $payload['topic'] = $c['topic'];
                    }
                    $overwrites = self::channelOverwrites($c, $base, $guildId, $botId);
                    if ($overwrites !== []) {
                        $payload['permission_overwrites'] = $overwrites;
                    }
                    DiscordBot::createChannel($guildId, $payload);
                    $channels++;
                }
            }
        } catch (DiscordAuthError | DiscordUnavailableError $e) {
            throw new OrgError(rtrim($e->getMessage(), '.') . ". Bis dahin angelegt: $cats Kategorien, $channels Kanäle.");
        }
        return ['categories' => $cats, 'channels' => $channels];
    }
}
