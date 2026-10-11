<?php

declare(strict_types=1);

namespace Hangar\Tests;

use Hangar\Db;
use Hangar\Http\Client;
use Hangar\Http\NetworkError;
use Hangar\Items\Enrich;
use Hangar\Items\EnrichError;

final class EnrichTest extends DbTestCase
{
    /** @var list<string> */
    private array $urls = [];

    private static function wikiItem(string $name, array $extra = []): array
    {
        return $extra + [
            'name' => $name, 'description' => ['en_EN' => 'English text', 'de_DE' => 'Deutscher Text'],
            'type_label' => 'Misc', 'images' => [], 'web_url' => "https://wiki.example/items/$name",
        ];
    }

    private static function respond(array $data, int $status = 200): array
    {
        return ['status' => $status, 'body' => json_encode(['data' => $data]), 'headers' => []];
    }

    private static function raw(mixed $body, int $status = 200): array
    {
        return ['status' => $status, 'body' => json_encode($body), 'headers' => []];
    }

    private function fake(callable $f): void
    {
        Client::fake(function (string $m, string $url) use ($f) {
            $this->urls[] = $url;
            return $f($url);
        });
    }

    // --- Abbildung ---------------------------------------------------------------------------

    public function testPrefersGermanDescriptionAndHidesMiscType(): void
    {
        $i = Enrich::mapWikiItem(self::wikiItem('Coin'));
        $this->assertSame('Coin', $i['name']);
        $this->assertSame('Deutscher Text', $i['description']);
        $this->assertNull($i['typeLabel']);
        $this->assertNull($i['imageUrl']);
    }

    public function testTakesImageAndTypeAndShortensLongDescriptions(): void
    {
        $i = Enrich::mapWikiItem(self::wikiItem('Rack', ['type_label' => 'Decoration', 'images' => [['thumbnail_url' => 'https://img/x.png']], 'description' => ['en_EN' => str_repeat('x', 1000)]]));
        $this->assertSame('Decoration', $i['typeLabel']);
        $this->assertSame('https://img/x.png', $i['imageUrl']);
        $this->assertSame(400, mb_strlen($i['description']));
    }

    public function testRejectsDataWithoutName(): void
    {
        $this->assertNull(Enrich::mapWikiItem(['foo' => 1]));
        $this->assertNull(Enrich::mapToolsPage(['nope' => 1]));
    }

    public function testSearchTermsFromMostToLeastExact(): void
    {
        $this->assertSame(['CCC Aves Helmet', 'Aves Helmet'], Enrich::searchTerms('CCC Aves Helmet'));
        $this->assertSame(['Bosco'], Enrich::searchTerms('Bosco'));
        $this->assertSame("RSI Zeus 'Solar' Helmet", Enrich::searchTerms('RSI Zeus ‘Solar’ Helmet')[0]);
    }

    // --- lookupItem --------------------------------------------------------------------------

    public function testTakesOnlyAnExactNameMatchNotAPartialName(): void
    {
        $this->fake(fn () => self::respond([self::wikiItem('Bosco Weapon Display Rack Deluxe'), self::wikiItem('Bosco Weapon Display Rack')]));
        $this->assertSame('Bosco Weapon Display Rack', Enrich::lookupItem('Bosco Weapon Display Rack')['name']);

        $this->fake(fn ($url) => str_contains($url, 'starcitizen.tools') ? self::raw([]) : self::respond([self::wikiItem('Bosco Weapon Display Rack')]));
        $this->assertNull(Enrich::lookupItem('Bosco Weapon'));
    }

    public function testFindsNamesWithTypographicQuotesAndWithoutManufacturerPrefix(): void
    {
        $wiki = [self::wikiItem('LH86 "Permafrost" Pistol'), self::wikiItem('Aves Helmet')];
        $this->fake(function ($url) use ($wiki) {
            if (str_contains($url, 'starcitizen.tools')) {
                return self::raw([]);
            }
            $q = rawurldecode(explode('&', explode('filter[name]=', $url)[1])[0]);
            return self::respond(array_values(array_filter($wiki, fn ($w) => str_contains($w['name'], $q))));
        });
        $this->assertSame('LH86 "Permafrost" Pistol', Enrich::lookupItem('LH86 “Permafrost” Pistol')['name']);
        $this->assertSame('Aves Helmet', Enrich::lookupItem('CCC Aves Helmet')['name']);
        $this->assertNull(Enrich::lookupItem('Völlig Unbekannt Ding'));
    }

    public function testFallsBackToStarcitizenTools(): void
    {
        $this->fake(fn ($url) => str_contains($url, 'starcitizen.tools')
            ? self::raw(['query' => ['pages' => [
                '1' => ['title' => 'Zeus Exploration Suit'],
                '2' => ['title' => 'Sangar Helmet', 'extract' => 'Ein  Helm.', 'fullurl' => 'https://starcitizen.tools/Sangar_Helmet', 'thumbnail' => ['source' => 'https://img/sangar.png']],
            ]]])
            : self::respond([]));
        $this->assertSame([
            'name' => 'Sangar Helmet', 'description' => 'Ein Helm.', 'typeLabel' => null,
            'imageUrl' => 'https://img/sangar.png', 'webUrl' => 'https://starcitizen.tools/Sangar_Helmet',
        ], Enrich::lookupItem('Virgil Sangar Helmet'));
    }

