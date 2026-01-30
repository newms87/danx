<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Newms87\Danx\Traits\UsesTestLock;

abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;
    use UsesTestLock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshTestLockHeartbeat();
    }
}
