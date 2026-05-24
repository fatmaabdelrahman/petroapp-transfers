<?php

declare(strict_types=1);

/**
 * Concurrency proof.
 *
 * The previous tests prove correctness sequentially. This test proves that the
 * Postgres `ON CONFLICT DO NOTHING` strategy genuinely survives concurrent writes.
 *
 * Strategy: fork N child processes (pcntl), each posts the same event_id to the
 * running HTTP app. After all children exit, assert:
 *   - exactly 1 row exists for that event_id
 *   - the sum of `inserted` across all responses equals 1
 *   - the sum of `duplicates` equals N-1
 *
 * This test only runs against the dockerised app via CONCURRENCY_BASE_URL.
 * When that env var isn't set, the test is skipped — keeping `make test`
 * (which runs unit + feature tests in-process) green by default.
 */
it('does not double-insert under concurrent POSTs with the same event_id', function () {
    $baseUrl = getenv('CONCURRENCY_BASE_URL') ?: null;
    if (! $baseUrl) {
        $this->markTestSkipped('Set CONCURRENCY_BASE_URL (e.g. http://app:8000) to run.');
    }
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl not available.');
    }

    $suffix    = bin2hex(random_bytes(6));
    $eventId   = "race-$suffix";
    $stationId = "RACE-$suffix";
    $n         = 25;

    $payload = json_encode(['events' => [[
        'event_id'   => $eventId,
        'station_id' => $stationId,
        'amount'     => 10.00,
        'status'     => 'approved',
        'created_at' => '2026-02-19T10:00:00Z',
    ]]], JSON_THROW_ON_ERROR);

    $tmpDir = sys_get_temp_dir();
    $pids = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            // Child
            $ch = curl_init($baseUrl . '/transfers');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 10,
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            file_put_contents("$tmpDir/race-$eventId-$i.json", (string) $body);
            exit(0);
        }
        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $insertedSum = 0;
    $duplicateSum = 0;
    for ($i = 0; $i < $n; $i++) {
        $path = "$tmpDir/race-$eventId-$i.json";
        $body = file_get_contents($path);
        @unlink($path);
        $decoded = json_decode((string) $body, true);
        $insertedSum  += (int) ($decoded['inserted'] ?? 0);
        $duplicateSum += (int) ($decoded['duplicates'] ?? 0);
    }

    expect($insertedSum)->toBe(1, 'Exactly one request should have inserted the row.');
    expect($duplicateSum)->toBe($n - 1, 'All other requests should report duplicate.');

    // Verify on disk via the summary endpoint.
    $ch = curl_init("$baseUrl/stations/$stationId/summary");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $summary = json_decode((string) curl_exec($ch), true);
    curl_close($ch);

    expect($summary['events_count'])->toBe(1);
    expect((float) $summary['total_approved_amount'])->toBe(10.00);
});
