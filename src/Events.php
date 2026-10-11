<?php

declare(strict_types=1);

namespace Hangar;

use DateTimeImmutable;

/**
 * Eventplanung einer Orga. Lesen darf jedes Mitglied der Orga, anlegen/ändern/löschen nur, wer
 * planen darf (Orga-Admin oder Planer-Rolle, siehe org_memberships.can_plan). Alle Funktionen
 * prüfen die Orga-Zugehörigkeit selbst, damit Events fremder Orgas nie erreichbar sind.
 */
final class Events
{
    public const MAX_SHIPS = 30;
    public const MAX_SLOTS = 20;

    /** @return array<string,mixed> */
    private static function requireMember(string $userId, string $orgId): array
    {
        $m = Db::one('SELECT role, can_plan FROM org_memberships WHERE user_id = ? AND org_id = ?', [$userId, $orgId]);
        if ($m === null) {
            throw new EventError('Kein Mitglied dieser Orga.');
        }
        return $m;
    }

    /** Darf der Betrachter in dieser Orga planen (Admin oder Planer)? Nur sie sehen Entwürfe. */
    private static function canPlanIn(string $orgId, ?Viewer $viewer): bool
    {
        foreach ($viewer?->orgs ?? [] as $o) {
            if ($o['id'] === $orgId) {
                return $o['role'] === 'ADMIN' || $o['canPlan'];
            }
        }
        return false;
    }

    private static function requirePlanner(string $userId, string $orgId): void
    {
        $m = self::requireMember($userId, $orgId);
        if ($m['role'] !== 'ADMIN' && !$m['can_plan']) {
            throw new EventError('Nur Planer und Admins dürfen Events bearbeiten.');
        }
    }

    /** Getrimmter Text mit Höchstlänge; leer wird zu null. */
    private static function text(mixed $v, int $max): ?string
    {
        $s = trim((string) ($v ?? ''));
        if (mb_strlen($s) > $max) {
            throw new EventError('Text zu lang');
        }
        return $s === '' ? null : $s;
    }

    /**
     * Prüft die Eingabe und wandelt sie um (Zeiten nach UTC).
     * @param array<string,mixed> $in
     * @return array{title:string,description:?string,location:?string,startsAt:DateTimeImmutable,endsAt:?DateTimeImmutable,ships:list<array{catalogItemId:?string,customName:?string,task:?string,slots:list<array{label:string,userId:?string}>}>}
     */
    public static function validate(array $in): array
    {
        $title = trim((string) ($in['title'] ?? ''));
        if (mb_strlen($title) < 2) {
            throw new EventError('Titel zu kurz');
        }
        if (mb_strlen($title) > 100) {
            throw new EventError('Titel zu lang');
        }
        $startsAt = EventTime::parseBerlinLocal((string) ($in['startsAt'] ?? ''));
        if ($startsAt === null) {
            throw new EventError('Bitte einen gültigen Beginn angeben.');
        }
        $endsAt = null;
        $endRaw = trim((string) ($in['endsAt'] ?? ''));
        if ($endRaw !== '') {
            $endsAt = EventTime::parseBerlinLocal($endRaw);
            if ($endsAt === null) {
                throw new EventError('Das Ende ist ungültig.');
            }
            if ($endsAt <= $startsAt) {
                throw new EventError('Das Ende muss nach dem Beginn liegen.');
            }
        }
        $shipsIn = is_array($in['ships'] ?? null) ? array_values($in['ships']) : [];
        if (count($shipsIn) > self::MAX_SHIPS) {
            throw new EventError('Höchstens ' . self::MAX_SHIPS . ' Schiffe');
        }
        $ships = [];
        foreach ($shipsIn as $s) {
            $s = is_array($s) ? $s : [];
            $catalogId = isset($s['catalogItemId']) && $s['catalogItemId'] !== '' ? (string) $s['catalogItemId'] : null;
            $custom = trim((string) ($s['customName'] ?? ''));
            if (mb_strlen($custom) > 80) {
                throw new EventError('Name zu lang');
            }
            if ($catalogId === null && $custom === '') {
                throw new EventError('Schiff ohne Namen');
            }
            $slotsIn = is_array($s['slots'] ?? null) ? array_values($s['slots']) : [];
            if (count($slotsIn) > self::MAX_SLOTS) {
                throw new EventError('Höchstens ' . self::MAX_SLOTS . ' Plätze pro Schiff');
            }
            $slots = [];
            foreach ($slotsIn as $slot) {
                $slot = is_array($slot) ? $slot : [];
                $label = trim((string) ($slot['label'] ?? ''));
                if ($label === '' || mb_strlen($label) > 40) {
                    throw new EventError('Jeder Platz braucht eine Bezeichnung (höchstens 40 Zeichen)');
                }
                $uid = isset($slot['userId']) && $slot['userId'] !== '' ? (string) $slot['userId'] : null;
                $slots[] = ['label' => $label, 'userId' => $uid];
            }
            $task = self::text($s['task'] ?? null, 80);
            $ships[] = ['catalogItemId' => $catalogId, 'customName' => $catalogId !== null ? null : ($custom !== '' ? $custom : null), 'task' => $task, 'slots' => $slots];
        }
        return [
            'title' => $title,
            'description' => self::text($in['description'] ?? null, 3000),
            'location' => self::text($in['location'] ?? null, 100),
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'ships' => $ships,
        ];
    }

