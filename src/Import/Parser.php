<?php

declare(strict_types=1);

namespace Hangar\Import;

/**
 * Unterstützte Formate:
 * 1. "hangarexport" v2 (RSI-Lesezeichen / Hangar-Export-Erweiterung): Objekt mit pledges[].items[].
 * 2. HangarXPLOR "Pledges JSON": Array, jede Zeile ein Pledge mit Flags.
 * 3. Einfache Liste: Array von Objekten mit name (oder ship/title/model), optional lti und quantity.
 *
 * Ein Eintrag ist ein Array: title, isShip, lti, quantity und optional altTitle, code, category.
 */
final class Parser
{
    private const LTI_PATTERN = '/lifetime insurance|\blti\b/i';
    // Verwaltungs-Einträge, die kein Besitz sind (Versicherung, Spielzugang).
    private const JUNK_KIND = '/insurance|warranty|subscription|digital/i';
    private const JUNK_TITLE = '/insurance|digital (download|copy)|game package|^(star citizen|squadron 42)( digital)?( download)?$/i';

    /**
     * @return array{handle:?string,entries:list<array<string,mixed>>,format:string}
     * @throws ImportFormatError
     */
    public static function parse(mixed $json): array
    {
        if (is_array($json) && array_is_list($json)) {
            // HangarXPLOR zuerst prüfen: Seine Zeilen haben auch ein "name"-Feld und würden
            // sonst von der einfachen Liste als Schiffe missverstanden.
            foreach ($json as $e) {
                if (self::xplorEntry($e) !== null) {
                    return self::parseXplor($json);
                }
            }
            $result = self::parseSimple($json);
            if ($result['entries'] === []) {
                throw new ImportFormatError('Die Liste enthält keine erkennbaren Einträge.');
            }
            return $result;
        }
        if (is_array($json) && ($json['type'] ?? null) === 'hangarexport' && isset($json['pledges']) && is_array($json['pledges'])) {
            return self::parseV2($json);
        }
        throw new ImportFormatError('Unbekanntes Format. Erwartet wird der JSON-Export der Hangar-Export-Erweiterung.');
    }

    private static function str(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }

    /** Kategorie eines Nicht-Schiffs aus dem Pledge, oder null bei Verwaltungs-Einträgen. */
    private static function otherCategory(string $kind, string $pledgeName, string $title): ?string
    {
        if (preg_match(self::JUNK_KIND, $kind) || preg_match(self::JUNK_TITLE, $title)) {
            return null;
        }
        if (preg_match('/upgrade/i', $kind) || preg_match('/^upgrade\s*-/i', $title)) {
            return 'UPGRADE';
        }
        if (preg_match('/skin|paint/i', $kind) || preg_match('/^paint\b/i', $pledgeName)) {
            return 'PAINT';
        }
        return 'ITEM';
    }

