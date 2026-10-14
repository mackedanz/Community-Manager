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

    /**
     * Aufbau des Servers: Kategorien (mit ihren Rechten und Kanälen) und Kanäle ohne Kategorie, jeweils in der Reihenfolge von Discord.
     * type: 0 Text, 2 Sprache, 5 Ankündigung, 13 Bühne, 15 Forum (andere Typen fehlen).
     * @return array{categories:list<array{id:string,name:string,overwrites:list<array{id:string,type:int,allow:string,deny:string}>,channels:list<array{id:string,name:string,type:int}>}>,loose:list<array{id:string,name:string,type:int}>}
     */
    public static function structure(string $guildId): array
    {
        $all = self::channels($guildId);
        usort($all, static fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));
        $cats = [];
        foreach ($all as $c) {
            if (($c['type'] ?? null) === 4) {
                $overwrites = [];
                foreach (is_array($c['permission_overwrites'] ?? null) ? $c['permission_overwrites'] : [] as $o) {
                    if (is_array($o) && isset($o['id'])) {
                        $overwrites[] = ['id' => (string) $o['id'], 'type' => (int) ($o['type'] ?? 0), 'allow' => (string) ($o['allow'] ?? '0'), 'deny' => (string) ($o['deny'] ?? '0')];
                    }
                }
                $cats[(string) $c['id']] = ['id' => (string) $c['id'], 'name' => (string) ($c['name'] ?? $c['id']), 'overwrites' => $overwrites, 'channels' => []];
            }
        }
        $loose = [];
        foreach ($all as $c) {
            $type = $c['type'] ?? null;
            if (!in_array($type, [0, 2, 5, 13, 15], true)) {
                continue;
            }
            $row = ['id' => (string) $c['id'], 'name' => (string) ($c['name'] ?? $c['id']), 'type' => $type];
            $parent = (string) ($c['parent_id'] ?? '');
            if ($parent !== '' && isset($cats[$parent])) {
                $cats[$parent]['channels'][] = $row;
            } else {
                $loose[] = $row;
            }
        }
        return ['categories' => array_values($cats), 'loose' => $loose];
    }

    /** ID des Bots selbst (für dessen Rechte in neuen Kanälen), null wenn Discord sie nicht liefert. */
    public static function selfId(): ?string
    {
        $res = self::call('GET', '/users/@me');
        $u = json_decode($res['body'], true);
        return $res['status'] === 200 && is_array($u) && !empty($u['id']) ? (string) $u['id'] : null;
    }

    /**
     * Legt eine Kategorie oder einen Kanal an und liefert dessen ID. Bei Ratenbegrenzung wartet es einmal kurz.
     * @param array<string,mixed> $payload
     * @throws DiscordAuthError|DiscordUnavailableError
     */
    public static function createChannel(string $guildId, array $payload): string
    {
        return self::createObject('/guilds/' . rawurlencode($guildId) . '/channels', $payload, 'Kanäle verwalten', 'Kanälen');
    }

    /**
     * Legt eine Rolle an (name, permissions als Zahl-Text, color, hoist, mentionable) und liefert deren ID.
     * @param array<string,mixed> $payload
     * @throws DiscordAuthError|DiscordUnavailableError
     */
    public static function createRole(string $guildId, array $payload): string
    {
        return self::createObject('/guilds/' . rawurlencode($guildId) . '/roles', $payload, 'Rollen verwalten', 'Rollen');
    }

    /** @param array<string,mixed> $payload */
    private static function createObject(string $path, array $payload, string $needed, string $what): string
    {
        $res = self::call('POST', $path, $payload);
        if ($res['status'] === 429) {
            $wait = (float) ((json_decode($res['body'], true)['retry_after'] ?? 2));
            if ($wait <= 10) {
                usleep((int) ($wait * 1_000_000) + 200_000);
                $res = self::call('POST', $path, $payload);
            }
        }
        if ($res['status'] === 401 || $res['status'] === 403) {
            throw new DiscordAuthError("Dem Bot fehlt die Berechtigung „{$needed}“, er besitzt ein gewünschtes Recht selbst nicht, oder seine Rolle steht zu tief.");
        }
        if ($res['status'] === 429) {
            throw new DiscordUnavailableError("Discord bremst das Anlegen von {$what}. Bitte in einigen Minuten weitermachen.");
        }
        if ($res['status'] < 200 || $res['status'] >= 300) {
            $msg = json_decode($res['body'], true)['message'] ?? mb_substr(preg_replace('/s+/', ' ', $res['body']) ?? '', 0, 120);
            throw new DiscordUnavailableError("Discord lehnt das Anlegen ab (Status {$res['status']}): $msg");
        }
        $c = json_decode($res['body'], true);
        if (!is_array($c) || empty($c['id'])) {
            throw new DiscordUnavailableError('Discord hat keine ID geliefert.');
        }
        return (string) $c['id'];
    }

    /**
     * Alle Rollen mit ihren Rechten, die höchste zuerst; @everyone hat die Server-ID als ID.
     * @return list<array{id:string,name:string,color:int,hoist:bool,mentionable:bool,managed:bool,position:int,permissions:int}>
     */
    public static function roleDetails(string $guildId): array
    {
        $res = self::call('GET', '/guilds/' . rawurlencode($guildId) . '/roles');
        self::checkRead($res, 'Rollen');
        $rows = array_values(array_filter(json_decode($res['body'], true) ?: [], static fn ($r) => is_array($r) && !empty($r['id'])));
        usort($rows, static fn ($a, $b) => ($b['position'] ?? 0) <=> ($a['position'] ?? 0));
        return array_map(static fn ($r) => [
            'id' => (string) $r['id'], 'name' => (string) ($r['name'] ?? $r['id']), 'color' => (int) ($r['color'] ?? 0),
            'hoist' => !empty($r['hoist']), 'mentionable' => !empty($r['mentionable']), 'managed' => !empty($r['managed']),
            'position' => (int) ($r['position'] ?? 0), 'permissions' => (int) ($r['permissions'] ?? 0),
        ], $rows);
    }

    /**
     * Rechte, die der Bot selbst hat (alle seine Rollen und @everyone); -1 = alle (Administrator), null = nicht zu ermitteln.
     * Nur diese Rechte kann er an neue Rollen und in Kanälen vergeben.
     * @param list<array{id:string,permissions:int}> $roles Ergebnis von roleDetails()
     */
    public static function botPermissions(string $guildId, array $roles): ?int
    {
        $botId = self::selfId();
        if ($botId === null) {
            return null;
        }
        $res = self::call('GET', '/guilds/' . rawurlencode($guildId) . '/members/' . rawurlencode($botId));
        $m = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($m)) {
            return null;
        }
        $have = array_merge([$guildId], array_map('strval', is_array($m['roles'] ?? null) ? $m['roles'] : []));
        $bits = 0;
        foreach ($roles as $r) {
            if (in_array($r['id'], $have, true)) {
                $bits |= $r['permissions'];
            }
        }
        return ($bits & (1 << 3)) !== 0 ? -1 : $bits;
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