    /**
     * Legt ein Event an (eventId leer) oder ersetzt Daten, Schiffe und Plätze eines bestehenden.
     * @param array<string,mixed> $input
     * @return array<string,mixed> das gespeicherte Event
     */
    public static function save(string $userId, string $orgId, array $input, ?string $eventId = null): array
    {
        self::requirePlanner($userId, $orgId);
        $data = self::validate($input);

        if ($eventId !== null && Db::val('SELECT 1 FROM events WHERE id = ? AND org_id = ?', [$eventId, $orgId]) === null) {
            throw new EventError('Event nicht gefunden.');
        }

        // Zugeordnete Personen müssen Mitglieder dieser Orga sein.
        $userIds = [];
        $catalogIds = [];
        foreach ($data['ships'] as $s) {
            foreach ($s['slots'] as $slot) {
                if ($slot['userId'] !== null) {
                    $userIds[$slot['userId']] = true;
                }
            }
            if ($s['catalogItemId'] !== null) {
                $catalogIds[$s['catalogItemId']] = true;
            }
        }
        if ($userIds !== []) {
            $ids = array_keys($userIds);
            $ok = (int) Db::val('SELECT COUNT(*) FROM org_memberships WHERE org_id = ? AND user_id IN (' . Db::in($ids) . ')', [$orgId, ...$ids]);
            if ($ok !== count($ids)) {
                throw new EventError('Ein zugeordnetes Mitglied gehört nicht zur Orga.');
            }
        }
        if ($catalogIds !== []) {
            $ids = array_keys($catalogIds);
            $ok = (int) Db::val("SELECT COUNT(*) FROM catalog_items WHERE kind = 'SHIP' AND id IN (" . Db::in($ids) . ')', $ids);
            if ($ok !== count($ids)) {
                throw new EventError('Ein gewähltes Schiff gibt es nicht im Katalog.');
            }
        }

        $draft = !empty($input['draft']);
        $wantPush = !empty($input['discord']);
        return Db::transaction(function () use ($data, $orgId, $userId, $eventId, $draft, $wantPush): array {
            $fields = [
                'title' => $data['title'], 'description' => $data['description'], 'location' => $data['location'],
                'starts_at' => Time::db($data['startsAt']), 'ends_at' => $data['endsAt'] ? Time::db($data['endsAt']) : null,
            ];
            $wasPushed = false;
            if ($eventId === null) {
                $eventId = new_id();
                Db::insert('events', ['id' => $eventId, 'org_id' => $orgId, 'created_by_id' => $userId, 'status' => $draft ? 'DRAFT' : 'PLANNED'] + $fields);
            } else {
                // Ein aus Discord übernommener Termin, dessen Eckdaten ein Planer ändert, wird vom Abgleich nicht mehr überschrieben.
                $old = Db::one('SELECT title, description, location, starts_at, ends_at, discord_event_id, discord_push FROM events WHERE id = ?', [$eventId]);
                $wasPushed = $old !== null && ($old['discord_push'] || $old['discord_event_id'] !== null);
                if ($old !== null && $old['discord_event_id'] !== null) {
                    foreach (['title', 'description', 'location', 'starts_at', 'ends_at'] as $k) {
                        if ((string) $old[$k] !== (string) $fields[$k]) {
                            Db::run('UPDATE events SET discord_edited = 1 WHERE id = ?', [$eventId]);
                            break;
                        }
                    }
                }
                Db::run(
                    'UPDATE events SET title = ?, description = ?, location = ?, starts_at = ?, ends_at = ? WHERE id = ?',
                    [$fields['title'], $fields['description'], $fields['location'], $fields['starts_at'], $fields['ends_at'], $eventId],
                );
                // Entwurf an/aus; ein abgesagtes Event bleibt abgesagt, außer es wird zum Entwurf gemacht.
                Db::run("UPDATE events SET status = ? WHERE id = ? AND (status <> 'CANCELLED' OR ?)", [$draft ? 'DRAFT' : 'PLANNED', $eventId, $draft ? 1 : 0]);
                Db::run('DELETE FROM event_ships WHERE event_id = ?', [$eventId]);
            }
            // Haken „Auch in Discord anlegen“: der Cron-Lauf schreibt es nach Discord (aus Discord übernommene Termine ausgenommen)
            if ($wantPush || $wasPushed) {
                Db::run("UPDATE events SET discord_push = ?, discord_dirty = 1 WHERE id = ? AND (discord_origin IS NULL OR discord_origin = 'PUSH')", [$wantPush ? 1 : 0, $eventId]);
            }
            foreach ($data['ships'] as $i => $s) {
                $shipId = new_id();
                Db::insert('event_ships', [
                    'id' => $shipId, 'event_id' => $eventId, 'catalog_item_id' => $s['catalogItemId'],
                    'custom_name' => $s['customName'], 'task' => $s['task'], 'sort' => $i,
                ]);
                foreach ($s['slots'] as $j => $slot) {
                    Db::insert('event_slots', ['id' => new_id(), 'event_ship_id' => $shipId, 'label' => $slot['label'], 'user_id' => $slot['userId'], 'sort' => $j]);
                }
            }
            return Db::one('SELECT * FROM events WHERE id = ?', [$eventId]) ?? [];
        });
    }

