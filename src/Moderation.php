<?php

declare(strict_types=1);

namespace Hangar;

/**
 * Moderation von Hand im Web (Seite „Fälle“): verwarnen, Timeout, Kick, Bann und aufheben, Verwarnung zurücknehmen.
 * Fälle landen in bot_mod_cases (Migration 023), genau wie die von /mod im Bot; die Person wird per Direktnachricht
 * informiert und der Fall in den Protokoll-Kanal geschrieben. Regeln und Texte entsprechen BOT/src/lib/moderation.js.
 */
final class Moderation
{
    public const ACTIONS = [
        'WARN' => 'Verwarnung', 'TIMEOUT' => 'Timeout', 'UNTIMEOUT' => 'Timeout aufgehoben', 'KICK' => 'Kick', 'BAN' => 'Bann',
        'UNBAN' => 'Bann aufgehoben', 'PURGE' => 'Nachrichten gelöscht', 'AUTOMOD' => 'Automod',
    ];
    /** Was im Web ausgelöst werden kann: Schlüssel => Beschriftung im Formular. */
    public const DO = [
        'warn' => 'Verwarnen', 'timeout' => 'Timeout setzen', 'untimeout' => 'Timeout aufheben', 'kick' => 'Kicken', 'ban' => 'Bannen', 'unban' => 'Bann aufheben',
    ];
    public const MAX_TIMEOUT_SECONDS = 28 * 86400;
    public const PER_PAGE = 25;

    private const COLORS = ['WARN' => 0xf59e0b, 'TIMEOUT' => 0xf97316, 'UNTIMEOUT' => 0x22c55e, 'KICK' => 0xef4444, 'BAN' => 0xb91c1c, 'UNBAN' => 0x22c55e, 'PURGE' => 0x71717a, 'AUTOMOD' => 0x8b5cf6];
    private const UNITS = ['m' => 60, 'min' => 60, 'h' => 3600, 'std' => 3600, 'd' => 86400, 't' => 86400, 'w' => 7 * 86400];

    /** "30m", "2h", "1d", "1w" (auch "10 min", "3 t") als Sekunden; ohne Einheit Minuten. @throws OrgError */
    public static function parseDuration(string $text): int
    {
        if (preg_match('/^(\d{1,6})\s*([a-zäöü]*)$/iu', trim($text), $m) !== 1) {
            throw new OrgError('Die Dauer kenne ich nicht. Beispiele: 30m, 2h, 1d, 1w (höchstens 28 Tage).');
        }
        $unit = $m[2] === '' ? 'm' : mb_strtolower($m[2]);
        if (!isset(self::UNITS[$unit]) || (int) $m[1] < 1) {
            throw new OrgError('Die Dauer kenne ich nicht. Beispiele: 30m, 2h, 1d, 1w (höchstens 28 Tage).');
        }
        $s = (int) $m[1] * self::UNITS[$unit];
        if ($s > self::MAX_TIMEOUT_SECONDS) {
            throw new OrgError('Ein Timeout dauert höchstens 28 Tage.');
        }
        return $s;
    }

    public static function durationText(int $seconds): string
    {
        $parts = [];
        $rest = intdiv($seconds + 30, 60);
        foreach ([['Tag', 'Tage', 1440], ['Stunde', 'Stunden', 60], ['Minute', 'Minuten', 1]] as [$one, $many, $size]) {
            $n = intdiv($rest, $size);
            $rest -= $n * $size;
            if ($n > 0) {
                $parts[] = $n . ' ' . ($n === 1 ? $one : $many);
            }
        }
        return $parts === [] ? 'weniger als 1 Minute' : implode(' ', $parts);
    }

    /** Text der Direktnachricht an die Person. */
    public static function dmText(string $guildName, string $action, ?string $reason, ?string $detail = null): string
    {
        $what = ['WARN' => 'verwarnt', 'TIMEOUT' => 'für eine Zeit stummgeschaltet', 'KICK' => 'vom Server entfernt', 'BAN' => 'vom Server gebannt'][$action] ?? 'moderiert';
        return 'Du wurdest auf **' . mb_substr($guildName, 0, 100) . "** $what" . ($detail ? " ($detail)" : '') . '.' . ($reason ? "\nGrund: " . mb_substr($reason, 0, 500) : '');
    }

