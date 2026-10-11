<?php

declare(strict_types=1);

namespace Hangar\Import;

use Hangar\Text;

/** Macht aus den geparsten Einträgen einen Importplan (Vorschau und Übernahme nutzen denselben). */
final class Planner
{
    /**
     * @param array{handle:?string,entries:list<array<string,mixed>>,format:string} $parsed
     * @param array<string,list<array<string,mixed>>> $index
     * @return array{format:string,handle:?string,matched:list<array<string,mixed>>,unmatched:list<array<string,mixed>>,others:list<array<string,mixed>>,ignored:int}
     */
    public static function plan(array $parsed, array $index): array
    {
        $matched = [];
        $unmatched = [];
        $others = [];
        $ignored = 0;

        foreach ($parsed['entries'] as $entry) {
            $key = Text::normalizeName($entry['title']);
            // Schiffe nur gegen Schiffe, sonstige Einträge gegen Rüstungen.
            $kind = $entry['isShip'] ? 'SHIP' : 'ARMOR';
            $hit = ($entry['category'] ?? null) === 'UPGRADE' ? null : Matcher::find($index, $kind, $entry);
            $lti = $entry['lti'] ? '1' : '0';

            if ($hit !== null) {
                $id = $hit['id'] . '|' . $lti;
                if (isset($matched[$id])) {
                    $matched[$id]['quantity'] = min(999, $matched[$id]['quantity'] + $entry['quantity']);
                } else {
                    $matched[$id] = [
                        'catalogItemId' => $hit['id'], 'name' => $hit['name'], 'kind' => $kind,
                        'lti' => $entry['lti'], 'quantity' => $entry['quantity'],
                    ];
                }
            } elseif ($entry['isShip']) {
                $id = $key . '|' . $lti;
                if (isset($unmatched[$id])) {
                    $unmatched[$id]['quantity'] = min(999, $unmatched[$id]['quantity'] + $entry['quantity']);
                } else {
                    $unmatched[$id] = ['name' => $entry['title'], 'lti' => $entry['lti'], 'quantity' => $entry['quantity']];
                }
            } elseif (isset($entry['category'])) {
                $id = $entry['category'] . '|' . $key;
                if (isset($others[$id])) {
                    $others[$id]['quantity'] = min(999, $others[$id]['quantity'] + $entry['quantity']);
                } else {
                    $others[$id] = ['name' => $entry['title'], 'kind' => $entry['category'], 'quantity' => $entry['quantity']];
                }
            } else {
                $ignored++;
            }
        }

        return [
            'format' => $parsed['format'],
            'handle' => $parsed['handle'],
            'matched' => array_values($matched),
            'unmatched' => array_values($unmatched),
            'others' => array_values($others),
            'ignored' => $ignored,
        ];
    }
}
