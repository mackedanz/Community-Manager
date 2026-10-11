<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\FleetFilter;
use Hangar\OrgStats;
use PHPUnit\Framework\TestCase;

final class OrgStatsTest extends TestCase
{
    private static function entry(string $name, int $count, ?string $manufacturer, ?array $spec): array
    {
        return [
            'catalogItemId' => $spec ? $name : null, 'name' => $name, 'count' => $count, 'href' => null,
            'manufacturer' => $manufacturer, 'imageUrl' => null,
            'specs' => FleetFilter::parseSpecs($spec ? json_encode($spec) : null),
        ];
    }

    private static function fleet(): array
    {
        return [
            'entries' => [
                self::entry('Cutlass', 3, 'Drake', ['career' => 'combat', 'status' => 'flight-ready', 'sizeLabel' => 'Medium', 'crewMin' => 2, 'crewMax' => 4, 'cargo' => 46]),
                self::entry('Hammerhead', 1, 'Aegis', ['career' => 'Combat', 'status' => 'in-concept', 'sizeLabel' => 'Large', 'crewMin' => 6, 'crewMax' => 14]),
                self::entry('Eigenbau', 2, null, null),
            ],
            'totalShips' => 6,
            'memberCount' => 3,
        ];
    }

    public function testKpisAndCrewDeficit(): void
    {
        $s = OrgStats::compute(self::fleet(), 4);
        $this->assertSame(4, $s['members']);
        $this->assertSame(6, $s['totalShips']);
        $this->assertSame(3, $s['uniqueModels']);
        $this->assertSame(3, $s['flightReady']);
        $this->assertSame(3 * 2 + 6, $s['minCrew']);
        $this->assertSame(3 * 4 + 14, $s['maxCrew']);
        $this->assertSame(12 - 4, $s['crewDeficit']);
        $this->assertSame(138, $s['totalCargo']);
    }

    public function testMergesCareersAndPutsUnknownLast(): void
    {
        $s = OrgStats::compute(self::fleet(), 4);
        $this->assertSame([['Combat', 4], ['Unbekannt', 2]], array_map(fn ($x) => [$x['label'], $x['value']], $s['byCareer']));
        $this->assertSame(['key' => 'career', 'value' => 'Combat'], $s['byCareer'][0]['filter']);
        $this->assertNull($s['byCareer'][1]['filter']);
    }

    public function testStatusIsLabeledNicelyButFiltersWithRawValue(): void
    {
        $s = OrgStats::compute(self::fleet(), 4);
        $ready = array_values(array_filter($s['byStatus'], fn ($x) => $x['label'] === 'Flight-ready'))[0];
        $this->assertSame(['key' => 'status', 'value' => 'flight-ready'], $ready['filter']);
    }

    public function testCrewDeficitNeverNegativeAndTooManySlicesBecomeOther(): void
    {
        $this->assertSame(0, OrgStats::compute(self::fleet(), 100)['crewDeficit']);
        $many = ['entries' => array_map(fn ($i) => self::entry("S$i", 1, "M$i", null), range(0, 14)), 'totalShips' => 15, 'memberCount' => 1];
        $m = OrgStats::compute($many, 1)['byManufacturer'];
        $this->assertCount(10, $m);
        $this->assertSame('Weitere', $m[9]['label']);
    }
}
