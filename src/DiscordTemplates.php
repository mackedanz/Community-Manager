<?php

declare(strict_types=1);

namespace Hangar;

/**
 * Server-Vorlagen: Rollen, Kategorien und Kanäle (mit Sichtbarkeit und „nur lesen“) als JSON festhalten und auf einen Server anwenden.
 * Rollen werden in der Vorlage über ihren Namen angesprochen, damit sie auf jedem Server passt.
 *
 * Anwenden geht immer über einen Plan (plan()): was neu angelegt, geändert oder als überflüssig gelöscht würde. Gelöscht wird nur, was der
 * Nutzer in der Vorschau ausdrücklich ankreuzt; angelegt und geändert wird, was angekreuzt bleibt (apply()).
 *
 * Grenzen: Gespeichert werden Sichtbarkeit und „nur lesen“; weitere Spezialrechte einzelner Mitglieder, Bühnen- und Forenkanäle und die
 * Reihenfolge der Einträge nicht.
 */
final class DiscordTemplates
{
    public const VERSION = 1;
    public const MAX_ROLES = 50;
    public const MAX_CATEGORIES = 30;
    public const MAX_CHANNELS = 250;
    public const MAX_JSON = 200000;
    public const MAX_TEMPLATES = 30;

    private const TYPE_NAMES = [0 => 'text', 2 => 'voice', 5 => 'announce'];

    // ---------------------------------------------------------------- Format

    /**
     * Prüft eine Vorlage (z. B. aus einem Import) und bringt sie in die feste Form.
     * @return array{v:int,roles:list<array<string,mixed>>,categories:list<array<string,mixed>>,loose:list<array<string,mixed>>}
     * @throws OrgError
     */
    public static function normalize(mixed $raw): array
    {
        if (!is_array($raw) || ($raw['v'] ?? null) !== self::VERSION) {
            throw new OrgError('Das ist keine gültige Vorlage (Version fehlt oder passt nicht).');
        }
        $roles = [];
        $roleKeys = [];
        foreach (self::list($raw['roles'] ?? null) as $r) {
            $name = self::name($r['name'] ?? null, 'Rolle');
            $key = DiscordSetup::baseName($name);
            if ($key === 'everyone' || isset($roleKeys[$key])) {
                throw new OrgError("Die Rolle „{$name}“ kommt doppelt vor oder ist nicht erlaubt.");
            }
            $roleKeys[$key] = true;
            $bits = [];
            foreach (is_array($r['perms'] ?? null) ? $r['perms'] : [] as $bit) {
                if (!is_int($bit) || !isset(DiscordRoles::PERMISSIONS[$bit])) {
                    throw new OrgError("Unbekanntes Recht bei der Rolle „{$name}“.");
                }
                $bits[$bit] = $bit;
            }
            $color = $r['color'] ?? 0;
            if (!is_int($color) || $color < 0 || $color > 0xFFFFFF) {
                throw new OrgError("Ungültige Farbe bei der Rolle „{$name}“.");
            }
            $roles[] = ['name' => $name, 'color' => $color, 'hoist' => ($r['hoist'] ?? false) === true, 'mentionable' => ($r['mentionable'] ?? false) === true, 'perms' => array_values($bits)];
        }
        $categories = [];
        $catKeys = [];
        foreach (self::list($raw['categories'] ?? null) as $c) {
            $name = self::name($c['name'] ?? null, 'Kategorie');
            $key = DiscordSetup::baseName($name);
            if (isset($catKeys[$key])) {
                throw new OrgError("Die Kategorie „{$name}“ kommt doppelt vor.");
            }
            $catKeys[$key] = true;
            $categories[] = ['name' => $name, 'access' => self::access($c['access'] ?? null, false, $name), 'channels' => self::channels($c['channels'] ?? null, false)];
        }
        $loose = self::channels($raw['loose'] ?? null, true);
        $counts = self::counts(['roles' => $roles, 'categories' => $categories, 'loose' => $loose]);
        if ($counts['roles'] > self::MAX_ROLES || $counts['categories'] > self::MAX_CATEGORIES || $counts['channels'] > self::MAX_CHANNELS) {
            throw new OrgError('Die Vorlage ist zu groß (höchstens ' . self::MAX_ROLES . ' Rollen, ' . self::MAX_CATEGORIES . ' Kategorien, ' . self::MAX_CHANNELS . ' Kanäle).');
        }
        return ['v' => self::VERSION, 'roles' => $roles, 'categories' => $categories, 'loose' => $loose];
    }

    /** @return list<array<string,mixed>> */
    private static function list(mixed $v): array
    {
        if ($v === null) {
            return [];
        }
        if (!is_array($v)) {
            throw new OrgError('Die Vorlage ist fehlerhaft aufgebaut.');
        }
        foreach ($v as $row) {
            if (!is_array($row)) {
                throw new OrgError('Die Vorlage ist fehlerhaft aufgebaut.');
            }
        }
        return array_values($v);
    }