    public function testIgnoresTypeParenthesisButNotVariants(): void
    {
        $tools = fn (array $titles) => fn ($url) => str_contains($url, 'starcitizen.tools')
            ? self::raw(['query' => ['pages' => array_map(fn ($t) => ['title' => $t], $titles)]])
            : self::respond([]);

        $this->fake($tools(['Conner’s Beard Moss Plant (flair)', 'Conner’s Beard Moss (flair)']));
        $this->assertSame('Conner’s Beard Moss (flair)', Enrich::lookupItem('Conner’s Beard Moss')['name']);

        $this->fake($tools(['Sangar Helmet (Modified)']));
        $this->assertNull(Enrich::lookupItem('Sangar Helmet'));

        $this->fake($tools(['Aves Helmet (Modified)', 'Aves Helmet']));
        $this->assertSame('Aves Helmet', Enrich::lookupItem('Aves Helmet')['name']);
    }

    public function testResolvesRedirectsViaPageTitle(): void
    {
        $this->fake(function ($url) {
            if (!str_contains($url, 'starcitizen.tools')) {
                return self::respond([]);
            }
            if (!str_contains($url, 'titles=')) {
                return self::raw([]);
            }
            return self::raw(['query' => [
                'redirects' => [['from' => "Conner's Beard Moss (flair)", 'to' => 'Conner’s Beard Moss Plant (flair)']],
                'pages' => [
                    '1' => ['title' => 'Conner’s Beard Moss Plant (flair)', 'extract' => 'Ein Moos.', 'fullurl' => 'https://starcitizen.tools/x'],
                    '-1' => ['title' => "Conner's Beard Moss", 'missing' => ''],
                ],
            ]]);
        });
        $info = Enrich::lookupItem('Conner’s Beard Moss');
        $this->assertSame('Conner’s Beard Moss Plant (flair)', $info['name']);
        $this->assertSame('Ein Moos.', $info['description']);
    }

    public function testThrowsOnHttpErrors(): void
    {
        $this->fake(fn () => self::respond([], 500));
        $this->expectException(EnrichError::class);
        $this->expectExceptionMessage('HTTP 500');
        Enrich::lookupItem('X');
    }

    // --- enrichPending -----------------------------------------------------------------------

    private function ownItems(string $userId, array $items): void
    {
        foreach ($items as [$kind, $name]) {
            Db::insert('owned_items', ['id' => new_id(), 'user_id' => $userId, 'kind' => $kind, 'custom_name' => $name, 'source' => 'IMPORT']);
        }
    }

    public function testChecksOnlyItemKindAndStoresHitsAndMisses(): void
    {
        $userId = $this->mkUser(['name' => 'Enricher'])['id'];
        $this->ownItems($userId, [['ITEM', 'Bosco Weapon Display Rack'], ['ITEM', 'Nur im Hangar'], ['PAINT', 'Aurora Disco Paint']]);
        $this->fake(fn ($url) => self::respond(str_contains(rawurldecode($url), 'Bosco') ? [self::wikiItem('Bosco Weapon Display Rack')] : []));

        $r = Enrich::enrichPending($userId, 1000, 0);

        // Bosco: 1 Anfrage. "Nur im Hangar": Wiki-API 2, starcitizen.tools Titelabfrage 1 + Suche 2 = 5.
        $this->assertCount(6, $this->urls);
        $this->assertSame(['checked' => 2, 'found' => 1, 'failed' => 0], $r);
        $rows = array_column(Db::all('SELECT * FROM item_info'), null, 'match_key');
        $this->assertSame(1, (int) $rows['boscoweapondisplayrack']['found']);
        $this->assertSame('Deutscher Text', $rows['boscoweapondisplayrack']['description']);
        $this->assertSame(0, (int) $rows['nurimhangar']['found']);
        $this->assertArrayNotHasKey('auroradiscopaint', $rows);

        // Zweiter Lauf: alles bekannt oder kürzlich geprüft
        $this->urls = [];
        $this->assertSame(['checked' => 0, 'found' => 0, 'failed' => 0], Enrich::enrichPending($userId, 1000, 0));
        $this->assertSame([], $this->urls);
    }

    public function testStoresNothingOnNetworkErrorsAndStopsAfterRepeatedFailures(): void
    {
        $u = $this->mkUser(['name' => 'Offline'])['id'];
        $this->ownItems($u, array_map(fn ($i) => ['ITEM', "Offline Ding $i"], range(0, 7)));
        $this->fake(function () {
            throw new NetworkError('offline');
        });
        $r = Enrich::enrichPending($u, 1000, 0);
        $this->assertSame(['checked' => 0, 'found' => 0, 'failed' => 5], $r);
        $this->assertCount(5, $this->urls);
        $this->assertSame(0, (int) Db::val('SELECT COUNT(*) FROM item_info'));
    }

    public function testRechecksUnknownNamesAfterTwoWeeks(): void
    {
        $u = $this->mkUser()['id'];
        $this->ownItems($u, [['ITEM', 'Altes Ding']]);
        Db::insert('item_info', ['match_key' => 'altesding', 'found' => 0, 'checked_at' => gmdate('Y-m-d H:i:s', time() - Enrich::RECHECK_SECONDS - 60)]);
        $this->fake(fn () => self::respond([]));
        $this->assertSame(1, Enrich::enrichPending($u, 1000, 0)['checked']);
    }
}
