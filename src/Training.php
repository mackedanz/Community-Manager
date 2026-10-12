<?php

declare(strict_types=1);

namespace Hangar;

/**
 * Ausbildung einer Orga: sieben feste Bereiche, darin Ausbildungen nach Thema (Reihe) und Stufe.
 * Pflegen dürfen nur Admins und Ausbilder.
 */
final class Training
{
    /** Schlüssel => [Name, Kurzbeschreibung] */
    public const AREAS = [
        'mining' => ['Mining und Salvage', 'Rohstoffe abbauen und Wracks verwerten'],
        'engineering' => ['Engineering und Betankung', 'Technik, Reparatur und Refueling'],
        'transport' => ['Cargo, Passagiere, Daten', 'Frachttransport, Passagierflüge sowie Daten- und Inforunner'],
        'ground' => ['Bodenkampf', 'Einsätze zu Fuß'],
        'space' => ['Raumkampf', 'Einsätze mit Schiffen'],
        'science' => ['Medizin und Forschung', 'Medizin, Wissenschaft und Forschung'],
        'exploration' => ['Pathfinding und Exploration', 'Routen erkunden, Neues entdecken und Wege finden'],
    ];

    public const MAX_LEVEL = 50;

    public static function cleanArea(?string $area): ?string
    {
        return $area !== null && isset(self::AREAS[$area]) ? $area : null;
    }

    /** Sind in der Orga Ausbilder benannt? Erst dann ist die Ausbildung eingerichtet. */
    public static function enabled(string $orgId): bool
    {
        return Orgs::parseRoleIds(Orgs::find($orgId)['instructor_role_ids'] ?? null) !== [];
    }

    /** Darf der Betrachter Ausbildungen pflegen? (Admin oder Ausbilder der Orga) @param array<string,mixed> $org Eintrag aus Viewer::org() */
    public static function canManage(array $org): bool
    {
        return ($org['role'] ?? '') === 'ADMIN' || !empty($org['canInstruct']);
    }

    /**
     * Ausbildungen eines Bereichs nach Thema gruppiert, je Thema nach Stufe.
     * @return array<string,list<array<string,mixed>>>
     */
    public static function listByArea(string $orgId, string $area, ?string $userId = null): array
    {
        $out = [];
        $done = $userId !== null ? self::doneIds($orgId, $userId) : [];
        foreach (Db::all('SELECT id, topic, level, title, content FROM training_courses WHERE org_id = ? AND area = ? ORDER BY topic ASC, level ASC', [$orgId, $area]) as $r) {
            $out[$r['topic']][] = ['id' => $r['id'], 'topic' => $r['topic'], 'level' => (int) $r['level'], 'title' => $r['title'], 'content' => (string) $r['content'], 'done' => isset($done[$r['id']])];
        }
        return $out;
    }

    /**
     * Kommende Termine (nicht abgesagt, keine Entwürfe) zu den Ausbildungen eines Bereichs.
     * @return array<string,list<array{id:string,title:string,startsAt:\DateTimeImmutable}>> Ausbildung => Termine
     */
    public static function upcomingEventsByCourse(string $orgId, string $area, \DateTimeImmutable $now): array
    {
        $out = [];
        foreach (Db::all(
            "SELECT e.id, e.title, e.starts_at, e.course_id FROM events e JOIN training_courses t ON t.id = e.course_id
              WHERE e.org_id = ? AND t.area = ? AND e.status = 'PLANNED' AND (e.starts_at >= ? OR e.ends_at >= ?)
              ORDER BY e.starts_at ASC",
            [$orgId, $area, Time::db($now), Time::db($now)],
        ) as $r) {
            $out[(string) $r['course_id']][] = ['id' => $r['id'], 'title' => $r['title'], 'startsAt' => Time::parse($r['starts_at'])];
        }
        return $out;
    }

    /** @return array<string,int> Bereich => Anzahl der Ausbildungen */
    public static function countsByArea(string $orgId): array
    {
        $out = array_fill_keys(array_keys(self::AREAS), 0);
        foreach (Db::all('SELECT area, COUNT(*) n FROM training_courses WHERE org_id = ? GROUP BY area', [$orgId]) as $r) {
            if (isset($out[$r['area']])) {
                $out[$r['area']] = (int) $r['n'];
            }
        }
        return $out;
    }

    /** @return ?array<string,mixed> */
    public static function find(string $orgId, string $id): ?array
    {
        return Db::one('SELECT * FROM training_courses WHERE org_id = ? AND id = ?', [$orgId, $id]);
    }

