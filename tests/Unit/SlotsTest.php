<?php

namespace Tests\Unit;

use App\Offers\Slots;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Слот 16:00 и срок приёма 21:00 через три дня. Ошибка здесь молчалива и дорога: пачка выйдет не в тот день или приём
 * закроется не тогда, и никто этого не заметит до жалобы менеджера.
 */
class SlotsTest extends TestCase
{
    public function test_nearest_and_next_slot_around_16(): void
    {
        $morning = Carbon::parse('2026-10-03 09:30');
        $this->assertSame('2026-10-03 16:00', Slots::nearest($morning)->format('Y-m-d H:i'));
        $this->assertSame('2026-10-04 16:00', Slots::next($morning)->format('Y-m-d H:i'));

        // Ровно 16:00 — этот слот уже вышел, ближайший завтра.
        $this->assertSame('2026-10-04 16:00', Slots::nearest(Carbon::parse('2026-10-03 16:00'))->format('Y-m-d H:i'));
        $this->assertSame('2026-10-03 16:00', Slots::nearest(Carbon::parse('2026-10-03 15:59'))->format('Y-m-d H:i'));

        $night = Carbon::parse('2026-10-03 23:50');
        $this->assertSame('2026-10-04 16:00', Slots::nearest($night)->format('Y-m-d H:i'));
        $this->assertSame('2026-10-05 16:00', Slots::next($night)->format('Y-m-d H:i'));

        $this->assertNull(Slots::at(Slots::NOW, $morning));
    }

    public function test_bids_close_three_days_later_at_17(): void
    {
        $this->assertSame('2026-10-07 17:00', Slots::closeFor(Carbon::parse('2026-10-04 16:00'))->format('Y-m-d H:i'));
        // «Сейчас» поздно вечером — всё равно третий день после публикации, 17:00.
        $this->assertSame('2026-10-06 17:00', Slots::closeFor(Carbon::parse('2026-10-03 23:40'))->format('Y-m-d H:i'));
        $this->assertSame('2026-10-04 17:00', Slots::closeFor(Carbon::parse('2026-10-03 10:00'), 1)->format('Y-m-d H:i'));
    }
}
