<?php

namespace Tests;

use App\Models\Cafe;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cafe::creating(function (Cafe $cafe): void {
            if (! array_key_exists('subscription_legacy_unrestricted', $cafe->getAttributes())) {
                $cafe->setAttribute('subscription_legacy_unrestricted', true);
            }
        });
    }
}
