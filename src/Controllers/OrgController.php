<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\Auth;
use Hangar\Community;
use Hangar\Db;
use Hangar\DiscordAuthError;
use Hangar\DiscordUnavailableError;
use Hangar\EventTime;
use Hangar\Events;
use Hangar\FleetFilter;
use Hangar\Http\Flash;
use Hangar\Http\HttpException;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\Http\View;
use Hangar\Onboarding;
use Hangar\OrgError;
use Hangar\Orgs;
use Hangar\OrgStats;
use Hangar\RateLimit;
use Hangar\RsiOrg;

final class OrgController extends Controller
{
    /**
     * Seite im Orga-Rahmen (Kopf mit Navigation).
     * @param array{id:string,slug:string,name:string,iconUrl:?string,role:string,canPlan:bool} $org
     * @param array<string,mixed> $data
     */
    public static function orgPage(array $org, string $view, array $data, string $title, string $active): Response
    {
        $inner = View::render($view, $data + ['org' => $org], null);
        return self::page('org_frame', ['org' => $org, 'inner' => $inner, 'active' => $active, 'wide' => $view !== 'org_settings'], $title . ' · ' . $org['name']);
    }

    private static function errorMessage(\Throwable $e): string
    {
        return match (true) {
            $e instanceof OrgError => $e->getMessage(),
            $e instanceof DiscordAuthError => 'Discord-Zugriff abgelaufen. Bitte melde dich ab und neu mit Discord an.',
            $e instanceof DiscordUnavailableError => 'Discord ist gerade nicht erreichbar.',
            default => throw $e,
        };
    }

    /** Rollen-ID-Felder und die danebenstehenden Namensfelder (gleiche Reihenfolge) zu {id: name}. @param mixed $ids @param mixed $names @return array<string,string> */
    public static function roleNames(mixed $ids, mixed $names): array
    {
        $ids = is_array($ids) ? array_values($ids) : [];
        $names = is_array($names) ? array_values($names) : [];
        $out = [];
        foreach ($ids as $i => $id) {
            $id = trim((string) $id);
            if ($id !== '' && trim((string) ($names[$i] ?? '')) !== '') {
                $out[$id] = (string) $names[$i];
            }
        }
        return $out;
    }

    // --- Orga verwalten ----------------------------------------------------------------------