    public static function setCancelled(string $userId, string $orgId, string $eventId, bool $cancelled): void
    {
        self::requirePlanner($userId, $orgId);
        if (Db::val('SELECT 1 FROM events WHERE id = ? AND org_id = ?', [$eventId, $orgId]) === null) {
            throw new EventError('Event nicht gefunden.');
        }
        Db::run('UPDATE events SET status = ? WHERE id = ? AND org_id = ?', [$cancelled ? 'CANCELLED' : 'PLANNED', $eventId, $orgId]);
        Db::run('UPDATE events SET discord_dirty = 1 WHERE id = ? AND discord_push = 1', [$eventId]);
    }

    public static function delete(string $userId, string $orgId, string $eventId): void
    {
        self::requirePlanner($userId, $orgId);
        // Ein vom Hangar angelegtes Discord-Event wird vom Cron-Lauf entfernt
        $d = Db::one("SELECT discord_event_id FROM events WHERE id = ? AND org_id = ? AND discord_origin = 'PUSH'", [$eventId, $orgId]);
        if ($d !== null && $d['discord_event_id'] !== null) {
            Db::insert('discord_event_deletions', ['id' => new_id(), 'org_id' => $orgId, 'discord_event_id' => $d['discord_event_id']]);
        }
        if (Db::exec('DELETE FROM events WHERE id = ? AND org_id = ?', [$eventId, $orgId]) === 0) {
            throw new EventError('Event nicht gefunden.');
        }
    }

    /** Zu-/Absage des angemeldeten Mitglieds; "NONE" entfernt die Antwort. */
    public static function setRsvp(string $userId, string $orgId, string $eventId, string $status): void
    {
        $m = self::requireMember($userId, $orgId);
        $st = Db::val('SELECT status FROM events WHERE id = ? AND org_id = ?', [$eventId, $orgId]);
        if ($st === null || ($st === 'DRAFT' && $m['role'] !== 'ADMIN' && !$m['can_plan'])) {
            throw new EventError('Event nicht gefunden.');
        }
        if ($status === 'NONE') {
            Db::run('DELETE FROM event_rsvps WHERE event_id = ? AND user_id = ?', [$eventId, $userId]);
            self::releaseSlots($userId, $eventId);
            return;
        }
        if (!in_array($status, Constants::RSVP_STATUSES, true)) {
            throw new EventError('Ungültige Antwort.');
        }
        Db::run(
            'INSERT INTO event_rsvps (event_id, user_id, status) VALUES (?,?,?) ON DUPLICATE KEY UPDATE status = VALUES(status)',
            [$eventId, $userId, $status],
        );
        if ($status !== 'YES') {
            self::releaseSlots($userId, $eventId);
        }
    }