    private static function name(mixed $v, string $what): string
    {
        $n = is_string($v) ? trim($v) : '';
        if ($n === '' || mb_strlen($n) > DiscordSetup::MAX_NAME || DiscordSetup::baseName($n) === '') {
            throw new OrgError("Ein Name (" . $what . ") in der Vorlage ist leer, zu lang oder ungültig.");
        }
        return $n;
    }

    /** @return array{mode:string,roles:list<string>} @throws OrgError */
    private static function access(mixed $raw, bool $channel, string $name): array
    {
        $mode = is_array($raw) && is_string($raw['mode'] ?? null) ? $raw['mode'] : 'all';
        if (!in_array($mode, ['all', 'roles'], true)) {
            throw new OrgError("Ungültige Sichtbarkeit bei „{$name}“.");
        }
        $roles = [];
        foreach (is_array($raw) && is_array($raw['roles'] ?? null) ? $raw['roles'] : [] as $r) {
            $roles[DiscordSetup::baseName(self::name($r, 'Rolle'))] = trim((string) $r);
        }
        return ['mode' => $mode, 'roles' => $mode === 'roles' ? array_values($roles) : []];
    }

    /** @return list<array{name:string,type:string,topic:string,access:string,roles:list<string>,readonly:bool}> @throws OrgError */
    private static function channels(mixed $rows, bool $loose): array
    {
        $out = [];
        $keys = [];
        foreach (self::list($rows) as $c) {
            $name = self::name($c['name'] ?? null, 'Kanal');
            $type = is_string($c['type'] ?? null) && in_array($c['type'], self::TYPE_NAMES, true) ? $c['type'] : null;
            if ($type === null) {
                throw new OrgError("Unbekannte Kanalart bei „{$name}“.");
            }
            $key = ($type === 'voice' ? 'v:' : 't:') . DiscordSetup::baseName($name);
            if (isset($keys[$key])) {
                throw new OrgError("Der Kanal „{$name}“ kommt doppelt vor.");
            }
            $keys[$key] = true;
            $access = is_string($c['access'] ?? null) && in_array($c['access'], DiscordSetup::ACCESS, true) ? $c['access'] : ($loose ? 'all' : 'inherit');
            if ($loose && $access === 'inherit') {
                $access = 'all';
            }
            $roles = self::access(['mode' => $access === 'roles' ? 'roles' : 'all', 'roles' => $c['roles'] ?? []], true, $name)['roles'];
            $topic = $type === 'voice' || !is_string($c['topic'] ?? null) ? '' : trim($c['topic']);
            if (mb_strlen($topic) > DiscordSetup::MAX_TOPIC) {
                throw new OrgError("Das Thema von „{$name}“ ist zu lang.");
            }
            $out[] = ['name' => $name, 'type' => $type, 'topic' => $topic, 'access' => $access, 'roles' => $roles, 'readonly' => $type !== 'voice' && ($c['readonly'] ?? false) === true];
        }
        return $out;
    }

    /** @param array{roles:list<mixed>,categories:list<array<string,mixed>>,loose:list<mixed>} $tpl @return array{roles:int,categories:int,channels:int} */
    public static function counts(array $tpl): array
    {
        $channels = count($tpl['loose']);
        foreach ($tpl['categories'] as $c) {
            $channels += count($c['channels']);
        }
        return ['roles' => count($tpl['roles']), 'categories' => count($tpl['categories']), 'channels' => $channels];
    }

    // ------------------------------------------------------------- Mitgelieferte Vorlagen

