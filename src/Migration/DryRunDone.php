<?php

declare(strict_types=1);

namespace Hangar\Migration;

/** Wird am Ende eines Trockenlaufs geworfen, damit die Transaktion zurückgerollt wird. */
final class DryRunDone extends \RuntimeException
{
    /** @param array<string,array{source:int,imported:int}> $report */
    public function __construct(public readonly array $report)
    {
        parent::__construct('Trockenlauf');
    }
}