    /**
     * Gründe, aus denen diese Person nicht moderiert werden darf (null = in Ordnung). Wer im Manager Admin ist, darf wie ein
     * Server-Admin jede Person moderieren, außer sich selbst, den Bot und den Eigentümer; die Rolle des Bots muss höher stehen.
     * Wer nur Moderator ist (actorTop gesetzt), darf wie im Bot nur Personen moderieren, deren höchste Rolle unter der eigenen steht.
     * @param array{actorId:?string,targetId:string,botId:?string,ownerId:?string,botTop:?int,targetTop:int,actorTop?:?int} $c
     */
    public static function targetProblem(array $c): ?string
    {
        if ($c['actorId'] !== null && $c['actorId'] === $c['targetId']) {
            return 'Du kannst dich nicht selbst moderieren.';
        }
        if ($c['botId'] !== null && $c['botId'] === $c['targetId']) {
            return 'Den Bot selbst kann man nicht moderieren.';
        }
        if ($c['ownerId'] !== null && $c['ownerId'] === $c['targetId']) {
            return 'Der Server-Eigentümer lässt sich nicht moderieren.';
        }
        if ($c['botTop'] !== null && $c['targetTop'] >= $c['botTop']) {
            return 'Der Bot kann diese Person nicht moderieren: Seine Rolle muss im Server höher stehen als die höchste Rolle der Person.';
        }
        if (($c['actorTop'] ?? null) !== null && $c['targetTop'] >= $c['actorTop']) {
            return 'Diese Person steht in der Rangfolge auf deiner Höhe oder darüber. Das dürfen nur höhere Rollen.';
        }
        return null;
    }

    public static function activeWarnings(string $orgId, string $targetId): int
    {
        return (int) Db::val("SELECT COUNT(*) FROM bot_mod_cases WHERE org_id = ? AND target_id = ? AND action = 'WARN' AND removed_at IS NULL", [$orgId, $targetId]);
    }

    /** @throws OrgError */
    public static function removeWarning(string $orgId, int $caseId): void
    {
        $n = Db::exec("UPDATE bot_mod_cases SET removed_at = ? WHERE id = ? AND org_id = ? AND action = 'WARN' AND removed_at IS NULL", [Time::nowDb(), $caseId, $orgId]);
        if ($n === 0) {
            throw new OrgError("Eine offene Verwarnung mit der Nummer $caseId gibt es nicht.");
        }
    }

    /**
     * Fälle einer Orga, neueste zuerst, mit Namen der Moderatoren (wenn sie ein Konto im Manager haben).
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function cases(string $orgId, string $search, string $action, int $page): array
    {
        $where = 'c.org_id = ?';
        $args = [$orgId];
        if (isset(self::ACTIONS[$action])) {
            $where .= ' AND c.action = ?';
            $args[] = $action;
        }
        $search = trim($search);
        if ($search !== '') {
            $where .= ' AND (c.target_id = ? OR c.target_name LIKE ?)';
            $args[] = $search;
            $args[] = '%' . addcslashes($search, '%_\\') . '%';
        }
        $total = (int) Db::val("SELECT COUNT(*) FROM bot_mod_cases c WHERE $where", $args);
        $rows = Db::all(
            "SELECT c.*, u.name AS moderator_name FROM bot_mod_cases c LEFT JOIN users u ON u.discord_id = c.moderator_id
              WHERE $where ORDER BY c.id DESC LIMIT " . self::PER_PAGE . ' OFFSET ' . (max(1, $page) - 1) * self::PER_PAGE,
            $args,
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Die Person zur Eingabe: Discord-ID oder Name (muss genau eine Person treffen).
     * @return array{id:string,name:string,member:?array{id:string,name:string,roles:list<string>,until:?string,bot:bool}}
     * @throws OrgError|DiscordAuthError|DiscordUnavailableError
     */
    public static function resolve(string $guildId, string $query, bool $idOnly = false): array
    {
        $q = trim($query);
        if ($q === '') {
            throw new OrgError('Bitte die Person angeben (Name oder Discord-ID).');
        }
        if (preg_match('/^\d{17,20}$/', $q) === 1) {
            $m = DiscordBot::member($guildId, $q);
            return ['id' => $q, 'name' => $m['name'] ?? $q, 'member' => $m];
        }
        if ($idOnly) {
            throw new OrgError('Hier brauche ich die Discord-ID (17 bis 20 Ziffern). In Discord mit Entwicklermodus: Rechtsklick auf die Person, „Nutzer-ID kopieren“.');
        }
        $found = DiscordBot::searchMembers($guildId, ltrim($q, '@'));
        $exact = array_values(array_filter($found, static fn ($m) => mb_strtolower($m['name']) === mb_strtolower(ltrim($q, '@'))));
        $pick = count($exact) === 1 ? $exact : (count($found) === 1 ? $found : []);
        if ($pick === []) {
            throw new OrgError($found === []
                ? "Niemanden mit dem Namen „{$q}“ auf dem Server gefunden. Probiere die Discord-ID."
                : 'Mehrere Personen passen: ' . implode(', ', array_map(static fn ($m) => $m['name'], $found)) . '. Bitte genauer eingeben oder die Discord-ID nehmen.');
        }
        return ['id' => $pick[0]['id'], 'name' => $pick[0]['name'], 'member' => $pick[0]];
    }

