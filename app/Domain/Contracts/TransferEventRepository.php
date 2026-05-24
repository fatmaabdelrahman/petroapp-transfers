<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Domain\IngestResult;
use App\Domain\StationSummary;
use App\Domain\TransferEvent;

/**
 * Port: storage-agnostic interface for transfer event persistence.
 *
 * Implementations MUST guarantee:
 *  - ingestBatch() is idempotent per event_id (duplicates are not re-stored)
 *  - ingestBatch() is concurrency-safe (two parallel calls with the same
 *    event_id result in exactly one row total)
 */
interface TransferEventRepository
{
    /**
     * Insert events, skipping any whose event_id already exists.
     *
     * @param  list<TransferEvent>  $events
     */
    public function ingestBatch(array $events): IngestResult;

    public function summaryFor(string $stationId): StationSummary;
}
