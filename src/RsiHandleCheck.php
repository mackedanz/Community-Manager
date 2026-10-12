<?php

declare(strict_types=1);

namespace Hangar;

use Hangar\Http\Client;
use Hangar\Http\NetworkError;

/**
 * Prüft, ob der RSI-Handle eines Mitglieds noch zu seinem RSI-Konto gehört. Anker ist die UEE Citizen Record Nummer
 * aus dem öffentlichen Dossier (/citizens/<Handle>); sie ändert sich nie, der Handle schon. Wird ein Mitglied in der
 * RSI-Orga nicht mehr gefunden, sucht die Prüfung unter den Handles der Orga, die zu keinem Mitglied passen, den mit
 * derselben Nummer und merkt ihn als Vorschlag. Es wird nie etwas übernommen: Der Nickname wird im Discord angepasst.
 */
final class RsiHandleCheck
{
    private const DOSSIER = 'https://robertsspaceindustries.com/en/citizens/';
    /** Wie lange eine geprüfte Zuordnung Handle → Nummer gilt, bevor sie erneut geprüft wird (Tage). */
    private const RECHECK_DAYS = 7;
    /** Höchstzahl der Dossier-Abrufe je Orga und Lauf, aus Rücksicht auf RSI. */
    private const MAX_FETCHES = 400;
    private static int $pauseMicros = 300000;

    public static function pause(int $micros): void
    {
        self::$pauseMicros = $micros;
    }

    /**
     * Citizen Record Nummer zu einem Handle.
     * @return array{status:'ok'|'missing'|'unreachable',record:?int}
     */
    public static function record(string $handle): array
    {
        try {
            $res = Client::request('GET', self::DOSSIER . rawurlencode($handle), ['Accept' => 'text/html'], null, 20);
        } catch (NetworkError) {
            return ['status' => 'unreachable', 'record' => null];
        }
        if ($res['status'] === 404) {
            return ['status' => 'missing', 'record' => null];
        }
        if ($res['status'] === 200 && preg_match('/Citizen Record.*?#(\d{1,12})/s', $res['body'], $m) === 1) {
            return ['status' => 'ok', 'record' => (int) $m[1]];
        }
        return ['status' => 'unreachable', 'record' => null];
    }

    /**
     * Prüft die Mitglieder einer Orga gegen den gespeicherten RSI-Stand (RsiOrg::sync muss vorher gelaufen sein).
     * @return array{bound:int,conflicts:int,suggested:int}
     */
    public static function run(string $orgId): array
    {
        $org = Db::one('SELECT rsi_synced_at, handle_style FROM organizations WHERE id = ?', [$orgId]);
        $out = ['bound' => 0, 'conflicts' => 0, 'suggested' => 0];
        if ($org === null || $org['rsi_synced_at'] === null) {
            return $out;
        }
        $style = (string) $org['handle_style'];
        $roster = [];
        foreach (Db::all('SELECT handle FROM org_rsi_members WHERE org_id = ?', [$orgId]) as $r) {
            $roster[strtolower($r['handle'])] = $r['handle'];
        }
        $rows = Db::all(
            'SELECT u.id, u.name, u.rsi_handle, m.nick, c.handle AS c_handle, c.record_no, c.status, c.record_checked_at, c.suggested_handle
               FROM org_memberships m JOIN users u ON u.id = m.user_id LEFT JOIN rsi_checks c ON c.user_id = u.id
              WHERE m.org_id = ?',
            [$orgId],
        );
        $fetches = 0;
        $fetch = static function (string $handle) use (&$fetches): ?array {
            if ($fetches >= self::MAX_FETCHES) {
                return null;
            }
            if ($fetches++ > 0 && self::$pauseMicros > 0) {
                usleep(self::$pauseMicros);
            }
            return self::record($handle);
        };

        $claimed = [];
        $unmatched = [];
        $recheckBefore = Time::db(Time::now()->modify('-' . self::RECHECK_DAYS . ' days'));
        foreach ($rows as $u) {
            $matched = null;
            $nickHandle = RsiOrg::handleFromName($u['nick'], $style);
            // Der Kontoname zählt nur, wenn der Nickname keinen Handle ergibt (siehe Community::rsiHandleOf).
            foreach ([$u['rsi_handle'], $nickHandle, $nickHandle === null ? RsiOrg::handleFromName($u['name']) : null] as $cand) {
                if ($cand !== null && $cand !== '') {
                    $claimed[strtolower($cand)] = true;
                    $matched ??= $roster[strtolower($cand)] ?? null;
                }
            }
            if ($matched === null) {
                $unmatched[] = $u;
                continue;
            }
            $known = $u['record_no'] !== null && $u['c_handle'] !== null && strcasecmp($u['c_handle'], $matched) === 0;
            if ($known && $u['record_checked_at'] !== null && $u['record_checked_at'] >= $recheckBefore) {
                if ($u['suggested_handle'] !== null) {
                    Db::run('UPDATE rsi_checks SET suggested_handle = NULL WHERE user_id = ?', [$u['id']]);
                }
                continue;
            }
            $r = $fetch($matched);
            if ($r === null || $r['status'] !== 'ok') {
                continue;
            }
            $conflict = $u['record_no'] !== null && (int) $u['record_no'] !== $r['record'];
            Db::run(
                'INSERT INTO rsi_checks (user_id, handle, record_no, status, record_checked_at, suggested_handle) VALUES (?,?,?,?,?,NULL)
                 ON DUPLICATE KEY UPDATE handle = VALUES(handle), record_no = COALESCE(record_no, VALUES(record_no)), status = VALUES(status),
                                         record_checked_at = VALUES(record_checked_at), suggested_handle = NULL',
                [$u['id'], mb_substr($matched, 0, 100), $r['record'], $conflict ? 'conflict' : 'ok', Time::nowDb()],
            );
            $conflict ? $out['conflicts']++ : $out['bound']++;
        }

        // Nicht mehr auffindbare Mitglieder, deren Nummer bekannt ist: unter den übrig gebliebenen Orga-Handles suchen.
        $lost = array_values(array_filter($unmatched, static fn (array $u): bool => $u['record_no'] !== null));
        if ($lost === []) {
            return $out;
        }
        $byRecord = [];
        foreach ($roster as $key => $handle) {
            if (isset($claimed[$key])) {
                continue;
            }
            $r = $fetch($handle);
            if ($r === null) {
                break;
            }
            if ($r['status'] === 'ok') {
                $byRecord[$r['record']] = $handle;
            }
        }
        foreach ($lost as $u) {
            $new = $byRecord[(int) $u['record_no']] ?? null;
            if ($new !== $u['suggested_handle']) {
                Db::run('UPDATE rsi_checks SET suggested_handle = ? WHERE user_id = ?', [$new, $u['id']]);
            }
            if ($new !== null) {
                $out['suggested']++;
            }
        }
        return $out;
    }
}