    /**
     * Führt eine Maßnahme aus und gibt die Meldung für die Seite zurück.
     * @param array{id:string,name:?string,discord_id:?string,admin?:bool} $viewer wer im Manager handelt (admin=false: nur Moderator, dann zählt die Rangfolge der Rollen)
     * @param array{do:string,person:string,reason:string,duration:string,days:int} $in
     * @throws OrgError|DiscordAuthError|DiscordUnavailableError
     */
    public static function perform(string $orgId, string $guildId, array $viewer, array $in): string
    {
        $do = $in['do'];
        if (!isset(self::DO[$do])) {
            throw new OrgError('Diese Maßnahme gibt es nicht.');
        }
        $reason = trim($in['reason']) === '' ? null : mb_substr(trim($in['reason']), 0, 500);
        if ($do === 'warn' && $reason === null) {
            throw new OrgError('Eine Verwarnung braucht einen Grund.');
        }
        $seconds = $do === 'timeout' ? self::parseDuration($in['duration']) : 0;
        $t = self::resolve($guildId, $in['person'], $do === 'unban');
        $member = $t['member'];
        if (in_array($do, ['timeout', 'untimeout', 'kick'], true) && $member === null) {
            throw new OrgError('Diese Person ist nicht (mehr) auf dem Server.');
        }
        if ($do === 'unban') {
            $bannedName = DiscordBot::banned($guildId, $t['id']);
            if ($bannedName === null) {
                throw new OrgError('Diese Person ist nicht gebannt.');
            }
            $t['name'] = $bannedName;
        }
        if ($do === 'untimeout' && ($member['until'] === null || strtotime($member['until']) <= time())) {
            throw new OrgError('Diese Person hat gerade keinen Timeout.');
        }
        if ($do !== 'unban') {
            $details = DiscordBot::roleDetails($guildId);
            $tops = [];
            foreach ($details as $r) {
                if ($member !== null && in_array($r['id'], $member['roles'], true)) {
                    $tops[] = $r['position'];
                }
            }
            $actorTop = null;
            if (($viewer['admin'] ?? true) === false) {
                $actor = $viewer['discord_id'] !== null ? DiscordBot::member($guildId, $viewer['discord_id']) : null;
                if ($actor === null) {
                    throw new OrgError('Dein Discord-Konto wurde auf dem Server nicht gefunden.');
                }
                $actorTops = [];
                foreach ($details as $r) {
                    if (in_array($r['id'], $actor['roles'], true)) {
                        $actorTops[] = $r['position'];
                    }
                }
                $actorTop = $actorTops === [] ? -1 : max($actorTops);
            }
            $problem = self::targetProblem([
                'actorId' => $viewer['discord_id'], 'targetId' => $t['id'], 'botId' => DiscordBot::selfId(),
                'ownerId' => DiscordBot::ownerId($guildId), 'botTop' => DiscordBot::botMember($guildId, $details)['top'] ?? null,
                'targetTop' => $tops === [] ? -1 : max($tops), 'actorTop' => $actorTop,
            ]);
            if ($problem !== null) {
                throw new OrgError($problem);
            }
        }
        $guildName = $do === 'kick' || $do === 'ban' || $do === 'warn' || $do === 'timeout' ? self::guildName($orgId) : '';
        $who = $viewer['name'] ?? 'Manager';
        $audit = $who . ': ' . ($reason ?? 'ohne Angabe');
        $detail = null;
        $informed = null;
        switch ($do) {
            case 'warn':
                $action = 'WARN';
                $informed = DiscordBot::dm($t['id'], self::dmText($guildName, 'WARN', $reason));
                break;
            case 'timeout':
                $action = 'TIMEOUT';
                $detail = self::durationText($seconds);
                DiscordBot::timeoutMember($guildId, $t['id'], gmdate('Y-m-d\TH:i:s\Z', time() + $seconds), $audit);
                $informed = DiscordBot::dm($t['id'], self::dmText($guildName, 'TIMEOUT', $reason, $detail));
                break;
            case 'untimeout':
                $action = 'UNTIMEOUT';
                DiscordBot::timeoutMember($guildId, $t['id'], null, $audit);
                break;
            case 'kick':
                $action = 'KICK';
                // Erst benachrichtigen: nach dem Kick teilen Bot und Person keinen Server mehr
                $informed = DiscordBot::dm($t['id'], self::dmText($guildName, 'KICK', $reason));
                DiscordBot::kickMember($guildId, $t['id'], $audit);
                break;
            case 'ban':
                $action = 'BAN';
                $days = max(0, min(7, $in['days']));
                $detail = $days > 0 ? "Nachrichten der letzten $days Tage gelöscht" : null;
                $informed = $member !== null ? DiscordBot::dm($t['id'], self::dmText($guildName, 'BAN', $reason)) : null;
                DiscordBot::banMember($guildId, $t['id'], $days, $audit);
                break;
            default:
                $action = 'UNBAN';
                DiscordBot::unbanMember($guildId, $t['id'], $audit);
        }
        $id = self::addCase($orgId, $t['id'], $t['name'], $viewer['discord_id'], $action, $reason, $detail);
        self::log($orgId, $id, $action, $t, $who, $reason, $detail);
        $label = self::ACTIONS[$action];
        $msg = "Fall #$id: $label für {$t['name']}.";
        if ($action === 'WARN') {
            $n = self::activeWarnings($orgId, $t['id']);
            $msg .= " Aktive Verwarnungen: $n.";
        }
        if ($informed !== null) {
            $msg .= $informed ? ' Die Person wurde per Direktnachricht informiert.' : ' Die Person war per Direktnachricht nicht erreichbar.';
        }
        return $msg;
    }

