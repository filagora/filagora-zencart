<?php

declare(strict_types=1);

use Filagora\ZenCart\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

require_once __DIR__.'/Support/stack.php';