    /**
     * Vorlagen, die immer da sind: Schlüssel => [name, description, template].
     * @return array<string,array{name:string,description:string,template:array<string,mixed>}>
     */
    public static function builtin(): array
    {
        $p = DiscordRoles::PRESETS;
        $role = static fn (string $name, int $color, array $perms, bool $hoist = true, bool $mention = false): array => ['name' => $name, 'color' => $color, 'hoist' => $hoist, 'mentionable' => $mention, 'perms' => $perms];
        $ch = static fn (string $name, string $type = 'text', string $topic = '', bool $ro = false, string $access = 'inherit', array $roles = []): array => ['name' => $name, 'type' => $type, 'topic' => $topic, 'access' => $access, 'roles' => $roles, 'readonly' => $ro];
        $members = ['Mitglied', 'Planer', 'Ausbilder', 'Moderator'];
        $visitors = ['Besucher', ...$members];
        $standard = [
            'v' => 1,
            'roles' => [
                $role('Moderator', 0x3498db, $p['Moderator']),
                $role('Ausbilder', 0x2ecc71, $p['Mitglied']),
                $role('Planer', 0xe67e22, $p['Mitglied']),
                $role('Mitglied', 0x1abc9c, $p['Mitglied']),
                $role('Besucher', 0x95a5a6, $p['Gast (lesen und zuhören)'], false),
            ],
            'categories' => [
                ['name' => 'Eingangshalle', 'access' => ['mode' => 'all', 'roles' => []], 'channels' => [
                    $ch('┋! -server-regeln-!', 'text', 'Bitte lesen und mit „Bestätigen“ zustimmen', true),
                    $ch('┋willkommen', 'text', 'Hier werden neue Mitglieder begrüßt', true),
                ]],
                ['name' => 'Intern', 'access' => ['mode' => 'roles', 'roles' => $members], 'channels' => [$ch('┋team'), $ch('┋abteilungen'), $ch('┋recruits')]],
                ['name' => 'Events', 'access' => ['mode' => 'roles', 'roles' => $members], 'channels' => [
                    $ch('┋operations-plan', 'text', 'Termine der Orga: hier zu- oder absagen und Plätze wählen', true), $ch('┋op-chat'),
                ]],
                ['name' => 'Star Citizen Info', 'access' => ['mode' => 'roles', 'roles' => $visitors], 'channels' => [
                    $ch('┋patchnotes'), $ch('┋sc-news'), $ch('┋youtube-news'), $ch('┋tips und tricks'), $ch('┋issue-council'), $ch('┋leaks'),
                ]],
                ['name' => 'Öffentlich', 'access' => ['mode' => 'roles', 'roles' => $visitors], 'channels' => [$ch('┋screenshots'), $ch('┋chat'), $ch('┋wer streamt gerade')]],
                ['name' => 'Öffentliche Sprachkanäle', 'access' => ['mode' => 'roles', 'roles' => $visitors], 'channels' => [$ch('┋Lobby', 'voice'), $ch('┋Gruppe 1', 'voice'), $ch('┋Gruppe 2', 'voice')]],
                ['name' => 'Gaming Area', 'access' => ['mode' => 'roles', 'roles' => $visitors], 'channels' => [$ch('┋Gaming 1', 'voice'), $ch('┋Gaming 2', 'voice')]],
                ['name' => 'Abteilungen', 'access' => ['mode' => 'roles', 'roles' => $members], 'channels' => []],
                ['name' => 'Administrative', 'access' => ['mode' => 'roles', 'roles' => []], 'channels' => [
                    $ch('┋mod-protokoll', 'text', 'Protokoll der Moderation (nur für Moderatoren)', true, 'roles', ['Moderator']),
                    $ch('┋bot-einstellungen', 'text', 'Einstellungen des Bots (nur für Admins)'),
                ]],
            ],
            'loose' => [],
        ];
        $community = [
            'v' => 1,
            'roles' => [
                $role('Moderator', 0x3498db, $p['Moderator']),
                $role('Mitglied', 0x1abc9c, $p['Mitglied']),
            ],
            'categories' => [
                ['name' => 'Willkommen', 'access' => ['mode' => 'all', 'roles' => []], 'channels' => [
                    $ch('┋regeln', 'text', 'Bitte lesen', true), $ch('┋ankündigungen', 'text', 'Neuigkeiten', true),
                ]],
                ['name' => 'Community', 'access' => ['mode' => 'all', 'roles' => []], 'channels' => [$ch('┋chat'), $ch('┋bilder'), $ch('┋videos')]],
                ['name' => 'Sprache', 'access' => ['mode' => 'all', 'roles' => []], 'channels' => [$ch('┋Lobby', 'voice'), $ch('┋Gruppe 1', 'voice'), $ch('┋Gruppe 2', 'voice')]],
                ['name' => 'Moderation', 'access' => ['mode' => 'roles', 'roles' => ['Moderator']], 'channels' => [$ch('┋mods'), $ch('┋protokoll', 'text', '', true)]],
            ],
            'loose' => [],
        ];
        return [
            'standard' => ['name' => 'Orga-Standard', 'description' => 'Die Struktur von /discord-setup: Eingangshalle, Intern, Events, öffentliche Bereiche, Abteilungen, Administrative, dazu die Rollen Mitglied, Planer, Ausbilder, Moderator und Besucher.', 'template' => self::normalize($standard)],
            'community' => ['name' => 'Kleine Community', 'description' => 'Einfacher Start: Willkommen, Community, Sprache und ein Moderationsbereich, mit den Rollen Mitglied und Moderator.', 'template' => self::normalize($community)],
        ];
    }

    // ------------------------------------------------------------- Vom Server aufnehmen