    private static function releaseSlots(string $userId, string $eventId): void
    {
        Db::run(
            'UPDATE event_slots x JOIN event_ships s ON s.id = x.event_ship_id SET x.user_id = NULL WHERE s.event_id = ? AND x.user_id = ?',
            [$eventId, $userId],
        );
    }

    /**
     * Trägt das Mitglied in einen offenen Platz ein (nur mit Zusage "Dabei"). Ein früherer Platz im selben Event wird frei.
     * $slotId = null gibt den eigenen Platz frei.
     */
    public static function claimSlot(string $userId, string $orgId, string $eventId, ?string $slotId): void
    {
        $m = self::requireMember($userId, $orgId);
        $ev = Db::one('SELECT status FROM events WHERE id = ? AND org_id = ?', [$eventId, $orgId]);
        if ($ev === null || ($ev['status'] === 'DRAFT' && $m['role'] !== 'ADMIN' && !$m['can_plan'])) {
            throw new EventError('Event nicht gefunden.');
        }
        if ($slotId === null) {
            self::releaseSlots($userId, $eventId);
            return;
        }
        if ($ev['status'] === 'CANCELLED') {
            throw new EventError('Das Event ist abgesagt.');
        }
        if (Db::val("SELECT 1 FROM event_rsvps WHERE event_id = ? AND user_id = ? AND status = 'YES'", [$eventId, $userId]) === null) {
            throw new EventError('Sage zuerst mit „Dabei“ zu.');
        }
        Db::transaction(function () use ($userId, $eventId, $slotId): void {
            $ok = Db::val('SELECT 1 FROM event_slots x JOIN event_ships s ON s.id = x.event_ship_id WHERE x.id = ? AND s.event_id = ?', [$slotId, $eventId]);
            if ($ok === null) {
                throw new EventError('Platz nicht gefunden.');
            }
            self::releaseSlots($userId, $eventId);
            if (Db::exec('UPDATE event_slots SET user_id = ? WHERE id = ? AND user_id IS NULL', [$userId, $slotId]) === 0) {
                throw new EventError('Der Platz ist schon vergeben.');
            }
        });
    }

    // ---------------------------------------------------------------------------------------
    // Lesen
    // ---------------------------------------------------------------------------------------

    private static function isMemberOf(string $orgId, ?Viewer $viewer): bool
    {
        return $viewer !== null && in_array($orgId, $viewer->orgIds(), true);
    }

    /**
     * Events einer Orga im Zeitraum [from, to).
     * @return list<array{id:string,title:string,startsAt:DateTimeImmutable,endsAt:?DateTimeImmutable,cancelled:bool,shipCount:int,yes:int}>
     */
    public static function listEvents(string $orgId, ?Viewer $viewer, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if (!self::isMemberOf($orgId, $viewer)) {
            return [];
        }
        $rows = Db::all(
            "SELECT e.id, e.title, e.starts_at, e.ends_at, e.status,
                    (SELECT COUNT(*) FROM event_ships s WHERE s.event_id = e.id) AS ship_count,
                    (SELECT COUNT(*) FROM event_rsvps r WHERE r.event_id = e.id AND r.status = 'YES') AS yes_count
               FROM events e WHERE e.org_id = ? AND e.starts_at >= ? AND e.starts_at < ?" . (self::canPlanIn($orgId, $viewer) ? '' : " AND e.status <> 'DRAFT'") . " ORDER BY e.starts_at ASC",
            [$orgId, Time::db($from), Time::db($to)],
        );
        return array_map(fn ($e) => [
            'id' => $e['id'], 'title' => $e['title'], 'startsAt' => Time::parse($e['starts_at']), 'endsAt' => Time::parse($e['ends_at']),
            'draft' => $e['status'] === 'DRAFT',
            'cancelled' => $e['status'] === 'CANCELLED', 'shipCount' => (int) $e['ship_count'], 'yes' => (int) $e['yes_count'],
        ], $rows);
    }

