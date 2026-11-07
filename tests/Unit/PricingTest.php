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

    /** Ступени предложения (поставщик на partner.xcar.ru, 07.11.2026): свои границы и наценки, без наценки — без округления. */
    public function test_own_steps_and_flat(): void
    {
        $own = [[500_000, 50_000, 0], [2_000_000, 0, 12], [5_000_000, 300_000, 0], [null, 200_000, 0]];
        $this->assertSame(1_680_000, Pricing::floorFrom(1_500_000, $own));
        $this->assertSame(3_300_000, Pricing::floorFrom(3_000_000, $own));
        $this->assertSame(6_200_000, Pricing::floorFrom(6_000_000, $own));
        $this->assertSame(207_350, Pricing::floorFrom(207_350, Pricing::FLAT));
        // Сменили ступени при той же оценочной — закупочная по новым, если была посчитана по прежним.
        $this->assertSame(1_680_000, Pricing::floorFor(1_650_000, 1_500_000, 1_500_000, $own, Pricing::RULES));
        $this->assertNull(Pricing::floorFor(1_700_000, 1_500_000, 1_500_000, $own, Pricing::RULES));
        $this->assertSame('1 680 000 ₽ (+12%)', str_replace("\u{A0}", ' ', Pricing::floorLabel(1_680_000, 1_500_000, $own)));
    }

    /** Из формы ступени приходят строками с разрядами; не сходятся — null, последняя всегда «дальше». */
    public function test_clean(): void
    {
        $this->assertSame([[500_000, 50_000, 0], [2_000_000, 0, 7.5], [null, 1, 0]], Pricing::clean([['500 000', '50 000', '0'], [2_000_000, 0, '7,5'], [3_000_000, 1, 0]]));
        $this->assertNull(Pricing::clean([[2_000_000, 0, 0], [500_000, 0, 0], [null, 0, 0]]));
        $this->assertNull(Pricing::clean([[500_000, 0, 150], [null, 0, 0]]));
        $this->assertNull(Pricing::clean('[]'));
        $this->assertSame([[null, 0, 10]], Pricing::clean('[[100, 0, 10]]'));
    }
}
