<?php

declare(strict_types=1);

namespace App\Services;

use App\Domain\Contracts\TransferEventRepository;
use App\Domain\TransferEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * Orchestrates the ingestion of a batch of raw transfer events.
 *
 * Responsibilities:
 *  1. Per-event content validation (partial-accept strategy).
 *  2. Build TransferEvent value objects from validated raw data.
 *  3. Delegate storage to the repository.
 *  4. Return a typed IngestResponse.
 *
 * This class is intentionally free of HTTP concerns — it knows nothing
 * about requests, responses, or status codes.
 */
final class TransferIngestionService
{
    public function __construct(
        private readonly TransferEventRepository $repository,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rawEvents
     */
    public function ingest(array $rawEvents): IngestResponse
    {
        $valid    = [];
        $rejected = [];

        foreach ($rawEvents as $idx => $raw) {
            $validator = Validator::make(is_array($raw) ? $raw : [], [
                'event_id'   => ['required', 'string', 'max:64'],
                'station_id' => ['required', 'string', 'max:64'],
                'amount'     => ['required', 'numeric', 'min:0'],
                'status'     => ['required', 'string', 'max:32'],
                'created_at' => ['required', 'string', 'date'],
            ]);

            if ($validator->fails()) {
                $rejected[] = [
                    'index'    => $idx,
                    'event_id' => is_array($raw) ? ($raw['event_id'] ?? null) : null,
                    'errors'   => $validator->errors()->all(),
                ];
                continue;
            }

            // Amount is kept as string to preserve NUMERIC(14,2) precision.
            // Floats cannot represent all decimal values exactly.
            $valid[] = new TransferEvent(
                eventId:   (string) $raw['event_id'],
                stationId: (string) $raw['station_id'],
                amount:    (string) $raw['amount'],
                status:    (string) $raw['status'],
                createdAt: CarbonImmutable::parse($raw['created_at']),
            );
        }

        $result = $this->repository->ingestBatch($valid);

        return new IngestResponse(
            inserted:   $result->inserted,
            duplicates: $result->duplicates,
            rejected:   $rejected,
        );
    }
}
