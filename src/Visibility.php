<?php

declare(strict_types=1);

namespace Hangar;

/**
 * Zentrale Sichtbarkeitsprüfung. Jede Abfrage, die Daten eines anderen Nutzers ausliefert, muss
 * hierüber laufen.
 *
 * - Der Besitzer sieht immer alles.
 * - MEMBERS sehen Nutzer, die mit dem Besitzer mindestens eine Orga teilen.
 * - PRIVATE sieht nur der Besitzer.
 * - Gäste (nicht angemeldet) sehen nichts.
 * Unbekannte Werte gelten als PRIVATE.
 */
final class Visibility
{
    /** @param list<string> $ownerOrgIds */
    public static function canView(string $visibility, string $ownerId, array $ownerOrgIds, ?Viewer $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }
        if ($viewer->id === $ownerId) {
            return true;
        }
        if ($visibility !== 'MEMBERS') {
            return false;
        }
        return self::sharesOrg($ownerOrgIds, $viewer);
    }

    /** @param list<string> $ownerOrgIds */
    public static function sharesOrg(array $ownerOrgIds, ?Viewer $viewer): bool
    {
        return $viewer !== null && array_intersect($ownerOrgIds, $viewer->orgIds()) !== [];
    }

    /**
     * SQL-Bedingung für Nutzer, deren Hangar bzw. Errungenschaften der Betrachter sehen darf.
     * Entspricht canView() als Datenbankabfrage.
     *
     * @param 'hangar_visibility'|'achievements_visibility' $field
     * @return array{0:string,1:list<mixed>} Bedingung und Parameter
     */
    public static function usersWhere(string $field, Viewer $viewer, string $alias = 'u'): array
    {
        if (!in_array($field, ['hangar_visibility', 'achievements_visibility'], true)) {
            throw new \InvalidArgumentException('Ungültiges Sichtbarkeitsfeld');
        }
        $orgIds = $viewer->orgIds();
        $sql = "($alias.id = ?";
        $params = [$viewer->id];
        if ($orgIds !== []) {
            $sql .= " OR ($alias.$field = 'MEMBERS' AND EXISTS (SELECT 1 FROM org_memberships vm WHERE vm.user_id = $alias.id AND vm.org_id IN (" . Db::in($orgIds) . ')))';
            array_push($params, ...$orgIds);
        }
        return [$sql . ')', $params];
    }
}
