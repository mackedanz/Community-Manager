<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\FleetFilter;
use Hangar\Viewer;
use Hangar\Visibility;
use PHPUnit\Framework\TestCase;

final class VisibilityFilterTest extends TestCase
{
    public static function viewer(string $id, array $orgIds): Viewer
    {
        return new Viewer($id, $id, null, null, array_map(fn ($o) => ['id' => $o, 'slug' => $o, 'name' => $o, 'iconUrl' => null, 'role' => 'MEMBER', 'canPlan' => false], $orgIds), 'OK', 'csrf');
    }

    // --- Visibility --------------------------------------------------------------------------

    public function testOwnerSeesEverything(): void
    {
        $this->assertTrue(Visibility::canView('PRIVATE', 'u1', ['A'], self::viewer('u1', ['A'])));
        $this->assertTrue(Visibility::canView('MEMBERS', 'u1', [], self::viewer('u1', [])));
    }

    public function testPrivateInvisibleToOthersEvenInSameOrg(): void
    {
        $this->assertFalse(Visibility::canView('PRIVATE', 'u1', ['A'], self::viewer('u2', ['A', 'B'])));
    }

    public function testMembersOnlyWithSharedOrg(): void
    {
        $this->assertTrue(Visibility::canView('MEMBERS', 'u1', ['A'], self::viewer('u2', ['A', 'B'])));
        $this->assertFalse(Visibility::canView('MEMBERS', 'u1', ['A'], self::viewer('u3', ['B'])));
        $this->assertFalse(Visibility::canView('MEMBERS', 'u1', ['A'], self::viewer('u4', [])));
    }

    public function testGuestsSeeNothing(): void
    {
        $this->assertFalse(Visibility::canView('MEMBERS', 'u1', ['A'], null));
    }

    public function testFormerPublicAndUnknownValuesCountAsPrivate(): void
    {
        $v = self::viewer('u2', ['A', 'B']);
        $this->assertFalse(Visibility::canView('PUBLIC', 'u1', ['A'], $v));
        $this->assertFalse(Visibility::canView('???', 'u1', ['A'], $v));
    }

    public function testSharesOrg(): void
    {
        $this->assertTrue(Visibility::sharesOrg(['A'], self::viewer('u2', ['A', 'B'])));
        $this->assertFalse(Visibility::sharesOrg(['A'], self::viewer('u3', ['B'])));
        $this->assertFalse(Visibility::sharesOrg([], self::viewer('u2', ['A', 'B'])));
        $this->assertFalse(Visibility::sharesOrg(['A'], null));
    }

