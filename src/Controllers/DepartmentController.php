<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Hangar\Auth;
use Hangar\Departments;
use Hangar\Http\Flash;
use Hangar\Http\HttpException;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\OrgError;

/**
 * Abteilungen: Übersicht, Seite je Abteilung mit kommenden Trainings und Übungen, Pflege durch Admins und Abteilungsleiter.
 * Ohne benannte Abteilungsleiter ist der Bereich nicht eingerichtet (die Kachel ist ausgegraut, die Seiten gibt es nicht).
 */
final class DepartmentController extends Controller
{
    /** @return array<string,mixed> der Orga-Eintrag des Betrachters */
    private static function member(Request $req, array $p): array
    {
        [, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        if (!Departments::enabled($org['id'])) {
            throw HttpException::notFound();
        }
        return $org;
    }

    public static function index(Request $req, array $p): Response
    {
        $org = self::member($req, $p);
        $manage = Departments::canManage($org);
        $edit = null;
        if ($manage && (string) $req->query('edit', '') !== '') {
            $edit = Departments::find($org['id'], (string) $req->query('edit'));
        }
        return OrgController::orgPage($org, 'departments_index', [
            'departments' => Departments::list($org['id'], new DateTimeImmutable('now', new DateTimeZone('UTC'))),
            'manage' => $manage,
            'edit' => $edit,
        ], 'Abteilungen', 'departments');
    }

    public static function show(Request $req, array $p): Response
    {
        $org = self::member($req, $p);
        $dept = Departments::find($org['id'], (string) $p['id']) ?? throw HttpException::notFound();
        return OrgController::orgPage($org, 'department_show', [
            'dept' => $dept,
            'events' => Departments::upcomingEvents($org['id'], $dept['id'], new DateTimeImmutable('now', new DateTimeZone('UTC'))),
            'manage' => Departments::canManage($org),
        ], $dept['name'], 'departments');
    }

    public static function save(Request $req, array $p): Response
    {
        $org = self::member($req, $p);
        $to = '/o/' . $org['slug'] . '/departments';
        if (!Departments::canManage($org)) {
            return Flash::error($to, 'Das dürfen nur Admins und Abteilungsleiter.');
        }
        $id = (string) $req->input('id', '');
        try {
            $bytes = null;
            $upload = $req->files['image'] ?? null;
            if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ($upload['error'] !== UPLOAD_ERR_OK) {
                    throw new OrgError('Der Upload ist fehlgeschlagen (Datei zu groß?).');
                }
                $bytes = (string) file_get_contents((string) $upload['tmp_name']);
            }
            $saved = Departments::save($org['id'], [
                'name' => $req->input('name', ''),
                'description' => $req->input('description', ''),
            ], $id === '' ? null : $id, $bytes, (string) $req->input('removeImage', '') === '1');
        } catch (OrgError $e) {
            return Flash::error($to . ($id !== '' ? '?edit=' . rawurlencode($id) : ''), $e->getMessage());
        }
        return Flash::ok($to . '/' . $saved, 'Gespeichert.');
    }

    public static function delete(Request $req, array $p): Response
    {
        $org = self::member($req, $p);
        $to = '/o/' . $org['slug'] . '/departments';
        if (!Departments::canManage($org)) {
            return Flash::error($to, 'Das dürfen nur Admins und Abteilungsleiter.');
        }
        Departments::delete($org['id'], (string) $req->input('id', ''));
        return Flash::ok($to, 'Abteilung gelöscht.');
    }
}
