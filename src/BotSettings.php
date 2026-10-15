<?php

declare(strict_types=1);

namespace Hangar;

/**
 * Einstellungen des Discord-Bots (BOT/) je Orga, Tabelle bot_settings (Migration 020). Der Bot liest sie bei jeder
 * Verwendung neu (die Automod nach höchstens 30 Sekunden), Änderungen aus der Web-App wirken also ohne Neustart.
 * Namen, Grenzen und Prüfregeln entsprechen BOT/src/lib/{settings,welcome,moderation,automod,serversetup}.js.
 */
final class BotSettings
{
    public const ANNOUNCE_CHANNEL = 'announce_channel_id';
    public const ADMIN_CHANNEL = 'admin_channel_id';
    public const VISITOR_ROLE = 'visitor_role_id';
    public const WELCOME_CHANNEL = 'welcome_channel_id';
    public const WELCOME_TEXT = 'welcome_text';
    public const RULES_CHANNEL = 'rules_channel_id';
    public const MOD_ROLES = 'mod_role_ids';
    public const MOD_LOG_CHANNEL = 'mod_log_channel_id';
    public const AUTOMOD = 'automod';
    /** Vom Bot beim Start geschrieben: '1' = läuft mit Message Content (Automod und Nachrichten-Protokoll aktiv). */
    public const MESSAGE_CONTENT_STATUS = 'message_content_active';
    /** Vom Bot beim Start geschrieben: '1' = Twitch-Zugangsdaten sind eingetragen, Live-Meldungen laufen. */
    public const TWITCH_STATUS = 'twitch_active';

    public const MAX_WELCOME_TEXT = 500;
    public const MAX_MOD_ROLES = 10;
    public const MAX_WORDS = 50;
    public const MAX_DOMAINS = 30;
    public const MAX_EXEMPT_CHANNELS = 10;
    public const MAX_MENTIONS = 50;
    public const DEFAULT_WELCOME = 'Willkommen {user} auf **{server}**! Lies bitte {regeln} und bestätige sie, dann bist du dabei.';

    /** Bereiche der Einstellungsseite: Schlüssel => Titel. */
    public const SECTIONS = [
        'allgemein' => 'Allgemein',
        'termine' => 'Termine',
        'willkommen' => 'Willkommen und Regeln',
        'moderation' => 'Moderation',
        'automod' => 'Automod',
        'creator' => 'Creator',
        'setup' => 'Discord-Setup',
        'rollen' => 'Rollen und Rechte',
    ];

    /** Unterseiten ohne eigenen Menüpunkt: Schlüssel => Bereich, unter dem sie im Menü stehen. */
    public const SUBSECTIONS = [
        'vorlagen' => 'setup',
        'zuordnung' => 'setup',
        'faelle' => 'moderation',
        'regeln' => 'willkommen',
        'rechte' => 'setup',
    ];

    /** Alle Einstellungen der Orga. @return array<string,string> */
    public static function all(string $orgId): array
    {
        $out = [];
        foreach (Db::all('SELECT name, value FROM bot_settings WHERE org_id = ?', [$orgId]) as $r) {
            $out[(string) $r['name']] = (string) $r['value'];
        }
        return $out;
    }

