<?php

namespace Tests\Unit;

use App\Offers\Actions\AutoOffer;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Не предложение, хоть поля и похожи (12.10.2026, прод): платёжка Совкома ответом Кате и пересылка письма полугодовой
 * давности завелись черновиками. Ошибка тут — левое письмо встаёт в «Без продажной цены» молча.
 */
class AutoOfferBlockerTest extends TestCase
{
    private const OFFER = "-------- Пересылаемое сообщение --------\nОт кого: Корочкин Михаил <mikhail.korochkin@sovcomins.ru>\n"
        ."Дата: Понедельник, 12 октября 2026, 10:12 +03:00\nТема: 666916-26 ЛАДА ЛАРГУС\n\nКоллеги, добрый день! ТС оценены в 253 810 руб.\n№ п/п 1";

    public function test_real_offer_passes(): void
    {
        $this->assertNull(AutoOffer::notAnOffer('Fwd: 666916-26 ЛАДА ЛАРГУС', self::OFFER, ['image001.png', 'Акт осмотра.pdf', '1.jpg'], Carbon::parse('2026-10-12')));
    }

    public function test_payment_reply_is_not_an_offer(): void
    {
        $body = "Кать, привет! ПП прикладываю, банк поторопил.\n\nFrom: Екатерина Кузнецова <kate@inbox.test>\nSent: Friday, October 9, 2026 5:12 PM";
        $files = ['image001.png', 'ПП 2 154671-26.pdf', 'ПП 154671-26.pdf'];
        $this->assertSame('ответ в переписке', AutoOffer::notAnOffer('RE: Вывоз ГОТС по делу 154671/2026, г. Тюмень', $body, $files, Carbon::parse('2026-10-09')));
        $this->assertSame('письмо о платеже', AutoOffer::notAnOffer('Вывоз ГОТС по делу 154671/2026', $body, $files, Carbon::parse('2026-10-09')));
    }

    public function test_old_forward_is_not_an_offer(): void
    {
        $body = "-------- Пересылаемое сообщение --------\nОт кого: Корочкин Михаил <mikhail.korochkin@sovcomins.ru>\n"
            ."Дата: Вторник, 22 июля 2025, 15:55 +03:00\nТема: 341525-25 Exeed VX\n\nВсем привет! Можно договариваться о вывозе ТС";
        $this->assertSame('пересылка старого письма', AutoOffer::notAnOffer('Fwd: 341525-25 Exeed VX', $body, ['оценка ГОТС.pdf'], Carbon::parse('2026-10-12')));
    }
}
