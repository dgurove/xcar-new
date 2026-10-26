<?php

namespace Tests\Unit;

use App\Offers\Pricing;
use PHPUnit\Framework\TestCase;

/**
 * Закупочная от оценочной — деньги, ошибка в правиле молчалива (26.10.2026, приборка: правило было в четырёх копиях).
 * Границы ступеней и округление вверх до тысячи.
 */
class PricingTest extends TestCase
{
    public function test_floor_from_value_by_steps(): void
    {
        $this->assertSame(350_000, Pricing::floorFrom(300_000));
        $this->assertSame(550_000, Pricing::floorFrom(500_000));
        $this->assertSame(946_000, Pricing::floorFrom(860_000));
        $this->assertSame(2_200_000, Pricing::floorFrom(1_999_999));
        $this->assertSame(2_200_000, Pricing::floorFrom(2_000_000));
        $this->assertSame(2_701_000, Pricing::floorFrom(2_500_001));
    }

    public function test_ceil_thousand(): void
    {
        $this->assertSame(968_000, Pricing::ceilThousand(967_619));
        $this->assertSame(968_000, Pricing::ceilThousand(968_000));
    }

    public function test_floor_follows_value_unless_typed_by_hand(): void
    {
        // Пустая или посчитанная от прежней — заново; вписанная рукой — остаётся.
        $this->assertSame(1_100_000, Pricing::floorFor(null, null, 1_000_000));
        $this->assertSame(1_100_000, Pricing::floorFor(Pricing::floorFrom(900_000), 900_000, 1_000_000));
        $this->assertNull(Pricing::floorFor(777_000, 900_000, 1_000_000));
        $this->assertSame('1 100 000 ₽ (+10%)', str_replace("\u{A0}", ' ', Pricing::floorLabel(1_100_000, 1_000_000)));
        $this->assertSame('rule', Pricing::floorMode(1_100_000, 1_000_000));
        $this->assertSame('value', Pricing::floorMode(1_000_000, 1_000_000));
    }
}
