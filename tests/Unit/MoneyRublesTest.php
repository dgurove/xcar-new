<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

/** Сумма из формы — целые рубли: копейки отбрасываются, а не приклеиваются к рублям (16.10.2026). */
class MoneyRublesTest extends TestCase
{
    public function test_kopecks_are_dropped_not_glued(): void
    {
        $this->assertSame(150000, Money::rubles('150 000,50'));
        $this->assertSame(150000, Money::rubles('150000.50'));
        $this->assertSame(1150000, Money::rubles('1.150.000'));
        $this->assertSame(1150000, Money::rubles('1 150 000'));
        $this->assertSame(1450000, Money::rubles('1 450 000 ₽'));
        $this->assertSame(0, Money::rubles('0'));
        $this->assertNull(Money::rubles(''));
        $this->assertNull(Money::rubles(null));
        $this->assertNull(Money::rubles('абв'));
    }
}