    /**
     * Events für die Zeitleiste: ab $from für $months Monate, mit Ersteller (inkl. abgesagter).
     * @return list<array{id:string,title:string,startsAt:DateTimeImmutable,endsAt:?DateTimeImmutable,cancelled:bool,shipCount:int,yes:int,by:string}>
     */
    public static function listTimeline(string $orgId, ?Viewer $viewer, DateTimeImmutable $from, int $months = 12): array
    {
        if (!self::isMemberOf($orgId, $viewer)) {
            return [];
        }
        $to = $from->modify('+' . $months . ' months');
        $rows = Db::all(
            "SELECT e.id, e.title, e.starts_at, e.ends_at, e.status, COALESCE(om.nick, u.name) AS creator,
                    (SELECT COUNT(*) FROM event_ships s WHERE s.event_id = e.id) AS ship_count,
                    (SELECT COUNT(*) FROM event_rsvps r WHERE r.event_id = e.id AND r.status = 'YES') AS yes_count
               FROM events e LEFT JOIN users u ON u.id = e.created_by_id
               LEFT JOIN org_memberships om ON om.user_id = e.created_by_id AND om.org_id = e.org_id
              WHERE e.org_id = ? AND e.starts_at >= ? AND e.starts_at < ?" . (self::canPlanIn($orgId, $viewer) ? '' : " AND e.status <> 'DRAFT'") . " ORDER BY e.starts_at ASC",
            [$orgId, Time::db($from), Time::db($to)],
        );
        return array_map(fn ($e) => [
            'id' => $e['id'], 'title' => $e['title'], 'startsAt' => Time::parse($e['starts_at']), 'endsAt' => Time::parse($e['ends_at']),
            'draft' => $e['status'] === 'DRAFT',
            'cancelled' => $e['status'] === 'CANCELLED', 'shipCount' => (int) $e['ship_count'], 'yes' => (int) $e['yes_count'],
            'by' => (string) ($e['creator'] ?? 'Unbekannt'),
        ], $rows);
    }

    /**
     * Die nächsten anstehenden Events (ohne abgesagte).
     * @return list<array{id:string,title:string,startsAt:DateTimeImmutable,endsAt:?DateTimeImmutable}>
     */
    public static function listUpcoming(string $orgId, ?Viewer $viewer, ?DateTimeImmutable $now = null, int $take = 10): array
    {
        if (!self::isMemberOf($orgId, $viewer)) {
            return [];
        }
        $n = Time::db($now ?? Time::now());
        $rows = Db::all(
            "SELECT id, title, starts_at, ends_at FROM events
              WHERE org_id = ? AND status = 'PLANNED' AND (starts_at >= ? OR ends_at >= ?)
              ORDER BY starts_at ASC LIMIT $take",
            [$orgId, $n, $n],
        );
        return array_map(fn ($e) => ['id' => $e['id'], 'title' => $e['title'], 'startsAt' => Time::parse($e['starts_at']), 'endsAt' => Time::parse($e['ends_at'])], $rows);
    }

