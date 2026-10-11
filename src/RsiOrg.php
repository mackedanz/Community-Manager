<?php

declare(strict_types=1);

namespace Hangar;

use Hangar\Http\Client;
use Hangar\Http\NetworkError;

/**
 * Abgleich mit der öffentlichen Mitgliederliste der Orga auf robertsspaceindustries.com. Daraus ergibt sich pro
 * Mitglied, ob es in der RSI-Orga ist (Rahmenfarbe in der Mitgliederliste). RSI hat dafür keine offizielle API:
 * Es wird dieselbe Abfrage genutzt wie von der Orga-Seite. Schlägt sie fehl, bleibt der alte Stand erhalten.
 */
final class RsiOrg
{
    private const ENDPOINT = 'https://robertsspaceindustries.com/api/orgs/getOrgMembers';
    /** RSI liefert höchstens 32 Mitglieder pro Seite, größere Werte führen zu leeren Seiten. */
    private const PAGE_SIZE = 32;
    /** Obergrenze, damit eine riesige Orga den Abgleich nicht ewig laufen lässt (3200 Mitglieder). */
    private const MAX_PAGES = 100;
    /** Pause zwischen zwei Seiten (Mikrosekunden), aus Rücksicht auf RSI. */
    private static int $pauseMicros = 300000;

    public static function pause(int $micros): void
    {
        self::$pauseMicros = $micros;
    }

    /** Kürzel bereinigen: nur Buchstaben, Ziffern, - und _, in Großbuchstaben. */
    public static function cleanSid(string $sid): ?string
    {
        $sid = strtoupper(trim($sid));
        return preg_match('/^[A-Z0-9_-]{2,20}$/', $sid) === 1 ? $sid : null;
    }

    /** Wo im Nickname der Handle steht (Auswahl je Orga, Schlüssel in organizations.handle_style). */
    public const HANDLE_STYLES = [
        'plain' => 'Außerhalb aller Klammern (z. B. „(Vorname) Handle [Discordname]“)',
        'round' => 'In runden Klammern (z. B. „Discordname (Handle)“)',
        'square' => 'In eckigen Klammern (z. B. „Discordname [Handle]“)',
        'curly' => 'In geschweiften Klammern (z. B. „Discordname {Handle}“)',
        'angle' => 'In spitzen Klammern (z. B. „Discordname <Handle>“)',
    ];

    private const GROUP_PATTERNS = [
        'round' => '/\(([^()]*)\)/u',
        'square' => '/\[([^\[\]]*)\]/u',
        'curly' => '/\{([^{}]*)\}/u',
        'angle' => '/<([^<>]*)>/u',
    ];

    public static function cleanHandleStyle(?string $style): string
    {
        return $style !== null && isset(self::HANDLE_STYLES[$style]) ? $style : 'plain';
    }

    /**
     * RSI-Handle aus einem Discord-Namen. $style sagt, wo er steht:
     * „plain“: Klammerzusätze am Anfang und Ende („eXpG_McDance (Micha)“, „(SpaceTrotter) mimack76 [Micha]“) gehören nicht dazu;
     * sonst: der Inhalt der ersten Klammer dieser Art, die einen gültigen Handle enthält („Micha (mimack76)“).
     * Gibt null zurück, wenn kein gültiger Handle (Buchstaben, Ziffern, _ und -) herauskommt.
     */
    public static function handleFromName(?string $name, string $style = 'plain'): ?string
    {
        if ($name === null) {
            return null;
        }
        $valid = static fn (string $h): ?string => preg_match('/^[A-Za-z0-9_-]{2,100}$/', $h) === 1 ? $h : null;
        if (isset(self::GROUP_PATTERNS[$style])) {
            preg_match_all(self::GROUP_PATTERNS[$style], $name, $m);
            foreach ($m[1] as $inner) {
                if (($h = $valid(trim($inner))) !== null) {
                    return $h;
                }
            }
            return null;
        }
        $h = trim($name);
        do {
            $before = $h;
            $h = trim((string) preg_replace(['/\s*[(\[{<][^)\]}>]*[)\]}>]\s*$/u', '/^\s*[(\[{<][^)\]}>]*[)\]}>]\s*/u'], '', $h));
        } while ($h !== $before);
        return $valid($h);
    }

