<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Contracts\TransferEventRepository;
use App\Domain\IngestResult;
use App\Domain\StationSummary;
use App\Domain\TransferEvent;
use Illuminate\Support\Facades\DB;

/**
 * Postgres-backed repository.
 *
 * Concurrency / idempotency strategy:
 *   We use Postgres' `INSERT ... ON CONFLICT (event_id) DO NOTHING RETURNING event_id`.
 *   This is atomic at the storage layer: two concurrent inserts with the same event_id
 *   resolve to exactly one row, and the loser's statement returns zero rows.
 *   We derive `inserted` from RETURNING and `duplicates` from (batch_size - inserted).
 *   No application-level locks are needed.
 */
final class EloquentTransferEventRepository implements TransferEventRepository
{
    /**
     * @param  list<TransferEvent>  $events
     */
    public function ingestBatch(array $events): IngestResult
    {
        if ($events === []) {
            return new IngestResult(inserted: 0, duplicates: 0);
        }

        // De-duplicate within the same batch first so a single request that contains
        // the same event_id twice can't violate the PK constraint mid-statement.
        $byId = [];
        foreach ($events as $e) {
            $byId[$e->eventId] = $e;
        }
        $unique = array_values($byId);
        $intraBatchDupes = count($events) - count($unique);

        $placeholders = [];
        $bindings = [];
        foreach ($unique as $e) {
            $placeholders[] = '(?, ?, ?, ?, ?)';
            $bindings[] = $e->eventId;
            $bindings[] = $e->stationId;
            $bindings[] = $e->amount;
            $bindings[] = $e->status;
            $bindings[] = $e->createdAt->toIso8601String();
        }

        $sql = 'INSERT INTO transfer_events (event_id, station_id, amount, status, created_at) VALUES '
            . implode(', ', $placeholders)
            . ' ON CONFLICT (event_id) DO NOTHING RETURNING event_id';

        $rows = DB::select($sql, $bindings);
        $inserted = count($rows);
        $duplicates = count($unique) - $inserted + $intraBatchDupes;

        return new IngestResult(inserted: $inserted, duplicates: $duplicates);
    }

    public function summaryFor(string $stationId): StationSummary
    {
        $row = DB::selectOne(
            'SELECT
                COUNT(*) AS events_count,
                COALESCE(SUM(amount) FILTER (WHERE status = ?), 0) AS total_approved_amount
             FROM transfer_events
             WHERE station_id = ?',
            ['approved', $stationId]
        );

        return new StationSummary(
            stationId: $stationId,
            totalApprovedAmount: (float) ($row->total_approved_amount ?? 0),
            eventsCount: (int) ($row->events_count ?? 0),
        );
    }
}