    /**
     * Legt eine Ausbildung an oder ändert sie. Die Berechtigung prüft der Aufrufer (canManage).
     * @param array<string,mixed> $in area, topic, level, title, content
     * @throws OrgError
     */
    public static function save(string $orgId, array $in, ?string $id = null): string
    {
        $area = self::cleanArea(isset($in['area']) ? (string) $in['area'] : null) ?? throw new OrgError('Unbekannter Bereich.');
        $topic = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['topic'] ?? '')));
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['title'] ?? '')));
        $content = trim(str_replace("\r\n", "\n", (string) ($in['content'] ?? '')));
        $levelRaw = trim((string) ($in['level'] ?? ''));
        if (mb_strlen($topic) < 2 || mb_strlen($topic) > 80) {
            throw new OrgError('Das Thema braucht 2 bis 80 Zeichen.');
        }
        if (mb_strlen($title) < 2 || mb_strlen($title) > 120) {
            throw new OrgError('Der Titel braucht 2 bis 120 Zeichen.');
        }
        if (preg_match('/^\d{1,2}$/', $levelRaw) !== 1 || (int) $levelRaw < 1 || (int) $levelRaw > self::MAX_LEVEL) {
            throw new OrgError('Die Stufe ist eine Zahl von 1 bis ' . self::MAX_LEVEL . '.');
        }
        if (mb_strlen($content) > 8000) {
            throw new OrgError('Der Inhalt ist zu lang (höchstens 8000 Zeichen).');
        }
        $level = (int) $levelRaw;
        $dup = Db::val('SELECT id FROM training_courses WHERE org_id = ? AND area = ? AND topic = ? AND level = ?', [$orgId, $area, $topic, $level]);
        if ($dup !== null && $dup !== $id) {
            throw new OrgError("Zu „{$topic}“ gibt es in diesem Bereich schon eine Stufe {$level}.");
        }
        if ($id === null) {
            $id = new_id();
            Db::insert('training_courses', ['id' => $id, 'org_id' => $orgId, 'area' => $area, 'topic' => $topic, 'level' => $level, 'title' => $title, 'content' => $content === '' ? null : $content]);
            return $id;
        }
        if (self::find($orgId, $id) === null) {
            throw new OrgError('Ausbildung nicht gefunden.');
        }
        Db::run(
            'UPDATE training_courses SET area = ?, topic = ?, level = ?, title = ?, content = ? WHERE org_id = ? AND id = ?',
            [$area, $topic, $level, $title, $content === '' ? null : $content, $orgId, $id],
        );
        return $id;
    }

    /**
     * Alle Ausbildungen der Orga (für die Auswahl im Termin-Formular), nach Bereich, Thema und Stufe.
     * @return list<array{id:string,area:string,topic:string,level:int,title:string}>
     */
    public static function listAll(string $orgId): array
    {
        $rows = Db::all('SELECT id, area, topic, level, title FROM training_courses WHERE org_id = ? ORDER BY topic ASC, level ASC', [$orgId]);
        $order = array_flip(array_keys(self::AREAS));
        usort($rows, static fn (array $a, array $b): int => ($order[$a['area']] ?? 99) <=> ($order[$b['area']] ?? 99));
        return array_map(static fn (array $r): array => ['id' => $r['id'], 'area' => $r['area'], 'topic' => $r['topic'], 'level' => (int) $r['level'], 'title' => $r['title']], $rows);
    }

    /** @return ?array{id:string,area:string,areaName:string,topic:string,level:int,title:string} */
    public static function course(string $orgId, string $id): ?array
    {
        $r = Db::one('SELECT id, area, topic, level, title FROM training_courses WHERE org_id = ? AND id = ?', [$orgId, $id]);
        if ($r === null) {
            return null;
        }
        return ['id' => $r['id'], 'area' => $r['area'], 'areaName' => self::AREAS[$r['area']][0] ?? $r['area'], 'topic' => $r['topic'], 'level' => (int) $r['level'], 'title' => $r['title']];
    }

    /**
     * Stufen desselben Themas unterhalb der gegebenen Ausbildung, die der Nutzer noch nicht bestanden hat (in Reihenfolge).
     * Leer heißt: Die Voraussetzungen sind erfüllt.
     * @return list<array{level:int,title:string}>
     */
    public static function missingPrerequisites(string $orgId, string $userId, string $courseId): array
    {
        $c = Db::one('SELECT area, topic, level FROM training_courses WHERE org_id = ? AND id = ?', [$orgId, $courseId]);
        if ($c === null) {
            return [];
        }
        $rows = Db::all(
            'SELECT t.level, t.title FROM training_courses t
               LEFT JOIN training_completions d ON d.course_id = t.id AND d.user_id = ?
              WHERE t.org_id = ? AND t.area = ? AND t.topic = ? AND t.level < ? AND d.course_id IS NULL
              ORDER BY t.level ASC',
            [$userId, $orgId, $c['area'], $c['topic'], (int) $c['level']],
        );
        return array_map(static fn (array $r): array => ['level' => (int) $r['level'], 'title' => $r['title']], $rows);
    }

    public static function delete(string $orgId, string $id): void
    {
        Db::run('DELETE FROM training_courses WHERE org_id = ? AND id = ?', [$orgId, $id]);
    }

    /** @return array<string,true> IDs der Ausbildungen dieser Orga, die der Nutzer bestanden hat */
    public static function doneIds(string $orgId, string $userId): array
    {
        $out = [];
        foreach (Db::all(
            'SELECT d.course_id FROM training_completions d JOIN training_courses t ON t.id = d.course_id WHERE d.user_id = ? AND t.org_id = ?',
            [$userId, $orgId],
        ) as $r) {
            $out[(string) $r['course_id']] = true;
        }
        return $out;
    }

    /**
     * Bestandene Ausbildungen eines Nutzers in einer Orga, nach Bereich, Thema und Stufe.
     * @return list<array{area:string,areaName:string,topic:string,level:int,title:string,completedAt:string}>
     */
    public static function qualifications(string $orgId, string $userId): array
    {
        $rows = Db::all(
            'SELECT t.area, t.topic, t.level, t.title, d.completed_at FROM training_completions d
               JOIN training_courses t ON t.id = d.course_id
              WHERE d.user_id = ? AND t.org_id = ? ORDER BY t.topic ASC, t.level ASC',
            [$userId, $orgId],
        );
        $order = array_flip(array_keys(self::AREAS));
        usort($rows, static fn (array $a, array $b): int => ($order[$a['area']] ?? 99) <=> ($order[$b['area']] ?? 99));
        return array_map(static fn (array $r): array => [
            'area' => $r['area'], 'areaName' => self::AREAS[$r['area']][0] ?? $r['area'], 'topic' => $r['topic'],
            'level' => (int) $r['level'], 'title' => $r['title'], 'completedAt' => (string) $r['completed_at'],
        ], $rows);
    }

    /**
     * Teilnehmer eines Ausbildungstermins (Zusage „Dabei“) mit der Angabe, ob sie die Ausbildung schon bestanden haben.
     * @return list<array{userId:string,name:string,passed:bool}>
     */
    public static function participants(string $orgId, string $eventId, string $courseId): array
    {
        $rows = Db::all(
            "SELECT r.user_id, COALESCE(om.nick, u.name) AS name, (d.user_id IS NOT NULL) AS passed
               FROM event_rsvps r JOIN users u ON u.id = r.user_id
               JOIN org_memberships om ON om.user_id = r.user_id AND om.org_id = ?
               LEFT JOIN training_completions d ON d.user_id = r.user_id AND d.course_id = ?
              WHERE r.event_id = ? AND r.status = 'YES'",
            [$orgId, $courseId, $eventId],
        );
        $out = array_map(static fn (array $r): array => ['userId' => (string) $r['user_id'], 'name' => (string) ($r['name'] ?? 'Unbekannt'), 'passed' => (bool) $r['passed']], $rows);
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * Hält fest, wer die Ausbildung eines Termins bestanden hat. Betroffen sind nur die Teilnehmer mit Zusage; wer in
     * $passed steht, gilt als bestanden (mit dem Bestätiger), die übrigen Teilnehmer verlieren einen früheren Eintrag.
     * Die Berechtigung prüft der Aufrufer (canManage).
     * @param list<string> $passed Nutzer-IDs
     * @throws OrgError
     */
    public static function confirm(string $orgId, string $eventId, string $confirmerId, array $passed): void
    {
        $courseId = Db::val('SELECT course_id FROM events WHERE id = ? AND org_id = ?', [$eventId, $orgId]);
        if ($courseId === null) {
            throw new OrgError('Zu diesem Termin gehört keine Ausbildung.');
        }
        $passed = array_flip($passed);
        Db::transaction(static function () use ($orgId, $eventId, $confirmerId, $courseId, $passed): void {
            foreach (self::participants($orgId, $eventId, (string) $courseId) as $p) {
                if (isset($passed[$p['userId']])) {
                    Db::run(
                        'INSERT INTO training_completions (user_id, course_id, confirmed_by, completed_at) VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE user_id = user_id',
                        [$p['userId'], $courseId, $confirmerId, Time::nowDb()],
                    );
                } else {
                    Db::run('DELETE FROM training_completions WHERE user_id = ? AND course_id = ?', [$p['userId'], $courseId]);
                }
            }
        });
    }

    /** Text mit Zeilenumbrüchen und anklickbaren Links (http/https), sonst nur Text. */
    public static function renderContent(string $text): string
    {
        $safe = e($text);
        $linked = preg_replace_callback(
            '#https?://[^\s<]+#i',
            static function (array $m): string {
                $url = rtrim($m[0], '.,;:!?)');
                $rest = substr($m[0], strlen($url));
                return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" class="text-indigo-400 hover:underline">' . $url . '</a>' . $rest;
            },
            $safe,
        );
        return (string) $linked;
    }
}
