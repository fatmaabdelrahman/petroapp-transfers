<?php

declare(strict_types=1);

namespace App\Domain;

use Carbon\CarbonImmutable;

/**
 * Immutable domain value object for a transfer event.
 * Storage-agnostic: repositories accept/return these, not Eloquent models.
 *
 * `amount` is kept as string to preserve decimal precision.
 * Floats cannot represent all decimal values exactly (e.g. 0.1 + 0.2 ≠ 0.3).
 * The DB column is NUMERIC(14,2) which is exact — we must not lose precision in transit.
 */
final readonly class TransferEvent
{
    public function __construct(
        public string          $eventId,
        public string          $stationId,
        public string          $amount,
        public string          $status,
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
