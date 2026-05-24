<?php

declare(strict_types=1);

use App\Domain\TransferEvent;
use App\Infrastructure\Persistence\InMemoryTransferEventRepository;
use Carbon\CarbonImmutable;

// amount is string to match TransferEvent::$amount (preserves NUMERIC precision)
function makeEvent(string $id, string $station = 'S1', string $amount = '100.00', string $status = 'approved'): TransferEvent
{
    return new TransferEvent(
        eventId:   $id,
        stationId: $station,
        amount:    $amount,
        status:    $status,
        createdAt: CarbonImmutable::parse('2026-02-19T10:00:00Z'),
    );
}

it('proves the repository abstraction is swappable (in-memory impl behaves the same)', function () {
    $repo = new InMemoryTransferEventRepository();

    $result = $repo->ingestBatch([
        makeEvent('a', 'S1', '100.00', 'approved'),
        makeEvent('b', 'S1', '50.00',  'rejected'),
        makeEvent('a', 'S1', '999.00', 'approved'), // duplicate — must not change totals
    ]);

    expect($result->inserted)->toBe(2);
    expect($result->duplicates)->toBe(1);

    $summary = $repo->summaryFor('S1');
    expect($summary->totalApprovedAmount)->toBe(100.0);  // only event 'a', rejected is excluded
    expect($summary->eventsCount)->toBe(2);               // all statuses counted
});