    /** @return array<string,mixed>|null */
    public static function getEvent(string $orgId, ?Viewer $viewer, string $eventId): ?array
    {
        if (!self::isMemberOf($orgId, $viewer)) {
            return null;
        }
        $e = Db::one('SELECT * FROM events WHERE id = ? AND org_id = ?', [$eventId, $orgId]);
        if ($e === null || ($e['status'] === 'DRAFT' && !self::canPlanIn($orgId, $viewer))) {
            return null;
        }
        $shipRows = Db::all(
            'SELECT s.id, s.catalog_item_id, s.custom_name, s.task, c.kind AS c_kind, c.slug AS c_slug, c.name AS c_name
               FROM event_ships s LEFT JOIN catalog_items c ON c.id = s.catalog_item_id
              WHERE s.event_id = ? ORDER BY s.sort ASC',
            [$eventId],
        );
        $slotsByShip = [];
        if ($shipRows !== []) {
            $ids = array_column($shipRows, 'id');
            foreach (Db::all(
                'SELECT x.id, x.event_ship_id, x.label, x.user_id, COALESCE(om.nick, u.name) AS user_name
                   FROM event_slots x LEFT JOIN users u ON u.id = x.user_id
                   LEFT JOIN org_memberships om ON om.user_id = x.user_id AND om.org_id = ?
                  WHERE x.event_ship_id IN (' . Db::in($ids) . ') ORDER BY x.sort ASC',
                [$orgId, ...$ids],
            ) as $x) {
                $slotsByShip[$x['event_ship_id']][] = ['id' => $x['id'], 'label' => $x['label'], 'userId' => $x['user_id'], 'userName' => $x['user_name']];
            }
        }
        $rsvps = array_map(fn ($r) => ['userId' => $r['user_id'], 'name' => $r['name'] ?? 'Unbekannt', 'status' => $r['status']], Db::all(
            'SELECT r.user_id, r.status, COALESCE(om.nick, u.name) AS name FROM event_rsvps r JOIN users u ON u.id = r.user_id
               LEFT JOIN org_memberships om ON om.user_id = r.user_id AND om.org_id = ? WHERE r.event_id = ?',
            [$orgId, $eventId],
        ));
        usort($rsvps, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        $mine = null;
        foreach ($rsvps as $r) {
            if ($r['userId'] === $viewer->id) {
                $mine = $r['status'];
            }
        }
        return [
            'id' => $e['id'], 'title' => $e['title'], 'description' => $e['description'], 'location' => $e['location'],
            'startsAt' => Time::parse($e['starts_at']), 'endsAt' => Time::parse($e['ends_at']),
            'cancelled' => $e['status'] === 'CANCELLED',
            'draft' => $e['status'] === 'DRAFT',
            'discordPush' => (bool) $e['discord_push'],
            'discordOrigin' => $e['discord_origin'],
            'discordError' => $e['discord_error'],
            'discordUrl' => $e['discord_event_id'] !== null
                ? 'https://discord.com/events/' . rawurlencode((string) Db::val('SELECT discord_guild_id FROM organizations WHERE id = ?', [$orgId])) . '/' . rawurlencode($e['discord_event_id'])
                : null,
            'ships' => array_map(fn ($s) => [
                'id' => $s['id'], 'catalogItemId' => $s['catalog_item_id'], 'customName' => $s['custom_name'],
                'name' => $s['c_name'] ?? $s['custom_name'] ?? 'Unbekanntes Schiff',
                'href' => $s['c_kind'] !== null ? '/catalog/' . strtolower($s['c_kind']) . '/' . rawurlencode($s['c_slug']) : null,
                'imageUrl' => $s['c_kind'] !== null ? Community::imageSrc($s['c_kind'], $s['c_slug'], null) : null,
                'task' => $s['task'],
                'slots' => $slotsByShip[$s['id']] ?? [],
            ], $shipRows),
            'rsvps' => $rsvps,
            'myRsvp' => $mine,
            'viewerId' => $viewer->id,
        ];
    }

    /** @param array<string,mixed> $e @return array<string,mixed> */
    public static function toBriefing(array $e): array
    {
        $names = fn (string $status) => array_values(array_map(fn ($r) => $r['name'], array_filter($e['rsvps'], fn ($r) => $r['status'] === $status)));
        return [
            'title' => $e['title'], 'description' => $e['description'], 'location' => $e['location'],
            'startsAt' => $e['startsAt'], 'endsAt' => $e['endsAt'], 'cancelled' => $e['cancelled'],
            'ships' => array_map(fn ($s) => [
                'name' => $s['name'], 'task' => $s['task'],
                'slots' => array_map(fn ($x) => ['label' => $x['label'], 'userName' => $x['userName']], $s['slots']),
            ], $e['ships']),
            'yes' => $names('YES'),
            'maybe' => $names('MAYBE'),
        ];
    }

    /** Mitglieder der Orga für die Platzvergabe (nur Name, für Planer). @return list<array{id:string,name:string}> */
    public static function listAssignableMembers(string $orgId, ?Viewer $viewer): array
    {
        if (!self::isMemberOf($orgId, $viewer)) {
            return [];
        }
        $rows = Db::all('SELECT u.id, COALESCE(m.nick, u.name) AS name FROM org_memberships m JOIN users u ON u.id = m.user_id WHERE m.org_id = ?', [$orgId]);
        $out = array_map(fn ($r) => ['id' => $r['id'], 'name' => $r['name'] ?? 'Unbekannt'], $rows);
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }
}
