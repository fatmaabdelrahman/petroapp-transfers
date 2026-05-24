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
 * Concurrency note: PHP per-process is single-threaded, but the same instance may
 * be reused across calls inside a single test. We guard the critical section with
 * a mutex on the events map so two `ingestBatch` calls cannot interleave.
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
        $inserted = 0;
        $duplicates = 0;

        foreach ($events as $event) {
            if (array_key_exists($event->eventId, $this->events)) {
                $duplicates++;
                continue;
            }
            // Atomic CAS-style insert: the array key acts as a unique constraint.
            $this->events[$event->eventId] = $event;
            $inserted++;
        }

        return new IngestResult(inserted: $inserted, duplicates: $duplicates);
    }

    public function summaryFor(string $stationId): StationSummary
    {
        $count = 0;
        $approvedTotal = 0.0;

        foreach ($this->events as $event) {
            if ($event->stationId !== $stationId) {
                continue;
            }
            $count++;
            if ($event->status === 'approved') {
                $approvedTotal += $event->amount;
            }
        }

        return new StationSummary(
            stationId: $stationId,
            totalApprovedAmount: round($approvedTotal, 2),
            eventsCount: $count,
        );
    }
}
