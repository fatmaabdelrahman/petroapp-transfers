<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transfer_events', function (Blueprint $table) {
            // event_id is the natural primary key — globally unique per spec.
            // This is what enforces idempotency + concurrency safety at the DB layer.
            $table->string('event_id', 64)->primary();
            $table->string('station_id', 64);
            $table->decimal('amount', 14, 2);
            $table->string('status', 32);
            $table->timestampTz('created_at');
            $table->timestampTz('ingested_at')->useCurrent();

            // Speeds up the summary aggregate query.
            $table->index(['station_id', 'status'], 'idx_station_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_events');
    }
};
