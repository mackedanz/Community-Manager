<?php

declare(strict_types=1);

namespace Hangar;

use Hangar\Http\Client;
use Hangar\Http\NetworkError;

/**
 * YouTube-Creator des Discord-Bots (Tabellen bot_creators und bot_creator_videos, Migration 021). Gleiche Regeln wie
 * BOT/src/lib/{creators,youtube}.js: Kanal aus Link, @Name oder Kanal-ID ermitteln, den öffentlichen Feed prüfen,
 * bereits vorhandene Videos als bekannt eintragen, damit nur künftige gepostet werden.
 */
final class Creators
{
    /** Höchstzahl Creator je Orga, damit die Abfragen bei YouTube überschaubar bleiben. */
    public const MAX = 50;

    private const CHANNEL_ID = '/^UC[A-Za-z0-9_-]{22}$/';
    private const HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (compatible; Community-Manager-Bot)',
        'Accept-Language' => 'de-DE,de;q=0.9',
        // Ohne diese Cookies leitet YouTube aus der EU auf die Zustimmungsseite um
        'Cookie' => 'SOCS=CAI; CONSENT=YES+1',
    ];

    public const PLATFORMS = ['youtube' => 'YouTube', 'twitch' => 'Twitch'];

    /** Anmeldename aus einem Link (twitch.tv/name), @Name oder dem Namen selbst, klein geschrieben; null, wenn es keiner sein kann. */
    public static function parseTwitchLogin(string $input): ?string
    {
        $s = trim($input);
        if (preg_match('~^(?:https?://)?(?:www\.|m\.)?twitch\.tv/([A-Za-z0-9_]+)/?(?:[?#].*)?$~i', $s, $m) === 1) {
            $s = $m[1];
        }
        $s = strtolower(ltrim($s, '@'));
        return preg_match('/^[a-z0-9_]{3,25}$/', $s) === 1 ? $s : null;
    }

    /** @return list<array<string,mixed>> */
    public static function list(string $orgId): array
    {
        return Db::all('SELECT * FROM bot_creators WHERE org_id = ? ORDER BY name ASC', [$orgId]);
    }

    /** Was wurde eingegeben? Kanal-ID, Link mit /channel/UC…, @Name oder Link mit @Name. @return array{channelId?:string,handle?:string}|null */
    public static function parseInput(string $input): ?array
    {
        $s = trim($input);
        if (preg_match(self::CHANNEL_ID, $s) === 1) {
            return ['channelId' => $s];
        }
        if (preg_match('~youtube\.com/channel/(UC[A-Za-z0-9_-]{22})~i', $s, $m) === 1) {
            return ['channelId' => $m[1]];
        }
        if (preg_match('/^@([A-Za-z0-9._-]{3,100})$/', $s, $m) === 1 || preg_match('~youtube\.com/@([A-Za-z0-9._%-]{3,100})~i', $s, $m) === 1) {
            return ['handle' => rawurldecode($m[1])];
        }
        return null;
    }

    /** Kanal-ID und Name aus dem HTML einer Kanalseite. @return array{channelId:?string,name:?string} */
    public static function parseChannelPage(string $html): array
    {
        $id = null;
        if (preg_match('~<link rel="canonical" href="https://www\.youtube\.com/channel/(UC[A-Za-z0-9_-]{22})"~', $html, $m) === 1
            || preg_match('/"externalId":"(UC[A-Za-z0-9_-]{22})"/', $html, $m) === 1) {
            $id = $m[1];
        }
        $name = preg_match('/<meta property="og:title" content="([^"]*)"/', $html, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
        return ['channelId' => $id, 'name' => $name];
    }

    /**
     * Videos aus dem Feed (neueste zuerst).
     * @return array{name:?string,videos:list<array{id:string,title:string,url:string,short:bool}>}
     */
    public static function parseFeed(string $xml): array
    {
        $name = preg_match('~<author>\s*<name>([^<]*)</name>~', $xml, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
        $videos = [];
        foreach (array_slice(explode('<entry>', $xml), 1) as $entry) {
            if (preg_match('~<yt:videoId>([A-Za-z0-9_-]{6,20})</yt:videoId>~', $entry, $m) !== 1) {
                continue;
            }
            $id = $m[1];
            $url = preg_match('~<link rel="alternate" href="([^"]+)"~', $entry, $u) === 1 ? $u[1] : "https://www.youtube.com/watch?v=$id";
            $title = preg_match('~<title>([^<]*)</title>~', $entry, $t) === 1 ? html_entity_decode($t[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
            $videos[] = ['id' => $id, 'title' => $title, 'url' => $url, 'short' => str_contains($url, '/shorts/')];
        }
        return ['name' => $name, 'videos' => $videos];
    }

    /** @return array{status:int,body:string} @throws OrgError */
    private static function fetch(string $url): array
    {
        try {
            return Client::get($url, self::HEADERS, 20);
        } catch (NetworkError) {
            throw new OrgError('YouTube ist gerade nicht erreichbar.');
        }
    }

    /** @return array{name:?string,videos:list<array{id:string,title:string,url:string,short:bool}>} @throws OrgError */
    public static function fetchFeed(string $channelId): array
    {
        $res = self::fetch('https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode($channelId));
        if ($res['status'] === 404) {
            throw new OrgError('Diesen YouTube-Kanal gibt es nicht (mehr).');
        }
        if ($res['status'] !== 200) {
            throw new OrgError('YouTube antwortet mit Status ' . $res['status'] . '.');
        }
        return self::parseFeed($res['body']);
    }

    /** Kanal-ID und Name zu einer Eingabe. @return array{channelId:string,name:string,videos:list<array{id:string,title:string,url:string,short:bool}>} @throws OrgError */
    public static function resolve(string $input): array
    {
        $parsed = self::parseInput($input);
        if ($parsed === null) {
            throw new OrgError('Das sieht nicht nach einem YouTube-Kanal aus. Erlaubt: Link zum Kanal (youtube.com/@Name oder youtube.com/channel/UC…), @Name oder die Kanal-ID.');
        }
        $channelId = $parsed['channelId'] ?? null;
        $name = null;
        if ($channelId === null) {
            $handle = (string) $parsed['handle'];
            $res = self::fetch('https://www.youtube.com/@' . rawurlencode($handle));
            if ($res['status'] === 404) {
                throw new OrgError("Einen YouTube-Kanal @$handle gibt es nicht.");
            }
            if ($res['status'] !== 200) {
                throw new OrgError('YouTube antwortet mit Status ' . $res['status'] . '.');
            }
            $page = self::parseChannelPage($res['body']);
            if ($page['channelId'] === null) {
                throw new OrgError('Die Kanal-ID ließ sich nicht ermitteln. Bitte den Link mit /channel/UC… angeben.');
            }
            $channelId = $page['channelId'];
            $name = $page['name'];
        }
        // Der Feed bestätigt den Kanal und liefert den Namen
        $feed = self::fetchFeed($channelId);
        return ['channelId' => $channelId, 'name' => $feed['name'] ?? $name ?? $channelId, 'videos' => $feed['videos']];
    }

    /**
     * Creator hinzufügen oder Kanal, Rolle und Shorts eines vorhandenen ändern. Videos, die schon im Feed stehen, gelten als bekannt.
     * @return array{id:string,updated:bool,name:string} @throws OrgError
     */
    public static function add(string $orgId, string $input, string $discordChannelId, ?string $roleId, bool $skipShorts, string $platform = 'youtube'): array
    {
        if ($platform === 'twitch') {
            return self::addTwitch($orgId, $input, $discordChannelId, $roleId);
        }
        $yt = self::resolve($input);
        $existing = Db::val("SELECT id FROM bot_creators WHERE org_id = ? AND platform = 'youtube' AND channel_id = ?", [$orgId, $yt['channelId']]);
        if ($existing === null || $existing === false) {
            if ((int) Db::val('SELECT COUNT(*) FROM bot_creators WHERE org_id = ?', [$orgId]) >= self::MAX) {
                throw new OrgError('Höchstens ' . self::MAX . ' Creator je Orga.');
            }
            $id = new_id();
            Db::run(
                "INSERT INTO bot_creators (id, org_id, platform, channel_id, name, discord_channel_id, mention_role_id, skip_shorts, checked_at) VALUES (?, ?, 'youtube', ?, ?, ?, ?, ?, ?)",
                [$id, $orgId, $yt['channelId'], mb_substr($yt['name'], 0, 190), $discordChannelId, $roleId, $skipShorts ? 1 : 0, Time::nowDb()],
            );
        } else {
            $id = (string) $existing;
            Db::run(
                'UPDATE bot_creators SET name = ?, discord_channel_id = ?, mention_role_id = ?, skip_shorts = ?, last_error = NULL WHERE id = ?',
                [mb_substr($yt['name'], 0, 190), $discordChannelId, $roleId, $skipShorts ? 1 : 0, $id],
            );
        }
        foreach ($yt['videos'] as $v) {
            Db::run('INSERT IGNORE INTO bot_creator_videos (creator_id, video_id) VALUES (?, ?)', [$id, $v['id']]);
        }
        return ['id' => $id, 'updated' => $existing !== null && $existing !== false, 'name' => $yt['name']];
    }

    /**
     * Twitch-Kanal eintragen (oder Discord-Kanal und Rolle eines vorhandenen ändern). Die Web-App fragt Twitch nicht selbst: der Bot prüft
     * den Kanal beim nächsten Durchlauf (resolved = 0), trägt den Anzeigenamen ein oder meldet in last_error, dass es ihn nicht gibt.
     * @return array{id:string,updated:bool,name:string} @throws OrgError
     */
    private static function addTwitch(string $orgId, string $input, string $discordChannelId, ?string $roleId): array
    {
        $login = self::parseTwitchLogin($input) ?? throw new OrgError('Das sieht nicht nach einem Twitch-Kanal aus. Erlaubt: Link zum Kanal (twitch.tv/name), @Name oder der Anmeldename (3 bis 25 Zeichen: Buchstaben, Ziffern, Unterstrich).');
        $existing = Db::val("SELECT id FROM bot_creators WHERE org_id = ? AND platform = 'twitch' AND channel_id = ?", [$orgId, $login]);
        if ($existing !== null && $existing !== false) {
            Db::run('UPDATE bot_creators SET discord_channel_id = ?, mention_role_id = ?, last_error = NULL WHERE id = ?', [$discordChannelId, $roleId, (string) $existing]);
            return ['id' => (string) $existing, 'updated' => true, 'name' => $login];
        }
        if ((int) Db::val('SELECT COUNT(*) FROM bot_creators WHERE org_id = ?', [$orgId]) >= self::MAX) {
            throw new OrgError('Höchstens ' . self::MAX . ' Creator je Orga.');
        }
        $id = new_id();
        Db::run(
            "INSERT INTO bot_creators (id, org_id, platform, channel_id, name, discord_channel_id, mention_role_id, skip_shorts, resolved) VALUES (?, ?, 'twitch', ?, ?, ?, ?, 0, 0)",
            [$id, $orgId, $login, $login, $discordChannelId, $roleId],
        );
        return ['id' => $id, 'updated' => false, 'name' => $login];
    }

    /** Ändert nur Kanal, Rolle und Shorts eines Creators (ohne YouTube zu fragen). @throws OrgError */
    public static function update(string $orgId, string $id, string $discordChannelId, ?string $roleId, bool $skipShorts): void
    {
        if ((int) Db::val('SELECT COUNT(*) FROM bot_creators WHERE id = ? AND org_id = ?', [$id, $orgId]) === 0) {
            throw new OrgError('Diesen Creator gibt es nicht (mehr).');
        }
        Db::run(
            'UPDATE bot_creators SET discord_channel_id = ?, mention_role_id = ?, skip_shorts = ? WHERE id = ? AND org_id = ?',
            [$discordChannelId, $roleId, $skipShorts ? 1 : 0, $id, $orgId],
        );
    }

    public static function remove(string $orgId, string $id): bool
    {
        return Db::exec('DELETE FROM bot_creators WHERE id = ? AND org_id = ?', [$id, $orgId]) > 0;
    }
}