    /** Setzt eine Einstellung; null oder '' entfernt sie. */
    public static function set(string $orgId, string $name, ?string $value): void
    {
        if ($value === null || $value === '') {
            Db::run('DELETE FROM bot_settings WHERE org_id = ? AND name = ?', [$orgId, $name]);
            return;
        }
        Db::run('INSERT INTO bot_settings (org_id, name, value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$orgId, $name, $value]);
    }

    /**
     * Automod-Einstellungen (fehlende oder kaputte Werte fallen auf „aus“ zurück, wie loadConfig im Bot).
     * @param array<string,string> $all
     * @return array{spam:bool,invites:bool,links:bool,mentions:int,words:list<string>,domains:list<string>,exemptChannels:list<string>}
     */
    public static function automod(array $all): array
    {
        $s = json_decode($all[self::AUTOMOD] ?? '{}', true);
        $s = is_array($s) ? $s : [];
        $list = static fn ($v): array => is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
        return [
            'spam' => ($s['spam'] ?? false) === true,
            'invites' => ($s['invites'] ?? false) === true,
            'links' => ($s['links'] ?? false) === true,
            'mentions' => is_int($s['mentions'] ?? null) && $s['mentions'] >= 0 ? $s['mentions'] : 0,
            'words' => $list($s['words'] ?? null),
            'domains' => $list($s['domains'] ?? null),
            'exemptChannels' => $list($s['exemptChannels'] ?? null),
        ];
    }

    /** Ist irgendeine Automod-Regel eingeschaltet? @param array<string,mixed> $cfg */
    public static function automodActive(array $cfg): bool
    {
        return $cfg['spam'] || $cfg['invites'] || $cfg['links'] || $cfg['mentions'] > 0 || $cfg['words'] !== [];
    }

    /**
     * Welche Bereiche sind eingerichtet (für die Punkte in der Navigation und die Kachel)? „allgemein“ zählt nicht mit.
     * @param array<string,string> $all @return array<string,bool>
     */
    public static function status(array $all): array
    {
        return [
            'termine' => isset($all[self::ANNOUNCE_CHANNEL]),
            'willkommen' => isset($all[self::WELCOME_CHANNEL]),
            'moderation' => isset($all[self::MOD_ROLES]) || isset($all[self::MOD_LOG_CHANNEL]),
            'automod' => self::automodActive(self::automod($all)),
        ];
    }

    /** Hat der Bot beim letzten Start gemeldet, dass er Nachrichten lesen darf? Ohne Meldung (alter Bot, noch nicht gestartet) gilt: nein. @param array<string,string> $all */
    public static function twitchActive(array $all): bool
    {
        return ($all[self::TWITCH_STATUS] ?? '') === '1';
    }

    public static function messageContentActive(array $all): bool
    {
        return ($all[self::MESSAGE_CONTENT_STATUS] ?? '') === '1';
    }

    /** "https://www.Beispiel.de/seite" => "beispiel.de" @throws OrgError */
    public static function normalizeDomain(string $input): string
    {
        $host = (string) preg_replace('#^[a-z]+://#', '', mb_strtolower(trim($input)));
        $host = (string) preg_replace('/^www\./', '', $host);
        $host = preg_split('#[/?\#:\s]#', $host)[0] ?? '';
        if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}$/', $host) !== 1) {
            throw new OrgError("„{$input}“ ist keine Domain. Beispiel: youtube.com");
        }
        return $host;
    }

    /** Wort für die Liste: Kleinbuchstaben, 2 bis 40 Zeichen. @throws OrgError */
    public static function normalizeWord(string $input): string
    {
        $w = mb_strtolower(trim($input));
        $len = mb_strlen($w);
        if ($len < 2 || $len > 40) {
            throw new OrgError("„{$input}“: Ein Wort braucht 2 bis 40 Zeichen.");
        }
        return $w;
    }

    /**
     * Speichert einen Bereich der Einstellungsseite. Kanäle und Rollen müssen auf dem Server existieren
     * (null = Liste unbekannt, weil Discord nicht erreichbar war: dann zählt nur die Form der ID).
     * @param array<string,mixed> $post
     * @param ?list<string> $channelIds
     * @param ?list<string> $roleIds
     * @throws OrgError
     */
    public static function save(string $orgId, string $section, array $post, ?array $channelIds, ?array $roleIds): void
    {
        $str = static fn (string $k): string => is_string($post[$k] ?? null) ? trim($post[$k]) : '';
        $channel = static fn (string $k): ?string => self::checkId($str($k), $channelIds, 'Kanal');
        $role = static fn (string $k): ?string => self::checkId($str($k), $roleIds, 'Rolle');
        switch ($section) {
            case 'termine':
                $values = [self::ANNOUNCE_CHANNEL => $channel('announceChannel')];
                break;
            case 'willkommen':
                $text = str_replace("\r\n", "\n", $str('welcomeText'));
                if (mb_strlen($text) > self::MAX_WELCOME_TEXT) {
                    throw new OrgError('Der Begrüßungstext darf höchstens ' . self::MAX_WELCOME_TEXT . ' Zeichen haben.');
                }
                $values = [
                    self::WELCOME_CHANNEL => $channel('welcomeChannel'),
                    self::RULES_CHANNEL => $channel('rulesChannel'),
                    self::WELCOME_TEXT => $text === self::DEFAULT_WELCOME ? null : $text,
                ];
                break;
            case 'moderation':
                $ids = self::modRoleIds($post, $roleIds);
                $values = [self::MOD_ROLES => implode(',', $ids), self::MOD_LOG_CHANNEL => $channel('modLogChannel')];
                break;
            case 'zuordnung':
                // Leer = unverändert; nur was gewählt wurde, wird eingetragen.
                $values = [];
                foreach ([self::ADMIN_CHANNEL => 'adminChannel', self::ANNOUNCE_CHANNEL => 'announceChannel', self::WELCOME_CHANNEL => 'welcomeChannel',
                    self::RULES_CHANNEL => 'rulesChannel', self::MOD_LOG_CHANNEL => 'modLogChannel'] as $name => $key) {
                    if ($str($key) !== '') {
                        $values[$name] = $channel($key);
                    }
                }
                if ($str('visitorRole') !== '') {
                    $values[self::VISITOR_ROLE] = $role('visitorRole');
                }
                $ids = self::modRoleIds($post, $roleIds);
                if ($ids !== []) {
                    $values[self::MOD_ROLES] = implode(',', $ids);
                }
                break;
            case 'automod':
                $values = [self::AUTOMOD => json_encode(self::automodFromPost($post, $channelIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
                break;
            default:
                throw new OrgError('Unbekannter Bereich.');
        }
        Db::transaction(static function () use ($orgId, $values): void {
            foreach ($values as $name => $value) {
                self::set($orgId, $name, $value);
            }
        });
    }

    /**
     * @param array<string,mixed> $post @param ?list<string> $channelIds
     * @return array{spam:bool,invites:bool,links:bool,mentions:int,words:list<string>,domains:list<string>,exemptChannels:list<string>}
     * @throws OrgError
     */
    private static function automodFromPost(array $post, ?array $channelIds): array
    {
        $lines = static fn (string $k): array => array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', is_string($post[$k] ?? null) ? $post[$k] : '') ?: []), static fn ($s) => $s !== ''));
        $mentions = trim(is_string($post['mentions'] ?? null) ? $post['mentions'] : '0');
        if (preg_match('/^\d{1,2}$/', $mentions) !== 1 || ((int) $mentions > 0 && (int) $mentions < 3) || (int) $mentions > self::MAX_MENTIONS) {
            throw new OrgError('Die Grenze für Erwähnungen muss 0 (aus) oder 3 bis ' . self::MAX_MENTIONS . ' sein.');
        }
        $words = array_values(array_unique(array_map([self::class, 'normalizeWord'], $lines('words'))));
        $domains = array_values(array_unique(array_map([self::class, 'normalizeDomain'], $lines('domains'))));
        $exempt = [];
        foreach (is_array($post['exemptChannels'] ?? null) ? $post['exemptChannels'] : [] as $id) {
            $id = self::checkId(trim((string) $id), $channelIds, 'Kanal');
            if ($id !== null && !in_array($id, $exempt, true)) {
                $exempt[] = $id;
            }
        }
        foreach ([[$words, self::MAX_WORDS, 'verbotene Wörter'], [$domains, self::MAX_DOMAINS, 'erlaubte Domains'], [$exempt, self::MAX_EXEMPT_CHANNELS, 'ausgenommene Kanäle']] as [$list, $max, $what]) {
            if (count($list) > $max) {
                throw new OrgError("Höchstens $max $what.");
            }
        }
        $on = static fn (string $k): bool => ($post[$k] ?? '') === '1';
        return [
            'spam' => $on('spam'), 'invites' => $on('invites'), 'links' => $on('links'), 'mentions' => (int) $mentions,
            'words' => $words, 'domains' => $domains, 'exemptChannels' => $exempt,
        ];
    }

    /** Moderatoren-Rollen aus dem Formular (ohne Doppelte, höchstens MAX_MOD_ROLES). @param array<string,mixed> $post @param ?list<string> $roleIds @return list<string> @throws OrgError */
    private static function modRoleIds(array $post, ?array $roleIds): array
    {
        $ids = [];
        foreach (is_array($post['modRoles'] ?? null) ? $post['modRoles'] : [] as $id) {
            $id = self::checkId(trim((string) $id), $roleIds, 'Rolle');
            if ($id !== null && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        if (count($ids) > self::MAX_MOD_ROLES) {
            throw new OrgError('Höchstens ' . self::MAX_MOD_ROLES . ' Moderatoren-Rollen.');
        }
        return $ids;
    }

    /** Suchwörter (kleingeschrieben, ohne Sonderzeichen) für den Vorschlag der Zuordnung: Einstellung => Wörter, nach Wichtigkeit. */
    private const SUGGEST_WORDS = [
        self::ANNOUNCE_CHANNEL => ['ankuendigung', 'ankündigung', 'termin', 'event', 'announce'],
        self::WELCOME_CHANNEL => ['willkommen', 'welcome'],
        self::RULES_CHANNEL => ['regel', 'rules'],
        self::MOD_LOG_CHANNEL => ['modlog', 'modprotokoll', 'protokoll', 'moderationlog'],
        self::ADMIN_CHANNEL => ['botadmin', 'adminbot', 'botein', 'admin'],
        self::VISITOR_ROLE => ['besucher', 'gast', 'visitor', 'guest'],
        self::MOD_ROLES => ['moderator'],
    ];

    /**
     * Schlägt nach den Namen vor, welcher Kanal und welche Rolle zu welcher Einstellung passt. Nur für Einstellungen, die noch leer sind.
     * @param array<string,string> $all @param list<array{id:string,name:string}> $channels @param list<array{id:string,name:string}> $roles
     * @return array<string,string> Einstellung => ID (bei MOD_ROLES eine ID)
     */
    public static function suggest(array $all, array $channels, array $roles): array
    {
        $norm = static fn (string $n): string => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($n)) ?? '';
        $out = [];
        foreach (self::SUGGEST_WORDS as $name => $words) {
            if (($all[$name] ?? '') !== '') {
                continue;
            }
            $list = in_array($name, [self::VISITOR_ROLE, self::MOD_ROLES], true) ? $roles : $channels;
            foreach ($words as $word) {
                foreach ($list as $item) {
                    if (str_contains($norm($item['name']), $word)) {
                        $out[$name] = $item['id'];
                        continue 3;
                    }
                }
            }
        }
        return $out;
    }

    /** Leer => null; sonst muss die ID eine Discord-ID sein und (wenn bekannt) auf dem Server existieren. @param ?list<string> $known @throws OrgError */
    private static function checkId(string $id, ?array $known, string $what): ?string
    {
        if ($id === '') {
            return null;
        }
        if (preg_match('/^\d{1,25}$/', $id) !== 1 || ($known !== null && !in_array($id, $known, true))) {
            throw new OrgError(($what === 'Kanal' ? 'Diesen Kanal' : 'Diese Rolle') . ' gibt es auf dem Server nicht (mehr). Bitte die Seite neu laden.');
        }
        return $id;
    }
}
