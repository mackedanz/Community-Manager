<?php

declare(strict_types=1);

namespace Hangar;

use DateTimeImmutable;

/**
 * Abteilungen einer Orga mit Trainings und Übungen. Eine Abteilung hat Name und Beschreibung; Trainings und Übungen
 * sind Termine im Kalender, die einer Abteilung zugeordnet sind. Pflegen dürfen nur Admins und Abteilungsleiter.
 */
final class Departments
{
    /** Schlüssel in events.department_kind => Name */
    public const KINDS = ['TRAINING' => 'Training', 'EXERCISE' => 'Übung'];

    public static function cleanKind(?string $kind): ?string
    {
        return $kind !== null && isset(self::KINDS[$kind]) ? $kind : null;
    }

    /** Darf der Betrachter Abteilungen pflegen? (Admin oder Abteilungsleiter der Orga) @param array<string,mixed> $org Eintrag aus Viewer::org() */
    public static function canManage(array $org): bool
    {
        return ($org['role'] ?? '') === 'ADMIN' || !empty($org['canLead']);
    }

    /** Sind in der Orga Abteilungsleiter benannt? Erst dann ist der Bereich eingerichtet. */
    public static function enabled(string $orgId): bool
    {
        return Orgs::parseRoleIds(Orgs::find($orgId)['lead_role_ids'] ?? null) !== [];
    }

    /**
     * Alle Abteilungen mit der Zahl der kommenden Termine.
     * @return list<array{id:string,name:string,description:string,upcoming:int}>
     */
    public static function list(string $orgId, DateTimeImmutable $now): array
    {
        $rows = Db::all(
            "SELECT d.id, d.name, d.description,
                    (SELECT COUNT(*) FROM events e WHERE e.department_id = d.id AND e.starts_at >= ? AND e.status = 'PLANNED') AS upcoming
               FROM departments d WHERE d.org_id = ? ORDER BY d.name ASC",
            [Time::db($now), $orgId],
        );
        return array_map(static fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'description' => (string) $r['description'], 'upcoming' => (int) $r['upcoming'],
        ], $rows);
    }

    public static function count(string $orgId): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM departments WHERE org_id = ?', [$orgId]);
    }

    /** @return ?array{id:string,name:string,description:string} */
    public static function find(string $orgId, string $id): ?array
    {
        $r = Db::one('SELECT id, name, description FROM departments WHERE org_id = ? AND id = ?', [$orgId, $id]);
        return $r === null ? null : ['id' => $r['id'], 'name' => $r['name'], 'description' => (string) $r['description']];
    }

    /**
     * Kommende Termine einer Abteilung (ohne abgesagte und Entwürfe).
     * @return list<array{id:string,title:string,startsAt:DateTimeImmutable,kind:?string,kindName:string}>
     */
    public static function upcomingEvents(string $orgId, string $departmentId, DateTimeImmutable $now, int $limit = 50): array
    {
        $rows = Db::all(
            "SELECT id, title, starts_at, department_kind FROM events
              WHERE org_id = ? AND department_id = ? AND starts_at >= ? AND status = 'PLANNED'
              ORDER BY starts_at ASC LIMIT " . max(1, $limit),
            [$orgId, $departmentId, Time::db($now)],
        );
        return array_map(static fn (array $r): array => [
            'id' => $r['id'], 'title' => $r['title'], 'startsAt' => Time::parse($r['starts_at']),
            'kind' => $r['department_kind'], 'kindName' => self::KINDS[$r['department_kind']] ?? 'Termin',
        ], $rows);
    }

    /**
     * Alle Abteilungen für die Auswahl im Termin-Formular.
     * @return list<array{id:string,name:string}>
     */
    public static function options(string $orgId): array
    {
        return array_map(
            static fn (array $r): array => ['id' => $r['id'], 'name' => $r['name']],
            Db::all('SELECT id, name FROM departments WHERE org_id = ? ORDER BY name ASC', [$orgId]),
        );
    }

    /**
     * Abteilung einer Orga für die Anzeige am Termin.
     * @return ?array{id:string,name:string}
     */
    public static function summary(string $orgId, string $id): ?array
    {
        $r = Db::one('SELECT id, name FROM departments WHERE org_id = ? AND id = ?', [$orgId, $id]);
        return $r === null ? null : ['id' => $r['id'], 'name' => $r['name']];
    }

    /**
     * Legt eine Abteilung an oder ändert sie. Die Berechtigung prüft der Aufrufer (canManage).
     * @param array<string,mixed> $in name, description
     * @throws OrgError
     */
    public static function save(string $orgId, array $in, ?string $id = null): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')));
        $description = trim(str_replace("\r\n", "\n", (string) ($in['description'] ?? '')));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            throw new OrgError('Der Name braucht 2 bis 80 Zeichen.');
        }
        if (mb_strlen($description) > 8000) {
            throw new OrgError('Die Beschreibung ist zu lang (höchstens 8000 Zeichen).');
        }
        $dup = Db::val('SELECT id FROM departments WHERE org_id = ? AND name = ?', [$orgId, $name]);
        if ($dup !== null && $dup !== $id) {
            throw new OrgError("Es gibt schon eine Abteilung „{$name}“.");
        }
        if ($id === null) {
            $id = new_id();
            Db::insert('departments', ['id' => $id, 'org_id' => $orgId, 'name' => $name, 'description' => $description === '' ? null : $description]);
            return $id;
        }
        if (self::find($orgId, $id) === null) {
            throw new OrgError('Abteilung nicht gefunden.');
        }
        Db::run('UPDATE departments SET name = ?, description = ? WHERE org_id = ? AND id = ?', [$name, $description === '' ? null : $description, $orgId, $id]);
        return $id;
    }

    public static function delete(string $orgId, string $id): void
    {
        Db::run('DELETE FROM departments WHERE org_id = ? AND id = ?', [$orgId, $id]);
    }
}
