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
     * `events_count` includes events of ALL statuses (spec default).
     * `total_approved_amount` sums only events with status == "approved".
     */
    public function show(string $stationId): JsonResponse
    {
        $summary = $this->repository->summaryFor($stationId);

        return response()->json([
            'station_id'            => $summary->stationId,
            'total_approved_amount' => round($summary->totalApprovedAmount, 2),
            'events_count'          => $summary->eventsCount,
        ]);
    }
}