    public function testUsersWhereRejectsUnknownField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Visibility::usersWhere('name; DROP TABLE users', self::viewer('u', []));
    }

    // --- FleetFilter -------------------------------------------------------------------------

    private static function cutlass(): array
    {
        return FleetFilter::parseSpecs(json_encode(['career' => 'combat', 'role' => 'Medium Fighter', 'status' => 'flight-ready', 'sizeLabel' => 'Medium', 'size' => 'medium', 'crewMin' => 1, 'crewMax' => 2]));
    }

    private static function ursa(): array
    {
        return FleetFilter::parseSpecs(json_encode(['career' => 'ground', 'role' => 'Exploration', 'status' => 'flight-ready', 'sizeLabel' => 'Vehicle', 'size' => 'vehicle', 'crewMin' => 1, 'crewMax' => 2]));
    }

    public function testParseSpecsReadsCoreDataAndToleratesBrokenData(): void
    {
        $this->assertSame('Combat', self::cutlass()['career']);
        $this->assertSame(2, self::cutlass()['crewMax']);
        $unknown = FleetFilter::parseSpecs(null);
        $this->assertSame($unknown, FleetFilter::parseSpecs('{kaputt'));
        $this->assertNull($unknown['career']);
    }

    public function testFiltersBySelectFieldsAndCombinesThem(): void
    {
        $this->assertTrue(FleetFilter::matches(self::ursa(), ['career' => 'ground']));
        $this->assertFalse(FleetFilter::matches(self::cutlass(), ['career' => 'ground']));
        $this->assertFalse(FleetFilter::matches(self::ursa(), ['career' => 'ground', 'sizeLabel' => 'Medium']));
    }

    public function testCrewFilter(): void
    {
        $c = self::cutlass();
        $this->assertTrue(FleetFilter::matches($c, ['crewMin' => 2]));
        $this->assertFalse(FleetFilter::matches($c, ['crewMin' => 3]));
        $this->assertTrue(FleetFilter::matches($c, ['crewMax' => 1]));
        $this->assertFalse(FleetFilter::matches(['crewMin' => 3] + $c, ['crewMax' => 2]));
    }

    public function testShipsWithoutDataDisappearOnceFilterIsSet(): void
    {
        $unknown = FleetFilter::parseSpecs(null);
        $this->assertTrue(FleetFilter::matches($unknown, []));
        $this->assertFalse(FleetFilter::matches($unknown, ['role' => 'Exploration']));
        $this->assertFalse(FleetFilter::matches($unknown, ['crewMin' => 0]));
    }

    public function testParseFilterIgnoresEmptyAndInvalid(): void
    {
        $this->assertSame(['career' => 'ground', 'crewMin' => 2], FleetFilter::parseFilter(['career' => 'ground', 'role' => '', 'crewMin' => '2', 'crewMax' => 'abc']));
    }

    public function testParseFilterReadsAndTrimsSearchText(): void
    {
        $this->assertSame(['q' => 'hornet mk'], FleetFilter::parseFilter(['q' => '  hornet mk ']));
        $this->assertSame([], FleetFilter::parseFilter(['q' => '   ']));
        $this->assertSame(100, mb_strlen(FleetFilter::parseFilter(['q' => str_repeat('a', 300)])['q']));
    }

    public function testFullTextSearchMatchesNameManufacturerAndSpecs(): void
    {
        $e = ['name' => 'Cutlass Black', 'manufacturer' => 'Drake Interplanetary', 'specs' => self::cutlass()];
        $this->assertTrue(FleetFilter::matchesText($e, ''));
        $this->assertTrue(FleetFilter::matchesText($e, 'cutlass'));
        $this->assertTrue(FleetFilter::matchesText($e, 'DRAKE black'));
        $this->assertTrue(FleetFilter::matchesText($e, 'medium fighter'));
        $this->assertTrue(FleetFilter::matchesText($e, 'combat'));
        $this->assertFalse(FleetFilter::matchesText($e, 'cutlass red'));
        $this->assertFalse(FleetFilter::matchesText($e, 'aegis'));
    }

    public function testFullTextSearchIgnoresCaseSpacingAndAccents(): void
    {
        $this->assertTrue(\Hangar\Text::matchesQuery(['F7A Hornet Mk II'], 'mk ii'));
        $this->assertTrue(\Hangar\Text::matchesQuery(['F7A Hornet Mk II'], 'f7a-hornet'));
        $this->assertTrue(\Hangar\Text::matchesQuery(['San\'tok.yāi'], 'santok yai'));
        $this->assertTrue(\Hangar\Text::matchesQuery([null, ''], '   '));
        $this->assertFalse(\Hangar\Text::matchesQuery([null], 'x'));
    }

    public function testOptionsSortedAndUnique(): void
    {
        $this->assertSame(['Combat', 'Ground'], FleetFilter::options([self::cutlass(), self::ursa(), FleetFilter::parseSpecs(null)])['career']);
    }

    public function testCareerSpellingIsUnified(): void
    {
        $a = FleetFilter::parseSpecs(json_encode(['career' => 'Combat']));
        $b = FleetFilter::parseSpecs(json_encode(['career' => 'combat']));
        $this->assertSame(['Combat'], FleetFilter::options([$a, $b])['career']);
        $this->assertTrue(FleetFilter::matches($b, ['career' => 'Combat']));
        $this->assertTrue(FleetFilter::matches($a, ['career' => 'combat']));
    }
}
