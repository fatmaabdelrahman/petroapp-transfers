<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IngestTransfersRequest;
use App\Services\TransferIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Handles POST /transfers.
 *
 * Intentionally thin — this controller has exactly three jobs:
 *  1. Accept a shape-validated HTTP request (via IngestTransfersRequest).
 *  2. Delegate all business logic to TransferIngestionService.
 *  3. Return the HTTP response.
 *
 * No validation loops, no DTO construction, no repository calls here.
 */
final class TransferController extends Controller
{
    public function __construct(
        private readonly TransferIngestionService $service,
    ) {}

    public function store(IngestTransfersRequest $request): JsonResponse
    {
        $response = $this->service->ingest(
            $request->input('events', [])
        );

        Log::info('transfers.ingest', [
            'inserted'   => $response->inserted,
            'duplicates' => $response->duplicates,
            'rejected'   => count($response->rejected),
        ]);

        return response()->json([
            'inserted'   => $response->inserted,
            'duplicates' => $response->duplicates,
            'rejected'   => $response->rejected,
        ]);
    }
}
