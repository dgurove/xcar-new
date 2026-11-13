<?php

namespace Tests\Unit;

use App\Offers\SigningReply;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/** Ответы Альфы на запись на подписание (13.11.2026, BMW X6 № 2610041185, тексты с прода). */
class SigningReplyTest extends TestCase
{
    public function test_offered_hours_on_a_day(): void
    {
        $slots = SigningReply::offered(mb_strtolower('Добрый день! Есть на 13.11.2026 11,12,13 На какое записать?'), Carbon::parse('2026-11-12 13:32'));

        $this->assertSame(['2026-11-13 11:00', '2026-11-13 12:00', '2026-11-13 13:00'], array_map(fn ($s) => $s->format('Y-m-d H:i'), $slots));
    }

    public function test_time_from_our_reply_and_day_from_their_offer(): void
    {
        $at = SigningReply::when('13:00', Carbon::parse('2026-11-12 13:34'), Carbon::create(2026, 11, 13, 0, 0, 0, 'Europe/Moscow'));

        $this->assertSame('2026-11-13 13:00', $at->format('Y-m-d H:i'));
    }

    public function test_day_is_not_read_as_time(): void
    {
        $at = SigningReply::when('записали на 13.11 в 13:00', Carbon::parse('2026-11-12 13:34'));

        $this->assertSame('2026-11-13 13:00', $at->format('Y-m-d H:i'));
    }
}
