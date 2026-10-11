<?php

declare(strict_types=1);

namespace Hangar;

/** Erlaubte Werte der Textspalten und feste Beschriftungen (Quelle: src/lib/constants.ts). */
final class Constants
{
    public const VISIBILITIES = ['PRIVATE', 'MEMBERS'];

    // SHIP und ARMOR stammen aus dem Katalog; UPGRADE, PAINT und ITEM gibt es nur im Hangar.
    public const ITEM_KINDS = ['SHIP', 'ARMOR', 'WEAPON', 'ITEM', 'UPGRADE', 'PAINT'];
    public const OTHER_KINDS = ['UPGRADE', 'PAINT', 'ITEM'];

    public const KIND_LABELS = [
        'SHIP' => 'Schiffe',
        'ARMOR' => 'Rüstungen',
        'WEAPON' => 'Waffen',
        'ITEM' => 'Sonstiges (Loot, Ausrüstung)',
        'UPGRADE' => 'Upgrades (CCU)',
        'PAINT' => 'Paints & Skins',
    ];

    public const ITEM_SOURCES = ['IMPORT', 'MANUAL'];
    public const ORG_ROLES = ['ADMIN', 'MEMBER'];

    // Herkunft der Katalogeinträge
    public const SOURCE_MATRIX = 'RSI_MATRIX';
    public const SOURCE_FLEETYARDS = 'FLEETYARDS';
    public const SOURCE_WIKI = 'WIKI';

    public const RSI_PLEDGES_URL = 'https://robertsspaceindustries.com/en/account/pledges';
    public const RSI_BASE_URL = 'https://robertsspaceindustries.com';
    public const DEFAULT_SHIP_MATRIX_URL = 'https://robertsspaceindustries.com/ship-matrix/index';

    public const EVENT_STATUSES = ['PLANNED', 'CANCELLED', 'DRAFT'];
    public const RSVP_STATUSES = ['YES', 'MAYBE', 'NO'];

    public static function isKind(string $kind): bool
    {
        return in_array($kind, self::ITEM_KINDS, true);
    }
}
