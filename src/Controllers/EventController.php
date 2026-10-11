<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use DateTimeImmutable;
use Hangar\Auth;
use Hangar\Community;
use Hangar\EventBriefing;
use Hangar\EventError;
use Hangar\Events;
use Hangar\EventTime;
use Hangar\Http\Flash;
use Hangar\Http\HttpException;
use Hangar\Http\Request;
use Hangar\Http\Response;

final class EventController extends Controller
{
    private const MONTHS = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

    /** ?month=YYYY-MM, sonst der aktuelle Monat (nach Berliner Zeit). @return array{0:int,1:int} */
    private static function parseMonth(?string $raw): array
    {
        if ($raw !== null && preg_match('/^(\d{4})-(\d{2})$/', $raw, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12 && (int) $m[1] >= 2000 && (int) $m[1] <= 2100) {
            return [(int) $m[1], (int) $m[2]];
        }
        [$y, $mo] = explode('-', EventTime::dayKey(new DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        return [(int) $y, (int) $mo];
    }

    public static function index(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        return OrgController::orgPage($org, 'events_index', [
            'today' => EventTime::dayKey(new DateTimeImmutable('now', new \DateTimeZone('UTC'))),
            'timeline' => self::timeline($org['id'], $viewer),
        ], 'Termine', 'events');
    }

    /**
     * Monatsspalten von einem Jahr vor bis ein Jahr nach dem aktuellen Monat (25 Spalten, Berliner Zeit), jede mit ihren Events.
     * @return list<array{key:string,label:string,events:list<array<string,mixed>>}>
     */
    private static function timeline(string $orgId, $viewer): array
    {
        [$y, $m] = self::parseMonth(null);
        $start = (new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->modify('-12 months');
        $from = EventTime::parseBerlinLocal($start->format('Y-m') . '-01T00:00') ?? throw HttpException::notFound();
        $cols = [];
        for ($i = 0; $i <= 24; $i++) {
            $d = $start->modify("+$i months");
            $cols[$d->format('Y-m')] = ['key' => $d->format('Y-m'), 'label' => self::MONTHS[(int) $d->format('n') - 1] . ' ' . $d->format('Y'), 'events' => []];
        }
        foreach (Events::listTimeline($orgId, $viewer, $from, 25) as $e) {
            $k = substr(EventTime::dayKey($e['startsAt']), 0, 7);
            if (isset($cols[$k])) {
                $cols[$k]['events'][] = $e;
            }
        }
        return array_values($cols);
    }

    public static function show(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        $event = Events::getEvent($org['id'], $viewer, (string) $p['id']) ?? throw HttpException::notFound();
        // Vergangene und abgesagte Termine öffnen nur Planer (wie in der Zeitleiste gesperrt).
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $over = ($event['endsAt'] ?? $event['startsAt']) < $now;
        if (($over || $event['cancelled']) && !$org['canPlan']) {
            throw HttpException::notFound();
        }
        return OrgController::orgPage($org, 'event_show', [
            'event' => $event,
            'chunks' => EventBriefing::format(Events::toBriefing($event)),
        ], $event['title'], 'events');
    }

    /** @param array<string,mixed> $initial */
    private static function formPage(Request $req, array $org, \Hangar\Viewer $viewer, string $title, array $initial, ?string $eventId): Response
    {
        $fleet = Community::getOrgFleet($org['id'], $viewer);
        $config = [
            'fleet' => array_map(fn ($e) => [
                'catalogItemId' => $e['catalogItemId'], 'name' => $e['name'], 'manufacturer' => $e['manufacturer'],
                'count' => $e['count'], 'career' => $e['specs']['career'],
                'crewMin' => $e['specs']['crewMin'], 'crewMax' => $e['specs']['crewMax'],
            ], $fleet['entries']),
            'members' => Events::listAssignableMembers($org['id'], $viewer),
            'ships' => $initial['ships'],
        ];
        return OrgController::orgPage($org, 'event_form', [
            'heading' => $title, 'eventId' => $eventId, 'initial' => $initial, 'config' => $config,
            'discordBot' => \Hangar\DiscordBot::configured(),
        ], $title, 'events');
    }

    public static function newForm(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        if (!$org['canPlan']) {
            throw HttpException::notFound();
        }
        return self::formPage($req, $org, $viewer, 'Neues Event', ['title' => '', 'description' => '', 'location' => '', 'draft' => false, 'discord' => false, 'discordImported' => false, 'startsAt' => '', 'endsAt' => '', 'ships' => []], null);
    }

    public static function editForm(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        if (!$org['canPlan']) {
            throw HttpException::notFound();
        }
        $e = Events::getEvent($org['id'], $viewer, (string) $p['id']) ?? throw HttpException::notFound();
        return self::formPage($req, $org, $viewer, 'Event bearbeiten', [
            'title' => $e['title'], 'description' => $e['description'] ?? '', 'location' => $e['location'] ?? '', 'draft' => $e['draft'], 'discord' => $e['discordPush'], 'discordImported' => $e['discordOrigin'] === 'IMPORT',
            'startsAt' => EventTime::toInput($e['startsAt']), 'endsAt' => $e['endsAt'] ? EventTime::toInput($e['endsAt']) : '',
            'ships' => array_map(fn ($s) => [
                'catalogItemId' => $s['catalogItemId'], 'customName' => $s['customName'], 'name' => $s['name'], 'task' => $s['task'] ?? '',
                'slots' => array_map(fn ($x) => ['label' => $x['label'], 'userId' => $x['userId'] ?? ''], $s['slots']),
            ], $e['ships']),
        ], $e['id']);
    }

    /** Anlegen oder Ändern; die Eingabe kommt als JSON aus dem Formular (Schiffe und Plätze). */
    public static function save(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        $eventId = ($t = (string) $req->input('eventId', '')) !== '' ? $t : null;
        $back = '/o/' . $org['slug'] . '/events' . ($eventId ? "/$eventId/edit" : '/new');
        $input = json_decode((string) $req->input('payload', ''), true);
        if (!is_array($input)) {
            return Flash::error($back, 'Ungültige Eingabe.');
        }
        $input['draft'] = $req->input('draft') === '1';
        $input['discord'] = $req->input('discord') === '1';
        try {
            $event = Events::save($viewer->id, $org['id'], $input, $eventId);
        } catch (EventError $e) {
            return Flash::error($back, $e->getMessage());
        }
        return Response::redirect('/o/' . $org['slug'] . '/events/' . $event['id']);
    }

    public static function rsvp(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        $to = '/o/' . $org['slug'] . '/events/' . $p['id'];
        try {
            Events::setRsvp($viewer->id, $org['id'], (string) $p['id'], (string) $req->input('status', ''));
        } catch (EventError $e) {
            return Flash::error($to, $e->getMessage());
        }
        return Response::redirect($to);
    }

    public static function slot(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        $to = '/o/' . $org['slug'] . '/events/' . $p['id'];
        $slotId = trim((string) $req->input('slot', ''));
        try {
            Events::claimSlot($viewer->id, $org['id'], (string) $p['id'], $slotId === '' ? null : $slotId);
        } catch (EventError $e) {
            return Flash::error($to, $e->getMessage());
        }
        return Response::redirect($to);
    }

    public static function cancel(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        $to = '/o/' . $org['slug'] . '/events/' . $p['id'];
        try {
            Events::setCancelled($viewer->id, $org['id'], (string) $p['id'], $req->input('cancelled') === '1');
        } catch (EventError $e) {
            return Flash::error($to, $e->getMessage());
        }
        return Response::redirect($to);
    }

    public static function delete(Request $req, array $p): Response
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        try {
            Events::delete($viewer->id, $org['id'], (string) $p['id']);
        } catch (EventError $e) {
            return Flash::error('/o/' . $org['slug'] . '/events/' . $p['id'], $e->getMessage());
        }
        return Response::redirect('/o/' . $org['slug'] . '/events');
    }
}
