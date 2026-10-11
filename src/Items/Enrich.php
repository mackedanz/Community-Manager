<?php

declare(strict_types=1);

namespace Hangar\Items;

use Hangar\Db;
use Hangar\Http\Client;
use Hangar\Text;
use Hangar\Time;

/**
 * Zusatzinfos (Beschreibung, Typ, Bild, Wiki-Link) zu Hangar-Einträgen ohne Katalogobjekt. Quellen
 * in dieser Reihenfolge: Star Citizen Wiki API (mit deutscher Beschreibung), dann als Ersatz das
 * MediaWiki von starcitizen.tools. Die Treffer werden in item_info gespeichert und nie in den
 * Katalog übernommen. Beides sind Community-Schnittstellen: alles außer dem Namen ist optional,
 * und Fehler stoppen nichts.
 */
final class Enrich
{
    private const WIKI_ITEMS_URL = 'https://api.star-citizen.wiki/api/v2/items';
    private const TOOLS_API_URL = 'https://starcitizen.tools/api.php';
    private const USER_AGENT = 'community-hangar (Star-Citizen-Fan-Tool)';

    /** Unbekannte Namen werden erst nach dieser Zeit erneut nachgeschlagen. */
    public const RECHECK_SECONDS = 14 * 86400;
    private const REQUEST_DELAY_MS = 250;
    private const MAX_CONSECUTIVE_FAILURES = 5;
    private const MAX_DESCRIPTION = 400;
    private const MAX_DROPPED_WORDS = 2;

    /** @param mixed $images @return array<string,mixed>|null das erste Bild */
    private static function firstImage(mixed $images): ?array
    {
        if (!is_array($images)) {
            return null;
        }
        $first = array_is_list($images) ? ($images[0] ?? null) : $images;
        return is_array($first) ? $first : null;
    }

    /** @return array{name:string,description:?string,typeLabel:?string,imageUrl:?string,webUrl:?string}|null */
    public static function mapWikiItem(mixed $raw): ?array
    {
        if (!is_array($raw) || !is_string($raw['name'] ?? null)) {
            return null;
        }
        $desc = is_array($raw['description'] ?? null) ? $raw['description'] : [];
        $description = trim((string) (($desc['de_DE'] ?? '') ?: ($desc['en_EN'] ?? '')));
        $img = self::firstImage($raw['images'] ?? null);
        $type = $raw['type_label'] ?? null;
        $url = null;
        foreach (['thumbnail_url', 'original_url'] as $k) {
            if (isset($img[$k]) && is_string($img[$k]) && $img[$k] !== '') {
                $url = $img[$k];
                break;
            }
        }
        return [
            'name' => $raw['name'],
            'description' => $description !== '' ? mb_substr($description, 0, self::MAX_DESCRIPTION) : null,
            'typeLabel' => is_string($type) && $type !== '' && $type !== 'Misc' ? $type : null,
            'imageUrl' => $url,
            'webUrl' => is_string($raw['web_url'] ?? null) ? $raw['web_url'] : null,
        ];
    }