    /** @param array<string,mixed> $data @return array{handle:?string,entries:list<array<string,mixed>>,format:string} */
    private static function parseV2(array $data): array
    {
        $entries = [];
        foreach ($data['pledges'] as $pledge) {
            if (!is_array($pledge)) {
                continue;
            }
            $pledgeNameRaw = self::str($pledge['pledgeName'] ?? '');
            $pledgeName = trim($pledgeNameRaw);
            // Upgrades (CCU) werden als eigener Eintrag mit dem Pledge-Namen geführt.
            if (preg_match('/^upgrade\b/i', $pledgeName)) {
                $title = trim((string) preg_replace('/^upgrade\s*-\s*/i', '', $pledgeName));
                if ($title !== '') {
                    $entries[] = ['title' => $title, 'isShip' => false, 'category' => 'UPGRADE', 'lti' => false, 'quantity' => 1];
                }
                continue;
            }
            $items = array_values(array_filter(is_array($pledge['items'] ?? null) ? $pledge['items'] : [], 'is_array'));
            $also = array_values(array_filter(is_array($pledge['alsoContains'] ?? null) ? $pledge['alsoContains'] : [], 'is_array'));

            $texts = [$pledgeNameRaw];
            foreach ([...$items, ...$also] as $i) {
                $texts[] = self::str($i['title'] ?? '');
            }
            // Näherung: Enthält ein Pledge Lifetime Insurance, gilt das für alle Schiffe darin.
            $lti = false;
            foreach ($texts as $t) {
                if (preg_match(self::LTI_PATTERN, $t)) {
                    $lti = true;
                    break;
                }
            }

            foreach ($items as $item) {
                $title = trim(self::str($item['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $kind = self::str($item['kind'] ?? '');
                if (preg_match('/^(ship|vehicle)$/i', trim($kind))) {
                    $entries[] = ['title' => $title, 'isShip' => true, 'lti' => $lti, 'quantity' => 1];
                    continue;
                }
                $category = self::otherCategory($kind, $pledgeName, $title);
                $e = [
                    // Upgrades in Paketen heißen "Upgrade - A to B"; wie eigenständige Upgrades ohne Präfix.
                    'title' => $category === 'UPGRADE' ? (string) preg_replace('/^upgrade\s*-\s*/i', '', $title) : $title,
                    'isShip' => false,
                ];
                if ($category !== null) {
                    $e['category'] = $category;
                }
                $e['lti'] = $lti;
                $e['quantity'] = 1;
                $entries[] = $e;
            }
        }
        $handle = isset($data['handle']) && is_string($data['handle']) ? trim($data['handle']) : '';
        // Nur ein gültiger RSI-Handle (Buchstaben, Ziffern, _ und -) wird übernommen; alles andere bleibt unberücksichtigt.
        $valid = preg_match('/^[A-Za-z0-9_-]{2,100}$/', $handle) === 1;
        return ['handle' => $valid ? $handle : null, 'entries' => $entries, 'format' => 'hangarexport'];
    }

    /**
     * Prüft eine HangarXPLOR-Zeile (ship ist Pflicht und muss bool sein); null, wenn sie nicht passt.
     * @return array<string,mixed>|null
     */
    private static function xplorEntry(mixed $e): ?array
    {
        if (!is_array($e) || !isset($e['ship']) || !is_bool($e['ship'])) {
            return null;
        }
        foreach (['name'] as $k) {
            if (isset($e[$k]) && !is_string($e[$k])) {
                return null;
            }
        }
        foreach (['lti', 'gear', 'upgrade'] as $k) {
            if (isset($e[$k]) && !is_bool($e[$k])) {
                return null;
            }
        }
        foreach (['ship_name', 'orig_name', 'ship_code'] as $k) {
            if (isset($e[$k]) && !is_string($e[$k])) {
                return null;
            }
        }
        return $e;
    }

    /** @param list<mixed> $list @return array{handle:?string,entries:list<array<string,mixed>>,format:string} */
    private static function parseXplor(array $list): array
    {
        $entries = [];
        foreach ($list as $raw) {
            $e = self::xplorEntry($raw);
            if ($e === null || !empty($e['upgrade'])) {
                continue;
            }
            if ($e['ship']) {
                $title = trim((string) (($e['ship_name'] ?? '') ?: ($e['orig_name'] ?? '')));
                if ($title === '') {
                    continue;
                }
                $orig = trim((string) ($e['orig_name'] ?? ''));
                $code = trim((string) ($e['ship_code'] ?? ''));
                $entry = ['title' => $title];
                if ($orig !== '' && $orig !== $title) {
                    $entry['altTitle'] = $orig;
                }
                if ($code !== '') {
                    $entry['code'] = $code;
                }
                $entry += ['isShip' => true, 'lti' => (bool) ($e['lti'] ?? false), 'quantity' => 1];
                $entries[] = $entry;
            } elseif (trim((string) ($e['name'] ?? '')) !== '') {
                // Rüstungen, Waffen und Bundles: nur verwertbar, wenn der Name im Katalog steht.
                $entries[] = [
                    'title' => trim((string) preg_replace('/^gear\s*-\s*/i', '', (string) $e['name'])),
                    'isShip' => false,
                    'lti' => false,
                    'quantity' => 1,
                ];
            }
        }
        return ['handle' => null, 'entries' => $entries, 'format' => 'hangarxplor'];
    }

    /** @param list<mixed> $list @return array{handle:?string,entries:list<array<string,mixed>>,format:string} */
    private static function parseSimple(array $list): array
    {
        $entries = [];
        foreach ($list as $e) {
            if (!is_array($e)) {
                continue;
            }
            $bad = false;
            foreach (['name', 'ship', 'title', 'model'] as $k) {
                if (isset($e[$k]) && !is_string($e[$k])) {
                    $bad = true;
                }
            }
            if (isset($e['lti']) && !is_bool($e['lti'])) {
                $bad = true;
            }
            $qty = 1;
            if (isset($e['quantity'])) {
                if (!is_numeric($e['quantity']) || (float) $e['quantity'] != (int) $e['quantity'] || (int) $e['quantity'] < 1 || (int) $e['quantity'] > 999) {
                    $bad = true;
                } else {
                    $qty = (int) $e['quantity'];
                }
            }
            if ($bad) {
                continue;
            }
            $title = trim((string) ($e['name'] ?? $e['ship'] ?? $e['title'] ?? $e['model'] ?? ''));
            if ($title === '') {
                continue;
            }
            $entries[] = ['title' => $title, 'isShip' => true, 'lti' => (bool) ($e['lti'] ?? false), 'quantity' => $qty];
        }
        return ['handle' => null, 'entries' => $entries, 'format' => 'liste'];
    }
}
