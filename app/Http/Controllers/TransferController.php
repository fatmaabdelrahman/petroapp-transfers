<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Contracts\TransferEventRepository;
use App\Domain\TransferEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

final class TransferController extends Controller
{
    public function __construct(private readonly TransferEventRepository $repository) {}

    /**
     * POST /transfers
     *
     * Batch strategy: PARTIAL ACCEPT.
     *   - Payload shape errors → 400.
     *   - Per-event validation errors → that event is rejected but the rest are ingested.
     *   - Response is always {inserted, duplicates, rejected[]}.
     */
    public function store(Request $request): JsonResponse
    {
        // 1) Payload shape: must be {events: [...]}.
        $shape = Validator::make($request->all(), [
            'events'   => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'array'],
        ]);

        if ($shape->fails()) {
            return response()->json([
                'message' => 'Invalid payload shape.',
                'errors'  => $shape->errors(),
            ], 400);
        }

        // 2) Per-event validation: collect valid events, record rejections.
        $valid = [];
        $rejected = [];

        foreach ($request->input('events', []) as $idx => $raw) {
            $perEvent = Validator::make(is_array($raw) ? $raw : [], [
                'event_id'   => ['required', 'string', 'max:64'],
                'station_id' => ['required', 'string', 'max:64'],
                'amount'     => ['required', 'numeric', 'min:0'],
                'status'     => ['required', 'string', 'max:32'],
                'created_at' => ['required', 'string', 'date'],
            ]);

            if ($perEvent->fails()) {
                $rejected[] = [
                    'index'    => $idx,
                    'event_id' => is_array($raw) ? ($raw['event_id'] ?? null) : null,
                    'errors'   => $perEvent->errors()->all(),
                ];
                continue;
            }

            $valid[] = new TransferEvent(
                eventId:   (string) $raw['event_id'],
                stationId: (string) $raw['station_id'],
                amount:    (float) $raw['amount'],
                status:    (string) $raw['status'],
                createdAt: CarbonImmutable::parse($raw['created_at']),
            );
        }

        $result = $this->repository->ingestBatch($valid);

        Log::info('transfers.ingest', [
            'inserted'   => $result->inserted,
            'duplicates' => $result->duplicates,
            'rejected'   => count($rejected),
        ]);

        return response()->json([
            'inserted'   => $result->inserted,
            'duplicates' => $result->duplicates,
            'rejected'   => $rejected,
        ], 200);
    }
}