    /**
     * Hält den Aufbau eines Servers als Vorlage fest.
     * @param array{categories:list<array<string,mixed>>,loose:list<array<string,mixed>>} $structure
     * @param list<array{id:string,name:string,color:int,hoist:bool,mentionable:bool,managed:bool,position:int,permissions:int}> $roles
     * @return array{template:array<string,mixed>,skipped:int}
     */
    public static function capture(array $structure, array $roles, string $guildId): array
    {
        $names = [];
        $tplRoles = [];
        $mask = DiscordRoles::catalogMask();
        foreach ($roles as $r) {
            if ($r['position'] > 0 && !$r['managed']) {
                $names[$r['id']] = $r['name'];
                $bits = [];
                foreach (array_keys(DiscordRoles::PERMISSIONS) as $bit) {
                    if (($r['permissions'] & $mask & (1 << $bit)) !== 0) {
                        $bits[] = $bit;
                    }
                }
                $tplRoles[] = ['name' => $r['name'], 'color' => $r['color'], 'hoist' => $r['hoist'], 'mentionable' => $r['mentionable'], 'perms' => $bits];
            }
        }
        $skipped = 0;
        $convert = static function (array $rows, ?array $catMode) use ($names, $guildId, &$skipped): array {
            $out = [];
            foreach ($rows as $c) {
                $type = self::TYPE_NAMES[(int) $c['type']] ?? null;
                if ($type === null) {
                    $skipped++;
                    continue;
                }
                $mode = self::viewMode($c['overwrites'], $guildId, $names);
                $inherit = $catMode !== null && self::sameMode($mode, $catMode);
                $out[] = [
                    'name' => (string) $c['name'], 'type' => $type, 'topic' => $type === 'voice' ? '' : (string) ($c['topic'] ?? ''),
                    'access' => $inherit ? 'inherit' : $mode['mode'], 'roles' => $inherit || $mode['mode'] === 'all' ? [] : $mode['roles'],
                    'readonly' => $type !== 'voice' && self::readonly($c['overwrites'], $guildId),
                ];
            }
            return $out;
        };
        $categories = [];
        foreach ($structure['categories'] as $cat) {
            $mode = self::viewMode($cat['overwrites'], $guildId, $names);
            $categories[] = ['name' => (string) $cat['name'], 'access' => $mode, 'channels' => $convert($cat['channels'], $mode)];
        }
        $template = self::normalize(['v' => self::VERSION, 'roles' => $tplRoles, 'categories' => $categories, 'loose' => $convert($structure['loose'], null)]);
        return ['template' => $template, 'skipped' => $skipped];
    }

    /**
     * Wer sieht den Eintrag: alle, oder nur die Rollen (@everyone darf nicht ansehen, Rollen dürfen).
     * @param list<array{id:string,type:int,allow:string,deny:string}> $overwrites
     * @param array<string,string> $names Rollen-ID => Name (ohne Bot-Rollen)
     * @return array{mode:string,roles:list<string>}
     */
    private static function viewMode(array $overwrites, string $guildId, array $names): array
    {
        $denied = false;
        foreach ($overwrites as $o) {
            if ($o['id'] === $guildId && $o['type'] === 0) {
                $denied = ((int) $o['deny'] & DiscordSetup::VIEW) !== 0;
            }
        }
        if (!$denied) {
            return ['mode' => 'all', 'roles' => []];
        }
        $roles = [];
        foreach ($overwrites as $o) {
            if ($o['type'] === 0 && $o['id'] !== $guildId && ((int) $o['allow'] & DiscordSetup::VIEW) !== 0 && isset($names[$o['id']])) {
                $roles[] = $names[$o['id']];
            }
        }
        return ['mode' => 'roles', 'roles' => $roles];
    }

    /** @param list<array{id:string,type:int,allow:string,deny:string}> $overwrites */
    private static function readonly(array $overwrites, string $guildId): bool
    {
        foreach ($overwrites as $o) {
            if ($o['id'] === $guildId && $o['type'] === 0) {
                return ((int) $o['deny'] & DiscordSetup::SEND) !== 0;
            }
        }
        return false;
    }

    /** @param array{mode:string,roles:list<string>} $a @param array{mode:string,roles:list<string>} $b */
    private static function sameMode(array $a, array $b): bool
    {
        if ($a['mode'] !== $b['mode']) {
            return false;
        }
        $keys = static function (array $roles): array {
            $k = array_map([DiscordSetup::class, 'baseName'], $roles);
            sort($k);
            return array_values(array_unique($k));
        };
        return $a['mode'] === 'all' || $keys($a['roles']) === $keys($b['roles']);
    }

    /** @param array{mode:string,roles:list<string>} $a */
    private static function describe(array $a): string
    {
        return $a['mode'] === 'all' ? 'für alle sichtbar' : ($a['roles'] === [] ? 'nur Admins' : 'sichtbar für ' . implode(', ', $a['roles']));
    }

    // ------------------------------------------------------------------ Plan

