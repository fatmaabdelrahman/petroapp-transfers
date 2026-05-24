<?php

declare(strict_types=1);

namespace App\Domain;

use Carbon\CarbonImmutable;

/**
 * Immutable domain value object for a transfer event.
 * Storage-agnostic: repositories accept/return these, not Eloquent models.
 */
final readonly class TransferEvent
{
    public function __construct(
        public string $eventId,
        public string $stationId,
        public float $amount,
        public string $status,
        public CarbonImmutable $createdAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event_id'   => $this->eventId,
            'station_id' => $this->stationId,
            'amount'     => $this->amount,
            'status'     => $this->status,
            'created_at' => $this->createdAt->toIso8601String(),
        ];
    }
}