    private static function guildName(string $orgId): string
    {
        return (string) Db::val('SELECT name FROM organizations WHERE id = ?', [$orgId]);
    }

    public static function addCase(string $orgId, string $targetId, string $targetName, ?string $moderatorId, string $action, ?string $reason, ?string $detail): int
    {
        Db::run(
            'INSERT INTO bot_mod_cases (org_id, target_id, target_name, moderator_id, action, reason, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$orgId, $targetId, mb_substr($targetName, 0, 100), $moderatorId, $action, $reason, $detail === null ? null : mb_substr($detail, 0, 200), Time::nowDb()],
        );
        return (int) Db::pdo()->lastInsertId();
    }

    /** Fall in den Protokoll-Kanal schreiben (wenn einer eingestellt ist); Fehler stören die Maßnahme nicht. @param array{id:string,name:string} $t */
    private static function log(string $orgId, int $id, string $action, array $t, string $who, ?string $reason, ?string $detail): void
    {
        $channel = BotSettings::all($orgId)[BotSettings::MOD_LOG_CHANNEL] ?? '';
        if ($channel === '') {
            return;
        }
        $fields = [['name' => 'Person', 'value' => "<@{$t['id']}> (" . mb_substr($t['name'], 0, 100) . ')', 'inline' => true], ['name' => 'Von', 'value' => $who . ' (Manager)', 'inline' => true]];
        if ($detail !== null) {
            $fields[] = ['name' => 'Details', 'value' => $detail, 'inline' => true];
        }
        if ($reason !== null) {
            $fields[] = ['name' => 'Grund', 'value' => $reason];
        }
        DiscordBot::post($channel, ['embeds' => [[
            'title' => "Fall #$id · " . self::ACTIONS[$action], 'color' => self::COLORS[$action] ?? 0x71717a, 'fields' => $fields, 'timestamp' => gmdate('c'),
        ]]]);
    }
}
