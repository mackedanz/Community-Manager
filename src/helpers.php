<?php

declare(strict_types=1);

/** HTML-Escaping für Templates. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Datum und Uhrzeit in Berlin, z. B. "05.10.2026, 09:33:00"; "–" bei leerem Wert. */
function dt(?\DateTimeInterface $d): string
{
    return $d === null ? '–' : \DateTimeImmutable::createFromInterface($d)->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y, H:i:s');
}

/** Neue ID für Datenbankzeilen (24 Hex-Zeichen, kryptografisch zufällig). */
function new_id(): string
{
    return bin2hex(random_bytes(12));
}