    /**
     * Was müsste auf dem Server passieren, damit er der Vorlage entspricht? Jeder Eintrag hat einen Schlüssel (key), eine Beschreibung
     * (label), den Bereich (section), ob er vorgewählt ist (default) und ggf. locked (nicht wählbar, Grund) sowie die Angaben für apply().
     * @param array<string,mixed> $tpl normalisierte Vorlage
     * @param array{categories:list<array<string,mixed>>,loose:list<array<string,mixed>>} $structure
     * @param list<array{id:string,name:string,color:int,hoist:bool,mentionable:bool,managed:bool,position:int,permissions:int}> $roles
     * @param ?array{permissions:int,top:int} $bot
     * @param array<string,string> $used Kanal-ID => wofür der Bot ihn nutzt
     * @return array{items:list<array<string,mixed>>,warnings:list<string>}
     */
    public static function plan(array $tpl, array $structure, array $roles, ?array $bot, string $guildId, array $used = []): array
    {
        $items = [];
        $warnings = [];
        $add = static function (string $section, string $op, string $ident, string $label, array $data, bool $default = true, ?string $locked = null) use (&$items): void {
            $items[] = ['key' => substr(sha1($op . '|' . $ident), 0, 12), 'section' => $section, 'op' => $op, 'label' => $label, 'default' => $default && $locked === null, 'locked' => $locked] + $data;
        };
        $botPerms = $bot['permissions'] ?? null;
        $botTop = $bot['top'] ?? null;
        $mask = DiscordRoles::catalogMask();
        $names = [];
        $byKey = [];
        foreach ($roles as $r) {
            if ($r['position'] > 0) {
                $byKey[DiscordSetup::baseName($r['name'])] = $r;
                if (!$r['managed']) {
                    $names[$r['id']] = $r['name'];
                }
            }
        }
        $known = $byKey;

        // Rollen
        $tplKeys = [];
        foreach ($tpl['roles'] as $t) {
            $key = DiscordSetup::baseName($t['name']);
            $tplKeys[$key] = true;
            $want = array_reduce($t['perms'], static fn (int $m, int $b) => $m | (1 << $b), 0);
            if (!isset($byKey[$key])) {
                if ($botPerms !== null && $botPerms !== -1 && ($want & ~$botPerms) !== 0) {
                    $warnings[] = 'Rolle „' . $t['name'] . '“: dem Bot fehlen die Rechte „' . implode('“, „', DiscordRoles::labels($want & ~$botPerms)) . '“; sie werden weggelassen.';
                    $want &= $botPerms;
                }
                $add('Rollen', 'role_create', $key, 'Rolle „' . $t['name'] . '“ anlegen', ['role' => ['name' => $t['name'], 'color' => $t['color'], 'hoist' => $t['hoist'], 'mentionable' => $t['mentionable'], 'permissions' => $want]]);
                $known[$key] = ['id' => '', 'name' => $t['name']];
                continue;
            }
            $r = $byKey[$key];
            if (!DiscordRoles::editable($r, $botTop)) {
                continue;
            }
            $perms = ($r['permissions'] & ~$mask) | $want;
            if ($botPerms !== null && $botPerms !== -1) {
                $perms = ($perms & $botPerms) | ($r['permissions'] & ~$botPerms);
            }
            $new = ['name' => $t['name'], 'color' => $t['color'], 'hoist' => $t['hoist'], 'mentionable' => $t['mentionable'], 'permissions' => $perms];
            $titles = ['name' => 'Name', 'color' => 'Farbe', 'hoist' => 'Anzeige', 'mentionable' => 'erwähnbar', 'permissions' => 'Rechte'];
            $payload = [];
            $changed = [];
            foreach ($new as $f => $v) {
                if ($v !== $r[$f]) {
                    $payload[$f] = $f === 'permissions' ? (string) $v : $v;
                    $changed[] = $titles[$f];
                }
            }
            if ($payload !== []) {
                $add('Rollen', 'role_update', $r['id'], 'Rolle „' . $r['name'] . '“ ändern: ' . implode(', ', $changed), ['id' => $r['id'], 'payload' => $payload]);
            }
        }

        // Kategorien und Kanäle
        $matchedCat = [];
        $matchedCh = [];
        $catByKey = [];
        foreach ($structure['categories'] as $c) {
            $catByKey[DiscordSetup::baseName((string) $c['name'])] ??= $c;
        }
        $existingChannels = [];
        foreach ($structure['categories'] as $c) {
            foreach ($c['channels'] as $ch) {
                $existingChannels[] = $ch;
            }
        }
        foreach ($structure['loose'] as $ch) {
            $existingChannels[] = $ch;
        }
        $kindOf = static fn (int $type): ?string => $type === 2 ? 'v' : (in_array($type, [0, 5], true) ? 't' : null);
        $unknownRoles = static function (array $access) use ($known): array {
            return array_values(array_filter($access['roles'], static fn ($n) => !isset($known[DiscordSetup::baseName($n)])));
        };
        $planChannels = function (array $channels, ?string $catName, array $catAccess, ?array $catRow) use (&$matchedCh, $existingChannels, $kindOf, $unknownRoles, $names, $guildId, $add, &$warnings, $used): void {
            foreach ($channels as $t) {
                $kind = $t['type'] === 'voice' ? 'v' : 't';
                $key = DiscordSetup::baseName($t['name']);
                $missing = $unknownRoles(['roles' => $t['roles']]);
                if ($missing !== []) {
                    $warnings[] = 'Kanal „' . $t['name'] . '“ übersprungen: Die Rolle „' . $missing[0] . '“ gibt es nicht und die Vorlage legt sie nicht an.';
                    continue;
                }
                $found = null;
                foreach ($existingChannels as $e) {
                    if (isset($matchedCh[$e['id']]) || $kindOf((int) $e['type']) !== $kind || DiscordSetup::baseName((string) $e['name']) !== $key) {
                        continue;
                    }
                    if ($found === null || ($catRow !== null && $e['parent'] === (string) $catRow['id'])) {
                        $found = $e;
                    }
                }
                $desired = $t['access'] === 'inherit' ? $catAccess : ['mode' => $t['access'] === 'roles' ? 'roles' : 'all', 'roles' => $t['access'] === 'roles' ? $t['roles'] : []];
                $where = $catName === null ? '' : ' in „' . $catName . '“';
                $how = [$t['type'] === 'voice' ? 'Sprache' : ($t['type'] === 'announce' ? 'Ankündigung' : 'Text'), self::describe($desired)];
                if ($t['readonly']) {
                    $how[] = 'nur lesen';
                }
                if ($found === null) {
                    $add('Kategorien und Kanäle', 'ch_create', ($catName ?? '') . '/' . $kind . $key, 'Kanal „' . $t['name'] . '“' . $where . ' anlegen (' . implode(', ', $how) . ')', ['cat' => $catName, 'ch' => $t]);
                    continue;
                }
                $matchedCh[$found['id']] = true;
                $changes = [];
                $payload = ['id' => (string) $found['id'], 'cat' => $catName, 'ch' => $t, 'name' => false, 'topic' => false, 'move' => false, 'rights' => false];
                if ($t['name'] !== $found['name']) {
                    $payload['name'] = true;
                    $changes[] = 'Name';
                }
                if ($t['type'] !== 'voice' && $t['topic'] !== '' && $t['topic'] !== (string) ($found['topic'] ?? '')) {
                    $payload['topic'] = true;
                    $changes[] = 'Thema';
                }
                $parentWanted = $catRow === null ? ($catName === null ? null : 'neu') : (string) $catRow['id'];
                if (($found['parent'] ?? null) !== $parentWanted) {
                    $payload['move'] = true;
                    $changes[] = 'Kategorie (verschieben)';
                }
                $current = self::viewMode($found['overwrites'], $guildId, $names);
                $ro = $t['type'] !== 'voice' && $t['readonly'];
                if (!self::sameMode($current, $desired) || ($t['type'] !== 'voice' && self::readonly($found['overwrites'], $guildId) !== $ro)) {
                    $payload['rights'] = true;
                    $changes[] = 'Rechte';
                }
                if ($changes !== []) {
                    $add('Kategorien und Kanäle', 'ch_update', (string) $found['id'], 'Kanal „' . $found['name'] . '“ ändern: ' . implode(', ', $changes), $payload);
                }
            }
        };

        foreach ($tpl['categories'] as $t) {
            $missing = $unknownRoles($t['access']);
            if ($missing !== []) {
                $warnings[] = 'Kategorie „' . $t['name'] . '“ mit ihren Kanälen übersprungen: Die Rolle „' . $missing[0] . '“ gibt es nicht und die Vorlage legt sie nicht an.';
                continue;
            }
            $row = $catByKey[DiscordSetup::baseName($t['name'])] ?? null;
            if ($row === null) {
                $add('Kategorien und Kanäle', 'cat_create', DiscordSetup::baseName($t['name']), 'Kategorie „' . $t['name'] . '“ anlegen (' . self::describe($t['access']) . ')', ['name' => $t['name'], 'access' => $t['access']]);
            } else {
                $matchedCat[$row['id']] = true;
                $current = self::viewMode($row['overwrites'], $guildId, $names);
                $changes = [];
                $payload = ['id' => (string) $row['id'], 'name' => $t['name'] !== $row['name'] ? $t['name'] : null, 'access' => null];
                if ($payload['name'] !== null) {
                    $changes[] = 'Name';
                }
                if (!self::sameMode($current, $t['access'])) {
                    $payload['access'] = $t['access'];
                    $changes[] = 'Sichtbarkeit (' . self::describe($t['access']) . ')';
                }
                if ($changes !== []) {
                    $add('Kategorien und Kanäle', 'cat_update', (string) $row['id'], 'Kategorie „' . $row['name'] . '“ ändern: ' . implode(', ', $changes), $payload);
                }
            }
            $planChannels($t['channels'], $t['name'], $t['access'], $row);
        }
        $planChannels($tpl['loose'], null, ['mode' => 'all', 'roles' => []], null);

        // Überflüssiges: nur zur Auswahl, nie vorgewählt
        foreach ($existingChannels as $e) {
            if (!isset($matchedCh[$e['id']]) && $kindOf((int) $e['type']) !== null) {
                $parent = $e['parent'] === null ? '' : ' (Kategorie ' . (array_values(array_filter($structure['categories'], static fn ($c) => (string) $c['id'] === $e['parent']))[0]['name'] ?? '?') . ')';
                $add('Überflüssig (steht nicht in der Vorlage)', 'ch_delete', (string) $e['id'], 'Kanal „' . $e['name'] . '“' . $parent . ' löschen', ['id' => (string) $e['id']], false, isset($used[$e['id']]) ? 'Der Bot nutzt ihn für: ' . $used[$e['id']] : null);
            }
        }
        foreach ($structure['categories'] as $c) {
            if (!isset($matchedCat[$c['id']])) {
                $add('Überflüssig (steht nicht in der Vorlage)', 'cat_delete', (string) $c['id'], 'Kategorie „' . $c['name'] . '“ löschen (ihre Kanäle bleiben bestehen)', ['id' => (string) $c['id']], false);
            }
        }
        foreach ($roles as $r) {
            if ($r['position'] > 0 && !isset($tplKeys[DiscordSetup::baseName($r['name'])]) && DiscordRoles::editable($r, $botTop)) {
                $add('Überflüssig (steht nicht in der Vorlage)', 'role_delete', $r['id'], 'Rolle „' . $r['name'] . '“ löschen', ['id' => $r['id']], false);
            }
        }
        return ['items' => $items, 'warnings' => $warnings];
    }

