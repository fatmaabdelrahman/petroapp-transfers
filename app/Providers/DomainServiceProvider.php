<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Contracts\TransferEventRepository;
use App\Infrastructure\Persistence\EloquentTransferEventRepository;
use Illuminate\Support\ServiceProvider;

final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Default binding: Postgres-backed repository.
        // Swap to InMemoryTransferEventRepository in tests when desired.
        $this->app->singleton(
            TransferEventRepository::class,
            EloquentTransferEventRepository::class,
        );
    }
}
