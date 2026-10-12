<?php

declare(strict_types=1);

namespace Hangar\Controllers;

use Hangar\Auth;
use Hangar\Http\Flash;
use Hangar\Http\HttpException;
use Hangar\Http\Request;
use Hangar\Http\Response;
use Hangar\OrgError;
use Hangar\Training;

/** Ausbildung: Übersicht der sechs Bereiche, Seite je Bereich, Pflege der Ausbildungen (Admins und Ausbilder). */
final class TrainingController extends Controller
{
    /** Mitglied der Orga; ohne benannte Ausbilder ist die Ausbildung nicht eingerichtet (404, wie die Kachel ausgegraut). @return array{0:\Hangar\Viewer,1:array<string,mixed>} */
    private static function member(Request $req, array $p): array
    {
        [$viewer, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        if (!Training::enabled($org['id'])) {
            throw HttpException::notFound();
        }
        return [$viewer, $org];
    }

    public static function index(Request $req, array $p): Response
    {
        [, $org] = self::member($req, $p);
        return OrgController::orgPage($org, 'training_index', [
            'counts' => Training::countsByArea($org['id']),
        ], 'Ausbildung', 'training');
    }

    public static function area(Request $req, array $p): Response
    {
        [$viewer, $org] = self::member($req, $p);
        $area = Training::cleanArea((string) $p['area']) ?? throw HttpException::notFound();
        $manage = Training::canManage($org);
        $edit = null;
        if ($manage && (string) $req->query('edit', '') !== '') {
            $edit = Training::find($org['id'], (string) $req->query('edit'));
        }
        $topics = Training::listByArea($org['id'], $area, $viewer->id);
        return OrgController::orgPage($org, 'training_area', [
            'area' => $area,
            'topics' => $topics,
            'manage' => $manage,
            'edit' => $edit,
            'events' => Training::upcomingEventsByCourse($org['id'], $area, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))),
        ], Training::AREAS[$area][0], 'training');
    }

    public static function save(Request $req, array $p): Response
    {
        [, $org] = self::member($req, $p);
        $area = Training::cleanArea((string) $p['area']) ?? throw HttpException::notFound();
        $to = '/o/' . $org['slug'] . '/training/' . $area;
        if (!Training::canManage($org)) {
            return Flash::error($to, 'Das dürfen nur Admins und Ausbilder.');
        }
        $id = (string) $req->input('id', '');
        try {
            Training::save($org['id'], [
                'area' => $area,
                'topic' => $req->input('topic', ''),
                'level' => $req->input('level', ''),
                'title' => $req->input('title', ''),
                'content' => $req->input('content', ''),
            ], $id === '' ? null : $id);
        } catch (OrgError $e) {
            return Flash::error($to . ($id !== '' ? '?edit=' . rawurlencode($id) : ''), $e->getMessage());
        }
        return Flash::ok($to, 'Gespeichert.');
    }

    /** Admins entfernen den Nachweis einer bestandenen Ausbildung (auch einer inzwischen gelöschten). */
    public static function removeCompletion(Request $req, array $p): Response
    {
        [, $org] = Auth::requireOrgMember($req, (string) $p['slug']);
        $to = '/members/' . rawurlencode((string) $req->input('user', ''));
        if (($org['role'] ?? '') !== 'ADMIN') {
            return Flash::error($to, 'Das dürfen nur Admins.');
        }
        Training::removeCompletion($org['id'], (int) $req->input('id', '0'));
        return Flash::ok($to, 'Nachweis entfernt.');
    }

    public static function delete(Request $req, array $p): Response
    {
        [, $org] = self::member($req, $p);
        $area = Training::cleanArea((string) $p['area']) ?? throw HttpException::notFound();
        $to = '/o/' . $org['slug'] . '/training/' . $area;
        if (!Training::canManage($org)) {
            return Flash::error($to, 'Das dürfen nur Admins und Ausbilder.');
        }
        Training::delete($org['id'], (string) $req->input('id', ''));
        return Flash::ok($to, 'Ausbildung gelöscht.');
    }
}
