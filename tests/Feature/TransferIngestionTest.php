<?php

declare(strict_types=1);

/**
 * Helper: build a valid event payload with optional overrides.
 */
function eventPayload(array $overrides = []): array
{
    return array_merge([
        'event_id'   => 'evt-' . bin2hex(random_bytes(6)),
        'station_id' => 'S1',
        'amount'     => 100.50,
        'status'     => 'approved',
        'created_at' => '2026-02-19T10:00:00Z',
    ], $overrides);
}

it('returns correct inserted/duplicates counts on batch insert', function () {
    $events = [
        eventPayload(['event_id' => 'a', 'amount' => 10]),
        eventPayload(['event_id' => 'b', 'amount' => 20]),
        eventPayload(['event_id' => 'c', 'amount' => 30]),
    ];

    $first = $this->postJson('/transfers', ['events' => $events]);
    $first->assertOk()->assertJson(['inserted' => 3, 'duplicates' => 0]);

    // Re-send the same batch: all three should now be duplicates.
    $second = $this->postJson('/transfers', ['events' => $events]);
    $second->assertOk()->assertJson(['inserted' => 0, 'duplicates' => 3]);
});

it('does not change totals when a duplicate event is re-ingested', function () {
    $event = eventPayload(['event_id' => 'dup-1', 'amount' => 250, 'status' => 'approved']);

    $this->postJson('/transfers', ['events' => [$event]])->assertOk();
    $this->postJson('/transfers', ['events' => [$event]])->assertOk();
    $this->postJson('/transfers', ['events' => [$event]])->assertOk();

    $this->getJson('/stations/S1/summary')
        ->assertOk()
        ->assertJson([
            'station_id'            => 'S1',
            'total_approved_amount' => 250.00,
            'events_count'          => 1,
        ]);
});

it('produces the same totals regardless of arrival order', function () {
    $a = eventPayload(['event_id' => 'a', 'amount' => 50,  'created_at' => '2026-02-19T10:00:00Z']);
    $b = eventPayload(['event_id' => 'b', 'amount' => 75,  'created_at' => '2026-02-19T09:00:00Z']);
    $c = eventPayload(['event_id' => 'c', 'amount' => 125, 'created_at' => '2026-02-19T11:00:00Z']);

    // Out-of-order ingestion.
    $this->postJson('/transfers', ['events' => [$c, $a, $b]])->assertOk();

    $this->getJson('/stations/S1/summary')
        ->assertOk()
        ->assertJson([
            'total_approved_amount' => 250.00,
            'events_count'          => 3,
        ]);
});

it('returns a per-station summary that ignores other stations', function () {
    $this->postJson('/transfers', ['events' => [
        eventPayload(['event_id' => 's1-1', 'station_id' => 'S1', 'amount' => 100, 'status' => 'approved']),
        eventPayload(['event_id' => 's1-2', 'station_id' => 'S1', 'amount' => 200, 'status' => 'approved']),
        eventPayload(['event_id' => 's1-3', 'station_id' => 'S1', 'amount' => 999, 'status' => 'pending']),
        eventPayload(['event_id' => 's2-1', 'station_id' => 'S2', 'amount' => 500, 'status' => 'approved']),
    ]])->assertOk()->assertJson(['inserted' => 4, 'duplicates' => 0]);

    $this->getJson('/stations/S1/summary')->assertOk()->assertJson([
        'station_id'            => 'S1',
        'total_approved_amount' => 300.00, // pending is ignored
        'events_count'          => 3,      // all statuses counted
    ]);

    $this->getJson('/stations/S2/summary')->assertOk()->assertJson([
        'station_id'            => 'S2',
        'total_approved_amount' => 500.00,
        'events_count'          => 1,
    ]);
});

it('returns 404 for an unknown station', function () {
    $this->getJson('/stations/UNKNOWN/summary')
        ->assertNotFound()
        ->assertJson(['message' => "Station 'UNKNOWN' not found."]);
});

it('only counts approved events toward the total but counts all statuses in events_count', function () {
    $this->postJson('/transfers', ['events' => [
        eventPayload(['event_id' => 'e1', 'amount' => 100, 'status' => 'approved']),
        eventPayload(['event_id' => 'e2', 'amount' => 200, 'status' => 'rejected']),
        eventPayload(['event_id' => 'e3', 'amount' => 300, 'status' => 'something-unknown']),
    ]])->assertOk();

    $this->getJson('/stations/S1/summary')
        ->assertOk()
        ->assertJson([
            'total_approved_amount' => 100.00,
            'events_count'          => 3,
        ]);
});

it('rejects bad events but ingests the good ones (partial accept)', function () {
    $response = $this->postJson('/transfers', ['events' => [
        eventPayload(['event_id' => 'good-1', 'amount' => 50]),
        eventPayload(['event_id' => 'bad-1',  'amount' => -10]),       // negative
        eventPayload(['event_id' => 'bad-2',  'created_at' => 'nope']), // bad date
        eventPayload(['event_id' => 'good-2', 'amount' => 25]),
    ]]);

    $response->assertOk()
        ->assertJson(['inserted' => 2, 'duplicates' => 0]);

    expect($response->json('rejected'))->toHaveCount(2);

    $this->getJson('/stations/S1/summary')
        ->assertOk()
        ->assertJson(['total_approved_amount' => 75.00, 'events_count' => 2]);
});

it('returns 400 when the payload shape is invalid', function () {
    $this->postJson('/transfers', ['not_events' => []])
        ->assertStatus(400);

    $this->postJson('/transfers', ['events' => []])
        ->assertStatus(400);
});

it('de-duplicates within a single batch (same event_id sent twice)', function () {
    $event = eventPayload(['event_id' => 'twin', 'amount' => 42]);

    $response = $this->postJson('/transfers', ['events' => [$event, $event]]);

    $response->assertOk()->assertJson(['inserted' => 1, 'duplicates' => 1]);

    $this->getJson('/stations/S1/summary')
        ->assertOk()
        ->assertJson(['total_approved_amount' => 42.00, 'events_count' => 1]);
});
