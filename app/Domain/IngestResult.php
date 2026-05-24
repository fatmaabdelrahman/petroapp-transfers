<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class IngestResult
{
    public function __construct(
        public int $inserted,
        public int $duplicates,
    ) {}
}
