<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class StationSummary
{
    public function __construct(
        public string $stationId,
        public float $totalApprovedAmount,
        public int $eventsCount,
    ) {}
}