    public static function settings(Request $req, array $p): Response
    {
        [, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        if ($org['role'] !== 'ADMIN') {
            throw HttpException::notFound();
        }
        $full = Orgs::find($org['id']) ?? throw HttpException::notFound();
        return self::orgPage($org, 'org_settings', [
            'full' => $full,
            'memberRoleIds' => Orgs::parseRoleIds($full['member_role_ids']),
            'plannerRoleIds' => Orgs::parseRoleIds($full['planner_role_ids']),
            'recruiterRoleIds' => Orgs::parseRoleIds($full['recruiter_role_ids'] ?? null),
            'instructorRoleIds' => Orgs::parseRoleIds($full['instructor_role_ids'] ?? null),
            'leadRoleIds' => Orgs::parseRoleIds($full['lead_role_ids'] ?? null),
            'roleLabels' => Orgs::parseRoleLabels($full['role_labels']),
            'maxRoles' => Orgs::MAX_ROLES,
            'allowed' => Db::all('SELECT discord_id, name, avatar_url, fixed FROM org_allowed_members WHERE org_id = ? ORDER BY name ASC', [$org['id']]),
            'botReady' => \Hangar\DiscordBot::configured(),
            'gate' => Onboarding::gateEnabled(),
            'rsiRoster' => (int) Db::val('SELECT COUNT(*) FROM org_rsi_members WHERE org_id = ?', [$org['id']]),
            'brand' => \Hangar\Branding::current(),
        ], 'Orga verwalten', 'settings');
    }

    public static function update(Request $req, array $p): Response
    {
        $viewer = Auth::requireViewer($req);
        $orgId = (string) $req->input('orgId', '');
        $slug = (string) Db::val('SELECT slug FROM organizations WHERE id = ?', [$orgId]);
        try {
            Orgs::update($viewer->id, $orgId, [
                'name' => (string) $req->input('name', ''),
                'memberRoleIds' => $req->post['memberRoleIds'] ?? [],
                'plannerRoleIds' => $req->post['plannerRoleIds'] ?? [],
                'recruiterRoleIds' => $req->post['recruiterRoleIds'] ?? [],
                'instructorRoleIds' => $req->post['instructorRoleIds'] ?? [],
                'leadRoleIds' => $req->post['leadRoleIds'] ?? [],
                'roleNames' => self::roleNames($req->post['memberRoleIds'] ?? [], $req->post['memberRoleNames'] ?? [])
                    + self::roleNames($req->post['plannerRoleIds'] ?? [], $req->post['plannerRoleNames'] ?? [])
                    + self::roleNames($req->post['recruiterRoleIds'] ?? [], $req->post['recruiterRoleNames'] ?? [])
                    + self::roleNames($req->post['instructorRoleIds'] ?? [], $req->post['instructorRoleNames'] ?? [])
                    + self::roleNames($req->post['leadRoleIds'] ?? [], $req->post['leadRoleNames'] ?? []),
            ]);
        } catch (\Throwable $e) {
            return Flash::error("/o/$slug/settings", self::errorMessage($e));
        }
        return Flash::ok("/o/$slug/settings", 'Gespeichert.');
    }

    public static function syncAllowlist(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        $orgId = (string) $req->input('orgId', '');
        $slug = (string) Db::val('SELECT slug FROM organizations WHERE id = ?', [$orgId]);
        try {
            Orgs::requireOrgAdmin($viewer->id, $orgId);
            $r = Onboarding::syncAllowlist($orgId);
        } catch (\Throwable $e) {
            return Flash::error("/o/$slug/settings", self::errorMessage($e));
        }
        return Flash::ok("/o/$slug/settings", "Zugangsliste abgeglichen: {$r['total']} Mitglieder (+{$r['added']}, −{$r['removed']}).");
    }

    /** RSI-Kürzel der Orga speichern (leer = trennen) und die Mitgliederliste sofort abgleichen. */
    public static function saveDiscordEvents(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        $orgId = (string) $req->input('orgId', '');
        $slug = (string) Db::val('SELECT slug FROM organizations WHERE id = ?', [$orgId]);
        $mode = (string) $req->input('mode', 'OFF');
        try {
            Orgs::requireOrgAdmin($viewer->id, $orgId);
            if (!in_array($mode, \Hangar\DiscordEvents::MODES, true)) {
                throw new OrgError('Ungültige Auswahl.');
            }
            Db::run('UPDATE organizations SET discord_events_mode = ? WHERE id = ?', [$mode, $orgId]);
            if ($mode === 'OFF') {
                return Flash::ok("/o/$slug/settings", 'Übernahme der Discord-Events ausgeschaltet.');
            }
            $limit = RateLimit::hit("discord-events:$orgId", 10, 3600);
            if (!$limit['ok']) {
                return Flash::ok("/o/$slug/settings", 'Gespeichert. Der Abgleich läuft gleich automatisch.');
            }
            $result = \Hangar\DiscordEvents::syncOrg(Orgs::find($orgId) ?? throw new OrgError('Orga nicht gefunden.'));
        } catch (\Throwable $e) {
            return Flash::error("/o/$slug/settings", self::errorMessage($e));
        }
        return Flash::ok("/o/$slug/settings", "Gespeichert und abgeglichen: $result.");
    }

    /** Wo im Nickname der RSI-Handle steht (nur Orga-Admins). */
    public static function saveHandleStyle(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        $orgId = (string) $req->input('orgId', '');
        $slug = (string) Db::val('SELECT slug FROM organizations WHERE id = ?', [$orgId]);
        try {
            Orgs::requireOrgAdmin($viewer->id, $orgId);
            Onboarding::setHandleStyle($orgId, (string) $req->input('handleStyle', ''));
        } catch (\Throwable $e) {
            return Flash::error("/o/$slug/settings", self::errorMessage($e));
        }
        return Flash::ok("/o/$slug/settings", 'Gespeichert.');
    }

    /** Logo, Hintergrund und Deckkraft der Installation (nur Admins; die Orga-Admins sind die Discord-Server-Admins). */
    public static function saveBranding(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        $orgId = (string) $req->input('orgId', '');
        $slug = (string) Db::val('SELECT slug FROM organizations WHERE id = ?', [$orgId]);
        $to = "/o/$slug/settings";
        try {
            Orgs::requireOrgAdmin($viewer->id, $orgId);
            $reset = (string) $req->input('reset', '');
            if ($reset !== '') {
                \Hangar\Branding::reset($reset);
                return Flash::ok($to, 'Zurückgesetzt.');
            }
            foreach (['logo', 'background'] as $kind) {
                $f = $req->files[$kind] ?? null;
                if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($f['error'] !== UPLOAD_ERR_OK) {
                    throw new OrgError('Der Upload ist fehlgeschlagen (Datei zu groß?).');
                }
                \Hangar\Branding::setImage($kind, (string) file_get_contents((string) $f['tmp_name']));
            }
            $num = static fn (string $k): ?int => preg_match('/^\d{1,3}$/', trim((string) $req->input($k, ''))) === 1 ? (int) trim((string) $req->input($k)) : null;
            \Hangar\Branding::setOpacity($num('opacityDark'), $num('opacityLight'));
        } catch (\Throwable $e) {
            return Flash::error($to, self::errorMessage($e));
        }
        return Flash::ok($to, 'Design gespeichert.');
    }

    public static function saveRsi(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        $orgId = (string) $req->input('orgId', '');
        $slug = (string) Db::val('SELECT slug FROM organizations WHERE id = ?', [$orgId]);
        try {
            Orgs::requireOrgAdmin($viewer->id, $orgId);
            // Der Abgleich ruft bis zu 100 Seiten bei RSI ab: je Orga höchstens 10 Mal pro Stunde (Trennen ist frei).
            if (trim((string) $req->input('rsiSid', '')) !== '') {
                $limit = RateLimit::hit("rsi-sync:$orgId", 10, 3600);
                if (!$limit['ok']) {
                    throw new OrgError('Der RSI-Abgleich lief zu oft. Bitte in ' . (int) ceil($limit['retryAfterSec'] / 60) . ' Minuten erneut versuchen.');
                }
            }
            $name = RsiOrg::connect($orgId, (string) $req->input('rsiSid', ''));
            if ($name === null) {
                return Flash::ok("/o/$slug/settings", 'RSI-Orga getrennt.');
            }
            $r = RsiOrg::sync($orgId);
        } catch (\Throwable $e) {
            return Flash::error("/o/$slug/settings", self::errorMessage($e));
        }
        return Flash::ok("/o/$slug/settings", "RSI-Orga „{$name}“ verbunden: {$r['members']} sichtbare Mitglieder, {$r['redacted']} verborgen.");
    }

    public static function delete(Request $req): Response
    {
        $viewer = Auth::requireViewer($req);
        $orgId = (string) $req->input('orgId', '');
        $slug = (string) Db::val('SELECT slug FROM organizations WHERE id = ?', [$orgId]);
        if ($req->input('confirm') !== 'LÖSCHEN') {
            return Flash::error("/o/$slug/settings", 'Bitte LÖSCHEN eintippen.');
        }
        try {
            Orgs::delete($viewer->id, $orgId);
        } catch (\Throwable $e) {
            return Flash::error("/o/$slug/settings", self::errorMessage($e));
        }
        return Response::redirect('/');
    }

    // --- Orga-Bereich ------------------------------------------------------------------------

    public static function home(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);
        $today = EventTime::dayKey($now);
        $tomorrow = (new \DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
        $from = EventTime::parseBerlinLocal($today . 'T00:00') ?? $now;
        $days = ['today' => [], 'tomorrow' => []];
        foreach (Events::listTimeline($org['id'], $viewer, $from, 1) as $e) {
            if ($e['cancelled'] || $e['draft']) {
                continue;
            }
            $k = EventTime::dayKey($e['startsAt']);
            if ($k === $today) {
                $days['today'][] = $e;
            } elseif ($k === $tomorrow) {
                $days['tomorrow'][] = $e;
            }
        }
        return self::orgPage($org, 'org_home', [
            'feed' => Community::getFeed($org['id'], $viewer, 5, false, true),
            'stats' => OrgStats::forOrg($org['id'], $viewer),
            'days' => $days,
            'next' => Events::listUpcoming($org['id'], $viewer, $now, 1)[0] ?? null,
            'trainingCount' => array_sum(\Hangar\Training::countsByArea($org['id'])),
            'trainingEnabled' => Orgs::parseRoleIds(Orgs::find($org['id'])['instructor_role_ids'] ?? null) !== [],
            'departmentCount' => \Hangar\Departments::count($org['id']),
            'departmentsEnabled' => \Hangar\Departments::enabled($org['id']),
            'upcomingCount' => count(Events::listUpcoming($org['id'], $viewer, $now, 100)),
            'botStatus' => $org['role'] === 'ADMIN' ? \Hangar\BotSettings::status(\Hangar\BotSettings::all($org['id']), $org['id']) : null,
            'now' => $now,
        ], 'Übersicht', 'home');
    }

    public static function members(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        $all = Community::listMembers($org['id'], $viewer);
        // Filter nach RSI-Status (Klick auf die Legende); die Anzahl je Status zählt über alle Mitglieder.
        $counts = array_count_values(array_filter(array_column($all, 'rsiStatus')));
        $rsi = (string) $req->query('rsi', '');
        // Rahmen, Legende und Filter gibt es nur für Recruiter und Admins (Community::listMembers liefert sonst keinen Status).
        $rsi = !empty($org['canRecruit']) && in_array($rsi, ['main', 'affiliate', 'out', 'unknown'], true) ? $rsi : '';
        $members = $rsi === '' ? $all : array_values(array_filter($all, static fn (array $m): bool => $m['rsiStatus'] === $rsi));
        return self::orgPage($org, 'org_members', [
            'members' => $members,
            'total' => count($all),
            'rsiFilter' => $rsi,
            'rsiCounts' => $counts,
            'hasRsi' => $all !== [] && $all[0]['rsiStatus'] !== null,
            'rsiOrgName' => Db::val('SELECT rsi_org_name FROM organizations WHERE id = ?', [$org['id']]),
        ], 'Mitglieder', 'members');
    }

    public static function hangar(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        $all = Community::getOrgFleet($org['id'], $viewer);
        $filter = FleetFilter::parseFilter($req->query);
        $options = FleetFilter::options(array_column($all['entries'], 'specs'));
        $entries = array_values(array_filter($all['entries'], fn ($e) => FleetFilter::matches($e['specs'], $filter) && FleetFilter::matchesText($e, $filter['q'] ?? '')));
        $sort = FleetFilter::parseSort($req->query);
        $entries = FleetFilter::sortByName($entries, static fn (array $e): string => (string) $e['name'], $sort);
        return self::orgPage($org, 'org_hangar', [
            'all' => $all, 'filter' => $filter, 'options' => $options, 'entries' => $entries, 'sort' => $sort,
            'filtering' => $filter !== [],
            'totalShips' => array_sum(array_column($entries, 'count')),
        ], 'Orga-Flotte', 'hangar');
    }

    public static function stats(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        $stats = OrgStats::forOrg($org['id'], $viewer) ?? throw HttpException::notFound();
        return self::orgPage($org, 'org_stats', ['stats' => $stats], 'Statistik', 'stats');
    }
}
