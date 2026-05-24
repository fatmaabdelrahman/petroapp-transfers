<?php

declare(strict_types=1);

use App\Domain\TransferEvent;
use App\Infrastructure\Persistence\InMemoryTransferEventRepository;
use Carbon\CarbonImmutable;

function makeEvent(string $id, string $station = 'S1', float $amount = 100.0, string $status = 'approved'): TransferEvent
{
    return new TransferEvent(
        eventId: $id,
        stationId: $station,
        amount: $amount,
        status: $status,
        createdAt: CarbonImmutable::parse('2026-02-19T10:00:00Z'),
    );
}

it('proves the repository abstraction is swappable (in-memory impl behaves the same)', function () {
    $repo = new InMemoryTransferEventRepository();

    $result = $repo->ingestBatch([
        makeEvent('a', 'S1', 100, 'approved'),
        makeEvent('b', 'S1', 50,  'rejected'),
        makeEvent('a', 'S1', 999, 'approved'), // duplicate
    ]);

    expect($result->inserted)->toBe(2);
    expect($result->duplicates)->toBe(1);

    $summary = $repo->summaryFor('S1');
    expect($summary->totalApprovedAmount)->toBe(100.0);
    expect($summary->eventsCount)->toBe(2);
});
