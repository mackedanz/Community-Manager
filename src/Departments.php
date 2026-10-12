<?php

declare(strict_types=1);

namespace Hangar;

use DateTimeImmutable;
use Hangar\Images\ShipImages;

/**
 * Abteilungen einer Orga mit Trainings und Übungen. Eine Abteilung hat Name und Beschreibung; Trainings und Übungen
 * sind Termine im Kalender, die einer Abteilung zugeordnet sind. Pflegen dürfen nur Admins und Abteilungsleiter.
 */
final class Departments
{
    /** Schlüssel in events.department_kind => Name */
    public const KINDS = ['TRAINING' => 'Training', 'EXERCISE' => 'Übung'];

    private const IMAGE_FILE = '/^dept-[a-f0-9]{16}\.png$/';

    private static function imageDir(): string
    {
        return Config::imageDir() . '/departments';
    }

    /** Pfad einer gespeicherten Abteilungsbild-Datei (nur gültige Namen, nur vorhandene). */
    public static function imagePath(string $file): ?string
    {
        if (preg_match(self::IMAGE_FILE, $file) !== 1) {
            return null;
        }
        $p = self::imageDir() . '/' . $file;
        return is_file($p) ? $p : null;
    }

    public static function imageUrl(?string $file): ?string
    {
        return $file === null || $file === '' ? null : '/img/dept/' . $file;
    }

    /** Prüft das Bild, kodiert es als PNG (höchstens 256 Pixel) neu und legt es unter seinem Hash ab. @throws OrgError */
    private static function storeImage(string $bytes): string
    {
        $img = Branding::process('logo', $bytes);
        $name = 'dept-' . substr(sha1($img['body']), 0, 16);
        $dir = self::imageDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new OrgError('Der Bildordner ist nicht beschreibbar.');
        }
        if (ShipImages::storeIn($dir, $name, $img['body'], 'png') === null) {
            throw new OrgError('Das Bild konnte nicht gespeichert werden.');
        }
        return $name . '.png';
    }

    /** Löscht die Datei, wenn keine Abteilung sie mehr braucht. */
    private static function dropImage(?string $file): void
    {
        if ($file !== null && Db::val('SELECT 1 FROM departments WHERE image = ?', [$file]) === null && ($p = self::imagePath($file)) !== null) {
            @unlink($p);
        }
    }

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
     * @return list<array{id:string,name:string,description:string,image:?string,upcoming:int}>
     */
    public static function list(string $orgId, DateTimeImmutable $now): array
    {
        $rows = Db::all(
            "SELECT d.id, d.name, d.description, d.image,
                    (SELECT COUNT(*) FROM events e WHERE e.department_id = d.id AND e.starts_at >= ? AND e.status = 'PLANNED') AS upcoming
               FROM departments d WHERE d.org_id = ? ORDER BY d.name ASC",
            [Time::db($now), $orgId],
        );
        return array_map(static fn (array $r): array => [
            'id' => $r['id'], 'name' => $r['name'], 'description' => (string) $r['description'], 'image' => self::imageUrl($r['image']), 'upcoming' => (int) $r['upcoming'],
        ], $rows);
    }

    public static function count(string $orgId): int
    {
        return (int) Db::val('SELECT COUNT(*) FROM departments WHERE org_id = ?', [$orgId]);
    }

    /** @return ?array{id:string,name:string,description:string,image:?string} image ist die Bildadresse */
    public static function find(string $orgId, string $id): ?array
    {
        $r = Db::one('SELECT id, name, description, image FROM departments WHERE org_id = ? AND id = ?', [$orgId, $id]);
        return $r === null ? null : ['id' => $r['id'], 'name' => $r['name'], 'description' => (string) $r['description'], 'image' => self::imageUrl($r['image'])];
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
     * @param ?string $imageBytes Inhalt eines hochgeladenen Bildes (ersetzt das bisherige)
     * @param bool $removeImage das bisherige Bild entfernen (gilt nicht, wenn ein neues kommt)
     * @throws OrgError
     */
    public static function save(string $orgId, array $in, ?string $id = null, ?string $imageBytes = null, bool $removeImage = false): string
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
        if ($id !== null && self::find($orgId, $id) === null) {
            throw new OrgError('Abteilung nicht gefunden.');
        }
        $file = $imageBytes !== null ? self::storeImage($imageBytes) : null;
        if ($id === null) {
            $id = new_id();
            Db::insert('departments', ['id' => $id, 'org_id' => $orgId, 'name' => $name, 'description' => $description === '' ? null : $description, 'image' => $file]);
            return $id;
        }
        $old = Db::val('SELECT image FROM departments WHERE org_id = ? AND id = ?', [$orgId, $id]);
        $image = $file ?? ($removeImage ? null : $old);
        Db::run('UPDATE departments SET name = ?, description = ?, image = ? WHERE org_id = ? AND id = ?', [$name, $description === '' ? null : $description, $image, $orgId, $id]);
        if ($old !== $image) {
            self::dropImage(is_string($old) ? $old : null);
        }
        return $id;
    }

    public static function delete(string $orgId, string $id): void
    {
        $old = Db::val('SELECT image FROM departments WHERE org_id = ? AND id = ?', [$orgId, $id]);
        Db::run('DELETE FROM departments WHERE org_id = ? AND id = ?', [$orgId, $id]);
        self::dropImage(is_string($old) ? $old : null);
    }
}
