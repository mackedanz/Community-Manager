<?php

declare(strict_types=1);

namespace Hangar;

use DateTimeImmutable;

/** Aufbereitung eines Events als Briefing im Discord-Markdown. */
final class EventBriefing
{
    /** Discord erlaubt 2000 Zeichen pro Nachricht; etwas Luft für Sonderzeichen. */
    public const DISCORD_LIMIT = 1900;

    private static function stamp(DateTimeImmutable $d, string $style): string
    {
        return '<t:' . $d->getTimestamp() . ':' . $style . '>';
    }

    /**
     * @param array{title:string,description:?string,location:?string,startsAt:DateTimeImmutable,endsAt:?DateTimeImmutable,cancelled:bool,ships:list<array{name:string,task:?string,slots:list<array{label:string,userName:?string}>}>,yes:list<string>,maybe:list<string>} $e
     * @return list<string>
     */
    public static function lines(array $e): array
    {
        $lines = [];
        $lines[] = '# ' . ($e['cancelled'] ? 'ABGESAGT: ' : '') . $e['title'];
        $lines[] = '**Wann:** ' . self::stamp($e['startsAt'], 'F') . ($e['endsAt'] ? ' bis ' . self::stamp($e['endsAt'], 't') : '');
        if (!empty($e['location'])) {
            $lines[] = '**Treffpunkt:** ' . $e['location'];
        }
        if (isset($e['description']) && trim($e['description']) !== '') {
            array_push($lines, '', trim($e['description']));
        }
        if ($e['ships'] !== []) {
            array_push($lines, '', '## Schiffe');
            foreach ($e['ships'] as $s) {
                $lines[] = "**{$s['name']}**" . (!empty($s['task']) ? " – {$s['task']}" : '');
                foreach ($s['slots'] as $slot) {
                    $lines[] = "- {$slot['label']}: " . ($slot['userName'] ?? '_offen_');
                }
            }
        }
        if ($e['yes'] !== [] || $e['maybe'] !== []) {
            array_push($lines, '', '## Zusagen');
            if ($e['yes'] !== []) {
                $lines[] = 'Dabei (' . count($e['yes']) . '): ' . implode(', ', $e['yes']);
            }
            if ($e['maybe'] !== []) {
                $lines[] = 'Vielleicht (' . count($e['maybe']) . '): ' . implode(', ', $e['maybe']);
            }
        }
        return $lines;
    }

    /**
     * Teilt das Briefing an Zeilengrenzen in Blöcke, die in eine Discord-Nachricht passen.
     * @param array<string,mixed> $e
     * @return list<string>
     */
    public static function format(array $e, int $limit = self::DISCORD_LIMIT): array
    {
        $chunks = [];
        $current = '';
        foreach (self::lines($e) as $raw) {
            // Eine einzelne überlange Zeile hart teilen
            $parts = mb_strlen($raw) > $limit ? mb_str_split($raw, $limit) : [$raw];
            foreach ($parts as $line) {
                $next = $current !== '' ? "$current\n$line" : $line;
                if (mb_strlen($next) > $limit && $current !== '') {
                    $chunks[] = $current;
                    $current = $line;
                } else {
                    $current = $next;
                }
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }
        return $chunks;
    }
}