    /**
     * Suchbegriffe für einen RSI-Namen, vom genauesten zum ungenauesten. Die Wiki-Suche findet nur
     * Teilstrings, deshalb: RSI schreibt typografische Anführungszeichen (‘ ’ “ ”), die Wiki gerade,
     * und Hersteller-Kürzel vorne ("CCC Aves Helmet") stehen dort meist nicht im Namen.
     * @return list<string>
     */
    public static function searchTerms(string $name): array
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $name));
        $straight = (string) preg_replace('/[“”„‟]/u', '"', (string) preg_replace('/[‘’‚‛]/u', "'", $clean));
        $doubleOnly = str_replace("'", '"', $straight);
        $bases = array_values(array_unique([$straight, $doubleOnly]));

        $terms = [];
        for ($drop = 0; $drop <= self::MAX_DROPPED_WORDS; $drop++) {
            foreach ($bases as $base) {
                $words = explode(' ', $base);
                if (count($words) - $drop < ($drop === 0 ? 1 : 2)) {
                    continue;
                }
                $terms[] = implode(' ', array_slice($words, $drop));
            }
        }
        return array_values(array_unique($terms));
    }

    /** @return mixed dekodiertes JSON @throws EnrichError */
    private static function getJson(string $url, array $headers): mixed
    {
        $res = Client::get($url, ['Accept' => 'application/json'] + $headers, 30);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new EnrichError('HTTP ' . $res['status']);
        }
        return json_decode($res['body'], true);
    }

    /** @return list<mixed> */
    private static function queryWiki(string $term): array
    {
        $body = self::getJson(self::WIKI_ITEMS_URL . '?filter[name]=' . rawurlencode($term) . '&page[size]=25', []);
        return is_array($body) && is_array($body['data'] ?? null) ? array_values($body['data']) : [];
    }

    /**
     * Sucht einen Namen in der Wiki-API. Die Suche findet auch Teilnamen, deshalb zählt nur ein
     * Treffer, dessen normalisierter Name dem Suchbegriff entspricht.
     */
    private static function lookupWikiApi(string $name): ?array
    {
        foreach (self::searchTerms($name) as $term) {
            $key = Text::normalizeName($term);
            foreach (self::queryWiki($term) as $raw) {
                $item = self::mapWikiItem($raw);
                if ($item !== null && Text::normalizeName($item['name']) === $key) {
                    return $item;
                }
            }
        }
        return null;
    }

    /** @return array{name:string,description:?string,typeLabel:?string,imageUrl:?string,webUrl:?string}|null */
    public static function mapToolsPage(mixed $raw): ?array
    {
        if (!is_array($raw) || !is_string($raw['title'] ?? null)) {
            return null;
        }
        $description = trim((string) preg_replace('/\s+/u', ' ', (string) ($raw['extract'] ?? '')));
        $thumb = is_array($raw['thumbnail'] ?? null) && is_string($raw['thumbnail']['source'] ?? null) ? $raw['thumbnail']['source'] : null;
        return [
            'name' => $raw['title'],
            'description' => $description !== '' ? mb_substr($description, 0, self::MAX_DESCRIPTION) : null,
            'typeLabel' => null,
            'imageUrl' => $thumb,
            'webUrl' => is_string($raw['fullurl'] ?? null) ? $raw['fullurl'] : null,
        ];
    }

    /** @param array<string,string> $extra @return array{pages:list<mixed>,redirects:list<array{from:string,to:string}>} */
    private static function queryToolsRaw(array $extra): array
    {
        $params = ['action' => 'query', 'format' => 'json'] + $extra + [
            'prop' => 'pageimages|extracts|info', 'piprop' => 'thumbnail', 'pithumbsize' => '240',
            'exintro' => '1', 'explaintext' => '1', 'exsentences' => '2', 'exlimit' => 'max', 'inprop' => 'url',
        ];
        $body = self::getJson(self::TOOLS_API_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986), ['User-Agent' => self::USER_AGENT]);
        $q = is_array($body) && is_array($body['query'] ?? null) ? $body['query'] : [];
        return [
            'pages' => is_array($q['pages'] ?? null) ? array_values($q['pages']) : [],
            'redirects' => is_array($q['redirects'] ?? null) ? array_values($q['redirects']) : [],
        ];
    }

    private static function titleWithoutType(string $title): ?string
    {
        // Seitentitel tragen oft einen Typ in Klammern ("Conner's Beard Moss (flair)"). Den lassen wir
        // beim Vergleich weg, aber nicht bei Varianten ("Sangar Helmet (Modified)"), die etwas anderes sind.
        if (preg_match('/\s*\(([^()]*)\)\s*$/u', $title, $m) && !preg_match('/modified|edition|livery|paint|variant|version|\b\d{4}\b/i', $m[1])) {
            return (string) preg_replace('/\s*\(([^()]*)\)\s*$/u', '', $title);
        }
        return null;
    }

    /**
     * Fragt die Seite direkt über ihren Titel ab. Die Volltextsuche findet Weiterleitungen nicht
     * ("Conner's Beard Moss (flair)" führt auf "Conner's Beard Moss Plant (flair)"); beim Abruf
     * über den Titel löst das Wiki sie auf.
     */
    private static function lookupToolsByTitle(string $name): ?array
    {
        $base = self::searchTerms($name)[0] ?? '';
        if ($base === '') {
            return null;
        }
        $key = Text::normalizeName($base);
        $titles = array_map(fn ($s) => $base . $s, ['', ' (flair)']);
        $r = self::queryToolsRaw(['titles' => implode('|', $titles), 'redirects' => '1']);
        $requestedKey = fn (string $from) => Text::normalizeName(self::titleWithoutType($from) ?? $from);

        foreach ($r['pages'] as $raw) {
            if (is_array($raw) && array_key_exists('missing', $raw)) {
                continue;
            }
            $page = self::mapToolsPage($raw);
            if ($page === null) {
                continue;
            }
            $viaRedirect = false;
            foreach ($r['redirects'] as $red) {
                if (($red['to'] ?? null) === $page['name'] && $requestedKey((string) ($red['from'] ?? '')) === $key) {
                    $viaRedirect = true;
                }
            }
            if ($viaRedirect || Text::normalizeName($page['name']) === $key || Text::normalizeName(self::titleWithoutType($page['name']) ?? '') === $key) {
                return $page;
            }
        }
        return null;
    }

    /** Sucht eine Seite auf starcitizen.tools, deren Titel dem Suchbegriff entspricht. */
    private static function lookupTools(string $name): ?array
    {
        $direct = self::lookupToolsByTitle($name);
        if ($direct !== null) {
            return $direct;
        }
        foreach (self::searchTerms($name) as $term) {
            $key = Text::normalizeName($term);
            $r = self::queryToolsRaw(['generator' => 'search', 'gsrsearch' => $term, 'gsrnamespace' => '0', 'gsrlimit' => '8']);
            $pages = array_values(array_filter(array_map([self::class, 'mapToolsPage'], $r['pages'])));
            // Ein genauer Titel gewinnt vor einem Titel, der nur mit Typ-Klammer passt.
            foreach ($pages as $p) {
                if (Text::normalizeName($p['name']) === $key) {
                    return $p;
                }
            }
            foreach ($pages as $p) {
                $bare = self::titleWithoutType($p['name']);
                if ($bare !== null && Text::normalizeName($bare) === $key) {
                    return $p;
                }
            }
        }
        return null;
    }

    /**
     * Sucht Zusatzinfos zu einem Namen: erst in der Wiki-API, sonst auf starcitizen.tools.
     * @return array{name:string,description:?string,typeLabel:?string,imageUrl:?string,webUrl:?string}|null
     * @throws EnrichError|\Hangar\Http\NetworkError bei Netzwerk- oder API-Fehlern
     */
    public static function lookupItem(string $name): ?array
    {
        return self::lookupWikiApi($name) ?? self::lookupTools($name);
    }

    /**
     * Schlägt Hangar-Einträge vom Typ ITEM nach, zu denen es noch keine (oder eine veraltete
     * "unbekannt"-Antwort) gibt. Mit userId nur für diesen Nutzer, sonst für alle. Netzwerkfehler
     * werden nicht gespeichert, damit der nächste Lauf es erneut versucht.
     *
     * @return array{checked:int,found:int,failed:int}
     */
    public static function enrichPending(?string $userId = null, int $limit = 1000, int $delayMs = self::REQUEST_DELAY_MS): array
    {
        $sql = "SELECT DISTINCT custom_name FROM owned_items WHERE kind = 'ITEM' AND catalog_item_id IS NULL AND custom_name IS NOT NULL";
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $names = [];
        foreach (Db::all($sql, $params) as $o) {
            $key = Text::normalizeName($o['custom_name']);
            if ($key !== '' && !isset($names[$key])) {
                $names[$key] = $o['custom_name'];
            }
        }
        $result = ['checked' => 0, 'found' => 0, 'failed' => 0];
        if ($names === []) {
            return $result;
        }

        $keys = array_keys($names);
        $skip = [];
        $now = Time::now()->getTimestamp();
        foreach (Db::all('SELECT match_key, found, checked_at FROM item_info WHERE match_key IN (' . Db::in($keys) . ')', $keys) as $k) {
            if ($k['found'] || $now - Time::parse($k['checked_at'])->getTimestamp() < self::RECHECK_SECONDS) {
                $skip[$k['match_key']] = true;
            }
        }

        $consecutive = 0;
        foreach ($names as $key => $name) {
            if (isset($skip[$key])) {
                continue;
            }
            if ($result['checked'] + $result['failed'] >= $limit) {
                break;
            }
            try {
                $info = self::lookupItem($name);
                Db::run(
                    'INSERT INTO item_info (match_key, found, name, description, type_label, image_url, web_url, checked_at) VALUES (?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE found = VALUES(found), name = VALUES(name), description = VALUES(description),
                       type_label = VALUES(type_label), image_url = VALUES(image_url), web_url = VALUES(web_url), checked_at = VALUES(checked_at)',
                    [$key, $info !== null, $info['name'] ?? null, $info['description'] ?? null, $info['typeLabel'] ?? null, $info['imageUrl'] ?? null, $info['webUrl'] ?? null, Time::nowDb()],
                );
                $result['checked']++;
                if ($info !== null) {
                    $result['found']++;
                }
                $consecutive = 0;
            } catch (\Throwable) {
                $result['failed']++;
                // Ist die API nicht erreichbar, nicht hunderte Anfragen in Folge verschwenden.
                if (++$consecutive >= self::MAX_CONSECUTIVE_FAILURES) {
                    break;
                }
            }
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }
        return $result;
    }
}
