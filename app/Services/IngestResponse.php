<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Typed result returned by TransferIngestionService::ingest().
 *
 * Carrying the result as a value object instead of a raw array:
 *  - Makes the service contract explicit and IDE-friendly.
 *  - Prevents "what keys are in this array?" guessing further up the stack.
 */
final readonly class IngestResponse
{
    public function __construct(
        public int   $inserted,
        public int   $duplicates,
        /** @var list<array{index: int, event_id: string|null, errors: list<string>}> */
        public array $rejected,
    ) {}
}