    // ---------------------------------------------------------------- Anwenden

    /**
     * Führt die gewählten Einträge des Plans aus: erst Rollen, dann Kategorien, Kanäle, zuletzt Löschungen.
     * Bei einem Fehler bleibt das bis dahin Erledigte bestehen; die Meldung nennt es.
     * @param array{items:list<array<string,mixed>>,warnings:list<string>} $plan
     * @param list<string> $selected gewählte Schlüssel
     * @param array{categories:list<array<string,mixed>>,loose:list<array<string,mixed>>} $structure
     * @param list<array{id:string,name:string,color:int,hoist:bool,mentionable:bool,managed:bool,position:int,permissions:int}> $roles
     * @return array<string,int> Anzahl je Art
     * @throws OrgError
     */
    public static function apply(string $guildId, array $plan, array $selected, array $structure, array $roles): array
    {
        $botId = DiscordBot::selfId();
        $roleIds = [];
        foreach ($roles as $r) {
            if ($r['position'] > 0) {
                $roleIds[DiscordSetup::baseName($r['name'])] = $r['id'];
            }
        }
        $catIds = [];
        $catOver = [];
        foreach ($structure['categories'] as $c) {
            $catIds[DiscordSetup::baseName((string) $c['name'])] ??= (string) $c['id'];
            $catOver[(string) $c['id']] = $c['overwrites'];
        }
        $done = ['role_create' => 0, 'role_update' => 0, 'role_delete' => 0, 'cat_create' => 0, 'cat_update' => 0, 'cat_delete' => 0, 'ch_create' => 0, 'ch_update' => 0, 'ch_delete' => 0];
        // Erst Rollen, dann Kategorien und Kanäle, Löschungen zuletzt (Kanäle, Kategorien, Rollen)
        $order = ['role_create', 'role_update', 'cat_create', 'cat_update', 'ch_create', 'ch_update', 'ch_delete', 'cat_delete', 'role_delete'];
        $items = array_values(array_filter($plan['items'], static fn ($i) => in_array($i['key'], $selected, true) && $i['locked'] === null));
        usort($items, static fn ($a, $b) => array_search($a['op'], $order, true) <=> array_search($b['op'], $order, true));
        $access = static function (array $a) use (&$roleIds): array {
            $ids = [];
            foreach ($a['roles'] as $n) {
                $id = $roleIds[DiscordSetup::baseName($n)] ?? '';
                if ($id === '') {
                    throw new OrgError('Die Rolle „' . $n . '“ fehlt. Bitte sie in der Vorschau mit auswählen.');
                }
                $ids[] = $id;
            }
            return ['mode' => $a['mode'], 'roles' => $ids];
        };
        $parentOf = static function (?string $cat) use (&$catIds): ?string {
            if ($cat === null) {
                return null;
            }
            return $catIds[DiscordSetup::baseName($cat)] ?? throw new OrgError('Die Kategorie „' . $cat . '“ fehlt. Bitte sie in der Vorschau mit auswählen.');
        };
        $channelRights = static function (array $t, ?string $parent) use (&$catOver, $access, $guildId, $botId): array {
            $ch = ['type' => DiscordSetup::TYPES[$t['type']], 'access' => $t['access'], 'roles' => $access(['mode' => 'roles', 'roles' => $t['roles']])['roles'], 'readonly' => $t['readonly']];
            return DiscordSetup::channelOverwrites($ch, $parent !== null ? ($catOver[$parent] ?? []) : [], $guildId, $botId);
        };
        $current = null;
        try {
            foreach ($items as $it) {
                $current = $it;
                switch ($it['op']) {
                    case 'role_create':
                        $r = $it['role'];
                        $roleIds[DiscordSetup::baseName($r['name'])] = DiscordBot::createRole($guildId, [
                            'name' => $r['name'], 'permissions' => (string) $r['permissions'], 'color' => $r['color'], 'hoist' => $r['hoist'], 'mentionable' => $r['mentionable'],
                        ]);
                        break;
                    case 'role_update':
                        DiscordBot::updateRole($guildId, $it['id'], $it['payload']);
                        break;
                    case 'cat_create':
                        $ow = DiscordSetup::categoryOverwrites($access($it['access']), $guildId, $botId);
                        $payload = ['name' => $it['name'], 'type' => 4];
                        if ($ow !== []) {
                            $payload['permission_overwrites'] = $ow;
                        }
                        $id = DiscordBot::createChannel($guildId, $payload);
                        $catIds[DiscordSetup::baseName($it['name'])] = $id;
                        $catOver[$id] = $ow;
                        break;
                    case 'cat_update':
                        $payload = [];
                        if ($it['name'] !== null) {
                            $payload['name'] = $it['name'];
                        }
                        if ($it['access'] !== null) {
                            $payload['permission_overwrites'] = $catOver[$it['id']] = DiscordSetup::categoryOverwrites($access($it['access']), $guildId, $botId);
                        }
                        DiscordBot::updateChannel($it['id'], $payload);
                        break;
                    case 'ch_create':
                        $t = $it['ch'];
                        $parent = $parentOf($it['cat']);
                        $payload = ['name' => $t['name'], 'type' => DiscordSetup::TYPES[$t['type']]];
                        if ($parent !== null) {
                            $payload['parent_id'] = $parent;
                        }
                        if ($t['topic'] !== '') {
                            $payload['topic'] = $t['topic'];
                        }
                        $ow = $channelRights($t, $parent);
                        if ($ow !== []) {
                            $payload['permission_overwrites'] = $ow;
                        }
                        DiscordBot::createChannel($guildId, $payload);
                        break;
                    case 'ch_update':
                        $t = $it['ch'];
                        $parent = $parentOf($it['cat']);
                        $payload = [];
                        if ($it['name']) {
                            $payload['name'] = $t['name'];
                        }
                        if ($it['topic']) {
                            $payload['topic'] = $t['topic'];
                        }
                        if ($it['move']) {
                            $payload['parent_id'] = $parent;
                        }
                        if ($it['rights']) {
                            $payload['permission_overwrites'] = $channelRights($t, $parent);
                        }
                        DiscordBot::updateChannel($it['id'], $payload);
                        break;
                    case 'role_delete':
                        DiscordBot::deleteRole($guildId, $it['id']);
                        break;
                    default: // ch_delete, cat_delete
                        DiscordBot::deleteChannel($it['id']);
                }
                $done[$it['op']]++;
            }
        } catch (OrgError | DiscordAuthError | DiscordUnavailableError $e) {
            throw new OrgError(rtrim($e->getMessage(), '.') . '. Bis dahin erledigt: ' . self::summary($done) . ($current !== null ? ' (gestoppt bei: ' . $current['label'] . ')' : '') . '.');
        }
        return $done;
    }

    /** @param array<string,int> $done */
    public static function summary(array $done): string
    {
        $parts = [];
        foreach (['role' => 'Rollen', 'cat' => 'Kategorien', 'ch' => 'Kanäle'] as $k => $title) {
            $new = $done[$k . '_create'];
            $upd = $done[$k . '_update'];
            $del = $done[$k . '_delete'];
            if ($new + $upd + $del > 0) {
                $parts[] = $title . ' ' . implode(', ', array_filter([$new > 0 ? "$new neu" : '', $upd > 0 ? "$upd geändert" : '', $del > 0 ? "$del gelöscht" : '']));
            }
        }
        return $parts === [] ? 'nichts' : implode('; ', $parts);
    }
}
