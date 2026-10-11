<?php

declare(strict_types=1);

namespace Hangar\Import;

use Hangar\Achievements;
use Hangar\Db;

/** Vorschau und Übernahme eines Imports in den Hangar eines Nutzers. */
final class Importer
{
    /**
     * Erstellt den Importplan, ohne etwas zu schreiben (Vorschau).
     * @return array<string,mixed> Plan plus "replaced" (importierte Einträge, die ersetzt werden)
     * @throws ImportFormatError
     */
    public static function buildPlan(string $userId, mixed $json): array
    {
        $parsed = Parser::parse($json);
        $rows = Db::all("SELECT id, kind, slug, name, match_key, alt_match_key, code_key, manufacturer FROM catalog_items WHERE kind IN ('SHIP','ARMOR')");
        $plan = Planner::plan($parsed, Matcher::buildIndex($rows));
        $plan['replaced'] = (int) Db::val("SELECT COUNT(*) FROM owned_items WHERE user_id = ? AND source = 'IMPORT'", [$userId]);
        return $plan;
    }

    /**
     * Übernimmt den Plan: Alle importierten Einträge des Nutzers werden durch den RSI-Stand
     * ersetzt. Manuell hinzugefügte Einträge (source MANUAL) bleiben unberührt. Der RSI-Handle
     * aus dem Sync gilt immer.
     *
     * @param array<string,mixed> $plan
     */
    public static function applyPlan(string $userId, array $plan, string $source): void
    {
        Db::transaction(function () use ($userId, $plan, $source): void {
            Db::run("DELETE FROM owned_items WHERE user_id = ? AND source = 'IMPORT'", [$userId]);

            $insert = static function (array $row) use ($userId): void {
                Db::insert('owned_items', [
                    'id' => new_id(),
                    'user_id' => $userId,
                    'catalog_item_id' => $row['catalog_item_id'] ?? null,
                    'custom_name' => isset($row['custom_name']) ? mb_substr((string) $row['custom_name'], 0, 190) : null,
                    'kind' => $row['kind'],
                    'quantity' => $row['quantity'],
                    'lti' => $row['lti'] ? 1 : 0,
                    'source' => 'IMPORT',
                ]);
            };
            foreach ($plan['matched'] as $m) {
                $insert(['catalog_item_id' => $m['catalogItemId'], 'kind' => $m['kind'], 'quantity' => $m['quantity'], 'lti' => $m['lti']]);
            }
            foreach ($plan['unmatched'] as $u) {
                $insert(['custom_name' => $u['name'], 'kind' => 'SHIP', 'quantity' => $u['quantity'], 'lti' => $u['lti']]);
            }
            foreach ($plan['others'] as $o) {
                $insert(['custom_name' => $o['name'], 'kind' => $o['kind'], 'quantity' => $o['quantity'], 'lti' => false]);
            }

            Db::insert('import_logs', [
                'id' => new_id(),
                'user_id' => $userId,
                'source' => $source,
                'created' => count($plan['matched']) + count($plan['unmatched']) + count($plan['others']),
                'updated' => (int) ($plan['replaced'] ?? 0),
                'unmatched' => count($plan['unmatched']),
            ]);

            // RSI-Handle aus der angemeldeten RSI-Sitzung übernehmen.
            if (!empty($plan['handle'])) {
                Db::run('UPDATE users SET rsi_handle = ? WHERE id = ?', [$plan['handle'], $userId]);
            }
        });

        Achievements::recompute($userId);
    }
}
