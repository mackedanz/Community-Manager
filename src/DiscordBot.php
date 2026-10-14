<?php

declare(strict_types=1);

namespace Hangar;

use Hangar\Http\Client;
use Hangar\Http\NetworkError;

/**
 * Discord-API mit dem Bot-Token: Mitgliederliste (stündlicher Abgleich) und Server-Events.
 * Die Slash-Befehle bedient der Discord-Bot im Ordner BOT/.
 */
final class DiscordBot
{
    public static function configured(): bool
    {
        return Env::get('DISCORD_BOT_TOKEN') !== null;
    }

    /** @return array{status:int,body:string,headers:array<string,string>} */
    private static function call(string $method, string $path, ?array $json = null): array
    {
        $headers = ['Authorization' => 'Bot ' . Env::get('DISCORD_BOT_TOKEN', '')];
        $body = null;
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        try {
            return Client::request($method, Discord::API . $path, $headers, $body, 20);
        } catch (NetworkError) {
            throw new DiscordUnavailableError('Discord ist nicht erreichbar.');
        }
    }

    /**
     * Alle Mitglieder des Servers (seitenweise à 1000). Braucht den privilegierten Intent "Server Members".
     * @return list<array{id:string,name:string,nick:?string,accountName:string,avatar:?string,roles:list<string>}>
     */
    public static function members(string $guildId): array
    {
        $out = [];
        $after = '0';
        for ($page = 0; $page < 100; $page++) {
            $res = self::call('GET', '/guilds/' . rawurlencode($guildId) . '/members?limit=1000&after=' . $after);
            if ($res['status'] === 403 || $res['status'] === 401) {
                throw new DiscordAuthError('Der Bot darf die Mitgliederliste nicht lesen (Intent "Server Members" im Developer Portal einschalten).');
            }
            if ($res['status'] < 200 || $res['status'] >= 300) {
                throw new DiscordUnavailableError("Discord antwortet mit Status {$res['status']}.");
            }
            $rows = json_decode($res['body'], true);
            if (!is_array($rows) || $rows === []) {
                break;
            }
            foreach ($rows as $m) {
                $u = $m['user'] ?? null;
                if (!is_array($u) || empty($u['id']) || !empty($u['bot'])) {
                    continue;
                }
                $id = (string) $u['id'];
                $avatar = !empty($m['avatar'])
                    ? "https://cdn.discordapp.com/guilds/$guildId/users/$id/avatars/{$m['avatar']}.png"
                    : (!empty($u['avatar']) ? "https://cdn.discordapp.com/avatars/$id/{$u['avatar']}.png" : null);
                $out[] = [
                    'id' => $id,
                    'name' => (string) (($m['nick'] ?? null) ?: ($u['global_name'] ?? null) ?: ($u['username'] ?? 'Discord-Nutzer')),
                    'nick' => (($m['nick'] ?? null) ?: null) !== null ? (string) $m['nick'] : null,
                    'accountName' => (string) (($u['global_name'] ?? null) ?: ($u['username'] ?? 'Discord-Nutzer')),
                    'avatar' => $avatar,
                    'roles' => array_values(array_map('strval', is_array($m['roles'] ?? null) ? $m['roles'] : [])),
                ];
            }
            if (count($rows) < 1000) {
                break;
            }
            $after = (string) end($rows)['user']['id'];
        }
        return $out;
    }

    /**
     * Geplante und laufende Server-Events (Discord liefert beendete und abgesagte nur kurz). Zeiten in UTC.
     * @return list<array{id:string,name:string,description:?string,location:?string,startsAt:\DateTimeImmutable,endsAt:?\DateTimeImmutable,status:int,creatorId:?string}>
     */
    public static function scheduledEvents(string $guildId): array
    {
        $res = self::call('GET', '/guilds/' . rawurlencode($guildId) . '/scheduled-events');
        if ($res['status'] === 403 || $res['status'] === 401) {
            throw new DiscordAuthError('Der Bot darf die Events dieses Servers nicht lesen.');
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new DiscordUnavailableError("Discord antwortet mit Status {$res['status']}.");
        }
        $utc = new \DateTimeZone('UTC');
        $out = [];
        foreach (json_decode($res['body'], true) ?: [] as $e) {
            if (!is_array($e) || empty($e['id']) || empty($e['scheduled_start_time'])) {
                continue;
            }
            try {
                $start = (new \DateTimeImmutable((string) $e['scheduled_start_time']))->setTimezone($utc);
                $end = !empty($e['scheduled_end_time']) ? (new \DateTimeImmutable((string) $e['scheduled_end_time']))->setTimezone($utc) : null;
            } catch (\Exception) {
                continue;
            }
            $out[] = [
                'id' => (string) $e['id'], 'name' => (string) ($e['name'] ?? ''), 'description' => isset($e['description']) ? (string) $e['description'] : null,
                'location' => isset($e['entity_metadata']['location']) ? (string) $e['entity_metadata']['location'] : null,
                'startsAt' => $start, 'endsAt' => $end, 'status' => (int) ($e['status'] ?? 1),
                'creatorId' => isset($e['creator_id']) ? (string) $e['creator_id'] : null,
            ];
        }
        return $out;
    }

