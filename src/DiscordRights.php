<?php

declare(strict_types=1);

namespace Hangar;

/**
 * Rechte einer einzelnen Rolle in einem Kanal oder einer Kategorie (die Schalter ✓ / – / ✗ aus Discord, Reiter „Berechtigungen“).
 * Je Schalter: erlauben (✓), nicht festlegen (–, es gilt, was die Rolle selbst darf) oder verbieten (✗). Es wird nur der Eintrag dieser
 * einen Rolle geändert. Nicht angezeigte Rechte des Eintrags bleiben, ebenso Rechte, die der Bot selbst nicht hat (Discord erlaubt ihm nur,
 * was er selbst darf). Administrator, Rollen/Berechtigungen verwalten und Webhooks gibt es hier mit Absicht nicht.
 */
final class DiscordRights
{
    /** Einstellbare Rechte je Gruppe (Bitnummern); die Namen stehen in DiscordRoles::PERMISSIONS. */
    public const GROUPS = [
        'Allgemein' => [10, 0],
        'Text' => [11, 38, 35, 14, 15, 6, 18, 16, 17, 13, 34],
        'Sprache' => [20, 21, 9, 25, 22, 23, 24],
    ];

    /**
     * Welche Gruppen gehören zu diesem Kanaltyp? Sprachkanäle: Allgemein und Sprache; Text, Ankündigung und Forum: Allgemein und Text;
     * Kategorien: alle drei (die Rechte gelten für die Kanäle darin, die ihnen folgen).
     * @return array<string,array<int,string>> Gruppe => [Bit => Titel]
     */
    public static function groupsFor(int $type): array
    {
        $wanted = $type === 4 ? array_keys(self::GROUPS) : ($type === 2 ? ['Allgemein', 'Sprache'] : ['Allgemein', 'Text']);
        $out = [];
        foreach ($wanted as $group) {
            foreach (self::GROUPS[$group] as $bit) {
                $out[$group][$bit] = DiscordRoles::PERMISSIONS[$bit][0];
            }
        }
        return $out;
    }

    /** Alle Rechte, die für diesen Typ einstellbar sind, als Bitmaske. */
    public static function mask(int $type): int
    {
        $m = 0;
        foreach (self::groupsFor($type) as $items) {
            foreach (array_keys($items) as $bit) {
                $m |= 1 << $bit;
            }
        }
        return $m;
    }

    /**
     * Rechte, die der Bot hier setzen darf (alle einstellbaren, wenn er Administrator ist oder sich das nicht ermitteln ließ).
     * @param ?int $botPerms Bitmaske des Bots, -1 = alle, null = unbekannt
     */
    public static function grantable(int $type, ?int $botPerms): int
    {
        return $botPerms === null || $botPerms === -1 ? self::mask($type) : self::mask($type) & $botPerms;
    }

    /**
     * Der Eintrag einer Rolle in einem Kanal.
     * @param list<array{id:string,type:int,allow:string,deny:string}> $overwrites
     * @return array{exists:bool,allow:int,deny:int}
     */
    public static function current(array $overwrites, string $roleId): array
    {
        foreach ($overwrites as $o) {
            if ((string) $o['id'] === $roleId && (int) $o['type'] === 0) {
                return ['exists' => true, 'allow' => (int) $o['allow'], 'deny' => (int) $o['deny']];
            }
        }
        return ['exists' => false, 'allow' => 0, 'deny' => 0];
    }

    /**
     * Prüft das Formular und berechnet den neuen Eintrag.
     * @param array<string,mixed> $post id (Kanal oder Kategorie), role (Rollen-ID), perm[Bit] = allow | deny | leer
     * @param array{categories:list<array<string,mixed>>,loose:list<array<string,mixed>>} $structure
     * @param list<array{id:string,name:string,managed:bool,position:int}> $roles Ergebnis von DiscordBot::roleDetails() (@everyone hat die Server-ID)
     * @param ?array{permissions:int,top:int} $bot
     * @return array{id:string,name:string,roleId:string,roleName:string,allow:int,deny:int,remove:bool}
     * @throws OrgError
     */
    public static function plan(array $post, array $structure, array $roles, ?array $bot): array
    {
        $found = DiscordSetup::locate(is_string($post['id'] ?? null) ? trim($post['id']) : '', $structure);
        if ($found === null) {
            throw new OrgError('Das gibt es auf dem Server nicht mehr. Bitte die Seite neu laden.');
        }
        $row = $found['row'];
        $type = $found['category'] ? 4 : (int) $row['type'];
        $roleId = is_string($post['role'] ?? null) ? trim($post['role']) : '';
        $role = null;
        foreach ($roles as $r) {
            if ($r['id'] === $roleId) {
                $role = $r;
            }
        }
        if ($role === null) {
            throw new OrgError('Diese Rolle gibt es auf dem Server nicht (mehr). Bitte die Seite neu laden.');
        }
        if ($role['managed']) {
            throw new OrgError('Rollen von Bots und Integrationen lassen sich hier nicht ändern.');
        }
        $free = self::grantable($type, $bot['permissions'] ?? null);
        $allow = 0;
        $deny = 0;
        $perm = is_array($post['perm'] ?? null) ? $post['perm'] : [];
        foreach (self::mask($type) !== 0 ? array_keys(DiscordRoles::PERMISSIONS) : [] as $bit) {
            $b = 1 << $bit;
            if (($free & $b) === 0) {
                continue;
            }
            $state = $perm[$bit] ?? $perm[(string) $bit] ?? '';
            if ($state === 'allow') {
                $allow |= $b;
            } elseif ($state === 'deny') {
                $deny |= $b;
            }
        }
        $cur = self::current($row['overwrites'] ?? [], $roleId);
        $newAllow = ($cur['allow'] & ~$free) | $allow;
        $newDeny = ($cur['deny'] & ~$free) | $deny;
        if ($newAllow === $cur['allow'] && $newDeny === $cur['deny']) {
            throw new OrgError('Nichts geändert.');
        }
        return [
            'id' => (string) $row['id'], 'name' => (string) $row['name'], 'roleId' => $roleId, 'roleName' => $role['name'] === '@everyone' ? '@everyone' : (string) $role['name'],
            'allow' => $newAllow, 'deny' => $newDeny, 'remove' => $newAllow === 0 && $newDeny === 0,
        ];
    }
}
