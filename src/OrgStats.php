<?php

declare(strict_types=1);

namespace Hangar;

/** Statistik des Orga-Hangars. Rechnet nur auf der anonymen Orga-Flotte (keine Personendaten). */
final class OrgStats
{
    private const UNKNOWN = 'Unbekannt';
    private const OTHER = 'Weitere';
    private const MAX_SLICES = 10;
    private const STATUS_LABELS = ['flight-ready' => 'Flight-ready', 'in-concept' => 'In-concept'];

    /**
     * Zählt Schiffe je Wert; "Unbekannt" steht am Ende, Überhang wird zu "Weitere" zusammengefasst.
     * @param array{entries:list<array<string,mixed>>} $fleet
     * @param callable(array<string,mixed>):?string $pick
     * @param 'career'|'role'|'status'|'sizeLabel'|null $filterKey
     * @return list<array{label:string,value:int,filter:?array{key:string,value:string}}>
     */
    private static function tally(array $fleet, callable $pick, ?string $filterKey, ?callable $labelOf = null, int $maxSlices = PHP_INT_MAX): array
    {
        $map = [];
        foreach ($fleet['entries'] as $e) {
            $v = $pick($e) ?? self::UNKNOWN;
            $map[$v] = ($map[$v] ?? 0) + $e['count'];
        }
        $keys = array_keys($map);
        usort($keys, function ($a, $b) use ($map) {
            $a = (string) $a;
            $b = (string) $b;
            if ($a === self::UNKNOWN) {
                return 1;
            }
            if ($b === self::UNKNOWN) {
                return -1;
            }
            return $map[$b] <=> $map[$a] ?: strcasecmp($a, $b);
        });
        $slices = [];
        foreach ($keys as $raw) {
            $raw = (string) $raw;
            $slices[] = [
                'label' => $labelOf ? $labelOf($raw) : $raw,
                'value' => $map[$raw],
                'filter' => $filterKey !== null && $raw !== self::UNKNOWN ? ['key' => $filterKey, 'value' => $raw] : null,
            ];
        }
        if (count($slices) > $maxSlices) {
            $rest = array_slice($slices, $maxSlices - 1);
            $slices = [...array_slice($slices, 0, $maxSlices - 1), [
                'label' => self::OTHER, 'value' => array_sum(array_column($rest, 'value')), 'filter' => null,
            ]];
        }
        return $slices;
    }

    /**
     * @param array{entries:list<array<string,mixed>>,totalShips:int} $fleet
     * @return array<string,mixed>
     */
    public static function compute(array $fleet, int $members): array
    {
        $sum = static function (callable $f) use ($fleet): int|float {
            $s = 0;
            foreach ($fleet['entries'] as $e) {
                $s += $f($e);
            }
            return $s;
        };
        $minCrew = $sum(fn ($e) => $e['count'] * ($e['specs']['crewMin'] ?? 0));

        return [
            'members' => $members,
            'totalShips' => $fleet['totalShips'],
            'uniqueModels' => count($fleet['entries']),
            'flightReady' => $sum(fn ($e) => $e['specs']['status'] === 'flight-ready' ? $e['count'] : 0),
            'minCrew' => $minCrew,
            'maxCrew' => $sum(fn ($e) => $e['count'] * ($e['specs']['crewMax'] ?? $e['specs']['crewMin'] ?? 0)),
            // Fehlende Leute, um alle Schiffe mit Mindestbesatzung zu bemannen
            'crewDeficit' => max(0, $minCrew - $members),
            'totalCargo' => $sum(fn ($e) => $e['count'] * ($e['specs']['cargo'] ?? 0)),
            'byCareer' => self::tally($fleet, fn ($e) => $e['specs']['career'], 'career'),
            'byManufacturer' => self::tally($fleet, fn ($e) => $e['manufacturer'], null, null, self::MAX_SLICES),
            'byStatus' => self::tally($fleet, fn ($e) => $e['specs']['status'], 'status', fn ($v) => self::STATUS_LABELS[$v] ?? $v),
            'bySize' => self::tally($fleet, fn ($e) => $e['specs']['sizeLabel'], 'sizeLabel'),
            'byRole' => self::tally($fleet, fn ($e) => $e['specs']['role'], 'role', null, self::MAX_SLICES),
            'topModels' => array_map(fn ($e) => ['name' => $e['name'], 'count' => $e['count']], array_slice($fleet['entries'], 0, 10)),
        ];
    }

    /** Statistik der Orga; nur für Mitglieder (sonst null). @return array<string,mixed>|null */
    public static function forOrg(string $orgId, Viewer $viewer): ?array
    {
        if (!in_array($orgId, $viewer->orgIds(), true)) {
            return null;
        }
        $fleet = Community::getOrgFleet($orgId, $viewer);
        $members = (int) Db::val('SELECT COUNT(*) FROM org_memberships WHERE org_id = ?', [$orgId]);
        return self::compute($fleet, $members);
    }
}
