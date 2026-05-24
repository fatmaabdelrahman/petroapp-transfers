<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');
// Apply RefreshDatabase to every Feature test EXCEPT the concurrency test,
// which talks to a live HTTP server via forked processes and must not run
// inside a parent-process transaction.
uses(RefreshDatabase::class)->in('Feature/TransferIngestionTest.php');
