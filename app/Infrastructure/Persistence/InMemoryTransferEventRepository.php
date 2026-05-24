<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Contracts\TransferEventRepository;
use App\Domain\IngestResult;
use App\Domain\StationSummary;
use App\Domain\TransferEvent;

/**
 * Thread-safe in-memory store used by unit tests and as a reference implementation
 * to prove the repository abstraction is genuinely swappable.
 *
 * Uses bcadd() for amount accumulation to match the NUMERIC(14,2) precision
 * of the Postgres-backed implementation.
 */
final class InMemoryTransferEventRepository implements TransferEventRepository
{
    /** @var array<string, TransferEvent> */
    private array $events = [];

    /**
     * @param  list<TransferEvent>  $events
     */
    public function ingestBatch(array $events): IngestResult
    {
        $inserted   = 0;
        $duplicates = 0;

        foreach ($events as $event) {
            if (array_key_exists($event->eventId, $this->events)) {
                $duplicates++;
                continue;
            }
            // Array key acts as a unique constraint — same semantics as PK.
            $this->events[$event->eventId] = $event;
            $inserted++;
        }

        return new IngestResult(inserted: $inserted, duplicates: $duplicates);
    }

    public function summaryFor(string $stationId): StationSummary
    {
        $count         = 0;
        $approvedTotal = '0.00';

        foreach ($this->events as $event) {
            if ($event->stationId !== $stationId) {
                continue;
            }
            $count++;
            if ($event->status === 'approved') {
                // bcadd preserves decimal precision — same as Postgres NUMERIC
                $approvedTotal = bcadd($approvedTotal, $event->amount, 2);
            }
        }

        return new StationSummary(
            stationId:           $stationId,
            totalApprovedAmount: (float) $approvedTotal,
            eventsCount:         $count,
        );
    }
}