    /**
     * Sprachkanäle des Servers in der Reihenfolge von Discord (für die Auswahl beim Termin).
     * @return list<array{id:string,name:string}>
     */
    public static function voiceChannels(string $guildId): array
    {
        $rows = array_filter(self::channels($guildId), static fn ($c) => ($c['type'] ?? null) === 2);
        usort($rows, static fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));
        return array_map(static fn ($c) => ['id' => (string) $c['id'], 'name' => (string) ($c['name'] ?? $c['id'])], array_values($rows));
    }

    /**
     * Text- und Ankündigungskanäle, sortiert wie in Discord (ohne Kategorie zuerst, dann je Kategorie).
     * @return list<array{id:string,name:string,category:?string}>
     */
    public static function textChannels(string $guildId): array
    {
        $all = self::channels($guildId);
        $cats = [];
        foreach ($all as $c) {
            if (($c['type'] ?? null) === 4) {
                $cats[(string) $c['id']] = ['name' => (string) ($c['name'] ?? ''), 'position' => (int) ($c['position'] ?? 0)];
            }
        }
        $rows = array_values(array_filter($all, static fn ($c) => in_array($c['type'] ?? null, [0, 5], true)));
        $key = static function (array $c) use ($cats): array {
            $cat = $cats[(string) ($c['parent_id'] ?? '')] ?? null;
            return [$cat === null ? -1 : $cat['position'], (int) ($c['position'] ?? 0)];
        };
        usort($rows, static fn ($a, $b) => $key($a) <=> $key($b));
        return array_map(static fn ($c) => [
            'id' => (string) $c['id'],
            'name' => (string) ($c['name'] ?? $c['id']),
            'category' => $cats[(string) ($c['parent_id'] ?? '')]['name'] ?? null,
        ], $rows);
    }

    /**
     * Rollen, die man vergeben kann: ohne @everyone und ohne Rollen von Bots und Integrationen, die höchste zuerst.
     * @return list<array{id:string,name:string,color:int}>
     */
    public static function roles(string $guildId): array
    {
        $res = self::call('GET', '/guilds/' . rawurlencode($guildId) . '/roles');
        self::checkRead($res, 'Rollen');
        $rows = array_values(array_filter(json_decode($res['body'], true) ?: [], static fn ($r) => is_array($r) && !empty($r['id'])
            && (string) $r['id'] !== $guildId && empty($r['managed'])));
        usort($rows, static fn ($a, $b) => ($b['position'] ?? 0) <=> ($a['position'] ?? 0));
        return array_map(static fn ($r) => ['id' => (string) $r['id'], 'name' => (string) ($r['name'] ?? $r['id']), 'color' => (int) ($r['color'] ?? 0)], $rows);
    }

    /** Alle Kanäle des Servers (roh). @return list<array<string,mixed>> */
    private static function channels(string $guildId): array
    {
        $res = self::call('GET', '/guilds/' . rawurlencode($guildId) . '/channels');
        self::checkRead($res, 'Kanäle');
        return array_values(array_filter(json_decode($res['body'], true) ?: [], static fn ($c) => is_array($c) && !empty($c['id'])));
    }

    /** @param array{status:int,body:string,headers:array<string,string>} $res */
    private static function checkRead(array $res, string $what): void
    {
        if ($res['status'] === 403 || $res['status'] === 401) {
            throw new DiscordAuthError("Der Bot darf die $what dieses Servers nicht lesen.");
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new DiscordUnavailableError("Discord antwortet mit Status {$res['status']}.");
        }
    }

    /** @param array{status:int,body:string,headers:array<string,string>} $res */
    private static function checkEventWrite(array $res): void
    {
        if ($res['status'] === 401 || $res['status'] === 403) {
            throw new DiscordAuthError('Dem Bot fehlt die Berechtigung „Events erstellen“.');
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new DiscordUnavailableError("Discord antwortet mit Status {$res['status']}: " . mb_substr(preg_replace('/\s+/', ' ', $res['body']) ?? '', 0, 120));
        }
    }

    /** Legt ein externes Server-Event an und liefert dessen ID. @param array<string,mixed> $payload */
    public static function createScheduledEvent(string $guildId, array $payload): string
    {
        $res = self::call('POST', '/guilds/' . rawurlencode($guildId) . '/scheduled-events', $payload);
        self::checkEventWrite($res);
        $e = json_decode($res['body'], true);
        if (!is_array($e) || empty($e['id'])) {
            throw new DiscordUnavailableError('Discord hat keine Event-ID geliefert.');
        }
        return (string) $e['id'];
    }

    /** Ändert ein Server-Event. false, wenn es in Discord nicht mehr existiert. @param array<string,mixed> $payload */
    public static function updateScheduledEvent(string $guildId, string $eventId, array $payload): bool
    {
        $res = self::call('PATCH', '/guilds/' . rawurlencode($guildId) . '/scheduled-events/' . rawurlencode($eventId), $payload);
        if ($res['status'] === 404) {
            return false;
        }
        self::checkEventWrite($res);
        return true;
    }

    /** Löscht ein Server-Event (ein bereits fehlendes gilt als gelöscht). */
    public static function deleteScheduledEvent(string $guildId, string $eventId): void
    {
        $res = self::call('DELETE', '/guilds/' . rawurlencode($guildId) . '/scheduled-events/' . rawurlencode($eventId));
        if ($res['status'] !== 404) {
            self::checkEventWrite($res);
        }
    }
}
