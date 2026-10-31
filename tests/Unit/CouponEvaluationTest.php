<?php

namespace Tests\Unit;

use App\Models\Coupon;
use PHPUnit\Framework\TestCase;

class CouponEvaluationTest extends TestCase
{
    public function test_missing_coupon_returns_a_clear_invalid_coupon_message(): void
    {
        $evaluation = Coupon::evaluate(null, 55_000);

        $this->assertFalse($evaluation['valid']);
        $this->assertSame('Kupon geçersiz.', $evaluation['message']);
        $this->assertSame(0, $evaluation['discount_cents']);
    }
}
