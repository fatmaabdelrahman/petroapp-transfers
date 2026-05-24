<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Contracts\TransferEventRepository;
use Illuminate\Http\JsonResponse;

final class StationSummaryController extends Controller
{
    public function __construct(private readonly TransferEventRepository $repository) {}

    /**
     * GET /stations/{station_id}/summary
     *
     * Returns 404 if the station has no recorded events — a station only
     * "exists" in this system once it has received at least one transfer event.
     * We have no separate stations table, so zero events = unknown station.
     *
     * `events_count`          → all statuses (spec default, documents reality of what arrived).
     * `total_approved_amount` → approved only (spec requirement).
     */
    public function show(string $stationId): JsonResponse
    {
        $summary = $this->repository->summaryFor($stationId);

        if ($summary->eventsCount === 0) {
            return response()->json([
                'message' => "Station '{$stationId}' not found.",
            ], 404);
        }

        return response()->json([
            'station_id'            => $summary->stationId,
            'total_approved_amount' => round($summary->totalApprovedAmount, 2),
            'events_count'          => $summary->eventsCount,
        ]);
    }
}
