<?php

// The operations of the attention-screen concurrency tests (tests/Feature/Concurrency/AttentionConcurrencyTest.php),
// registered in every worker process. A worker loads only these registration files, so an operation declared anywhere else
// would simply not exist in it.

use Tests\Support\Concurrency\AttentionOperations;
use Tests\Support\Concurrency\Operations;

Operations::register('attention.review', [AttentionOperations::class, 'review']);
Operations::register('attention.put_back_after_reading', [AttentionOperations::class, 'putBackAfterReading']);