    /**
     * Eine Seite der Mitgliederliste.
     * @return array{total:int,name:?string,members:list<array{handle:?string,main:bool}>}
     * @throws OrgError
     */
    private static function fetchPage(string $sid, int $page): array
    {
        try {
            $res = Client::request('POST', self::ENDPOINT, [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], json_encode(['symbol' => $sid, 'search' => '', 'pagesize' => self::PAGE_SIZE, 'page' => $page]), 30);
        } catch (NetworkError) {
            throw new OrgError('RSI ist gerade nicht erreichbar.');
        }
        $json = $res['status'] === 200 ? json_decode($res['body'], true) : null;
        if (!is_array($json) || ($json['success'] ?? 0) !== 1 || !is_string($json['data']['html'] ?? null)) {
            throw new OrgError("Die Mitgliederliste von „{$sid}“ lässt sich auf RSI nicht abrufen (Kürzel falsch oder Liste nicht öffentlich).");
        }
        $total = (int) ($json['data']['totalrows'] ?? 0);
        $name = null;
        $members = [];
        foreach (preg_split('/(?=<li class="member-item)/', $json['data']['html']) ?: [] as $card) {
            if (!str_starts_with($card, '<li class="member-item')) {
                continue;
            }
            if ($name === null && preg_match('/data-org-name="([^"]*)"/', $card, $n) === 1) {
                $name = trim(html_entity_decode($n[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            preg_match('/^<li class="([^"]*)"/', $card, $cls);
            $handle = preg_match('#href="/citizens/([^"/?\#]+)"#', $card, $h) === 1 ? urldecode($h[1]) : null;
            $members[] = ['handle' => $handle, 'main' => str_contains($cls[1] ?? '', 'org-main')];
        }
        return ['total' => $total, 'name' => $name, 'members' => $members];
    }

    /**
     * Prüft, ob es die Orga gibt, und holt ihren Namen (nur die erste Seite, schnell genug für eine Discord-Antwort).
     * @return array{name:string,total:int}
     * @throws OrgError
     */
    public static function lookup(string $sid): array
    {
        $first = self::fetchPage($sid, 1);
        if ($first['total'] < 1 || $first['name'] === null) {
            throw new OrgError("Zu „{$sid}“ hat RSI keine Orga mit sichtbaren Mitgliedern gefunden.");
        }
        return ['name' => $first['name'], 'total' => $first['total']];
    }

    /**
     * Speichert das Kürzel der Orga (nach erfolgreicher Prüfung) oder entfernt es (leer). Ein geändertes Kürzel
     * verwirft den alten Stand; der Abgleich selbst läuft separat (sync()).
     * @return ?string Name der RSI-Orga, null wenn getrennt
     * @throws OrgError
     */
    public static function connect(string $orgId, string $sid): ?string
    {
        if (trim($sid) === '') {
            Db::run('UPDATE organizations SET rsi_sid = NULL, rsi_org_name = NULL, rsi_redacted = 0, rsi_synced_at = NULL WHERE id = ?', [$orgId]);
            Db::run('DELETE FROM org_rsi_members WHERE org_id = ?', [$orgId]);
            return null;
        }
        $clean = self::cleanSid($sid) ?? throw new OrgError('Das RSI-Kürzel darf nur Buchstaben, Ziffern, - und _ enthalten (2 bis 20 Zeichen).');
        $info = self::lookup($clean);
        $old = Db::val('SELECT rsi_sid FROM organizations WHERE id = ?', [$orgId]);
        if ($old !== $clean) {
            Db::run('DELETE FROM org_rsi_members WHERE org_id = ?', [$orgId]);
            Db::run('UPDATE organizations SET rsi_redacted = 0, rsi_synced_at = NULL WHERE id = ?', [$orgId]);
        }
        Db::run('UPDATE organizations SET rsi_sid = ?, rsi_org_name = ? WHERE id = ?', [$clean, mb_substr($info['name'], 0, 190), $orgId]);
        return $info['name'];
    }

    /**
     * Holt die ganze Mitgliederliste und ersetzt den gespeicherten Stand. Bei einem Fehler bleibt der alte erhalten.
     * @return array{members:int,redacted:int}
     * @throws OrgError
     */
    public static function sync(string $orgId): array
    {
        $sid = Db::val('SELECT rsi_sid FROM organizations WHERE id = ?', [$orgId]);
        if (!is_string($sid) || $sid === '') {
            throw new OrgError('Für diese Orga ist kein RSI-Kürzel hinterlegt.');
        }
        $members = [];
        $redacted = 0;
        $total = 0;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            if ($page > 1 && self::$pauseMicros > 0) {
                usleep(self::$pauseMicros);
            }
            $p = self::fetchPage($sid, $page);
            $total = $p['total'];
            foreach ($p['members'] as $m) {
                if ($m['handle'] === null) {
                    $redacted++;
                } else {
                    $members[strtolower($m['handle'])] = $m;
                }
            }
            if ($p['members'] === [] || $page * self::PAGE_SIZE >= $total) {
                break;
            }
        }
        if ($total > 0 && $members === [] && $redacted === 0) {
            throw new OrgError('RSI hat keine Mitglieder geliefert; der alte Stand bleibt erhalten.');
        }
        Db::transaction(function () use ($orgId, $members, $redacted): void {
            Db::run('DELETE FROM org_rsi_members WHERE org_id = ?', [$orgId]);
            foreach ($members as $m) {
                Db::run('INSERT INTO org_rsi_members (org_id, handle, main) VALUES (?,?,?)', [$orgId, mb_substr($m['handle'], 0, 100), $m['main'] ? 1 : 0]);
            }
            Db::run('UPDATE organizations SET rsi_redacted = ?, rsi_synced_at = ? WHERE id = ?', [$redacted, Time::nowDb(), $orgId]);
        });
        return ['members' => count($members), 'redacted' => $redacted];
    }

    /** Alle Orgas mit RSI-Kürzel abgleichen (Cron). Fehler einer Orga stoppen die anderen nicht. @return array<string,string> */
    public static function syncAll(): array
    {
        $out = [];
        foreach (Db::all('SELECT id, slug FROM organizations WHERE rsi_sid IS NOT NULL ORDER BY slug') as $o) {
            try {
                $r = self::sync($o['id']);
                $out[$o['slug']] = "{$r['members']} Mitglieder, {$r['redacted']} verborgen";
                try {
                    $c = RsiHandleCheck::run($o['id']);
                    $out[$o['slug']] .= ", Handle-Prüfung: {$c['bound']} bestätigt, {$c['conflicts']} Konflikte, {$c['suggested']} Vorschläge";
                } catch (\Throwable $e) {
                    $out[$o['slug']] .= ', Handle-Prüfung fehlgeschlagen: ' . $e->getMessage();
                }
            } catch (OrgError $e) {
                $out[$o['slug']] = 'Fehler: ' . $e->getMessage();
            }
        }
        return $out;
    }

    /**
     * Status eines Mitglieds gegenüber der RSI-Orga: main, affiliate, out (sichtbar nicht drin) oder unknown
     * (nicht auffindbar, aber verborgene Mitglieder vorhanden). null = nichts anzuzeigen (kein Kürzel / noch kein Abgleich).
     * @param list<?string> $candidates mögliche Handles der Person (RSI-Sync, Nickname, Name)
     * @param array<string,bool> $roster Handle (klein) => Hauptorga
     */
    public static function status(array $candidates, array $roster, int $redacted): string
    {
        foreach ($candidates as $c) {
            if ($c !== null && isset($roster[strtolower($c)])) {
                return $roster[strtolower($c)] ? 'main' : 'affiliate';
            }
        }
        // Ohne ableitbaren Handle lässt sich nichts behaupten.
        return $redacted > 0 || array_filter($candidates, static fn (?string $c): bool => $c !== null) === [] ? 'unknown' : 'out';
    }
}
