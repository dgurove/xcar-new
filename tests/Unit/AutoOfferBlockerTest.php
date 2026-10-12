<?php

namespace Tests\Unit;

use App\Offers\Actions\AutoOffer;
use Tests\TestCase;

/**
 * Не предложение, хоть поля и похожи (12.10.2026, прод): платёжки Совкома ответом Кате завелись черновиком. И обратное:
 * «Акт ПП» в письме Совкома — акт приёма-передачи, такое письмо — предложение. Ошибка тут — левое письмо встаёт в
 * «Без продажной цены» молча или настоящее не заводится.
 */
class AutoOfferBlockerTest extends TestCase
{
    public function test_offer_with_transfer_act_passes(): void
    {
        $body = "-------- Пересылаемое сообщение --------\nОт кого: Корочкин Михаил <mikhail.korochkin@sovcomins.ru>\n"
            ."Дата: Вторник, 6 октября 2026, 10:22 +03:00\nТема: 576308-26 ISUZU Пермь\n\n545 555 руб.\nДокументы уже у них (Соглашение и Акт ПП).";
        $this->assertNull(AutoOffer::notAnOffer('Fwd: 576308-26 ISUZU Пермь', $body, ['image001.png', 'Акт приема-передачи ПРАЙМ 576308-26.docx', 'оценка ГОТС.pdf', 'фото (1).jpg']));
    }

    public function test_payment_reply_is_not_an_offer(): void
    {
        $body = "Кать, привет! ПП прикладываю, банк поторопил.\n\nFrom: Екатерина Кузнецова <kate@inbox.test>\nSent: Friday, October 9, 2026 5:12 PM";
        $files = ['image001.png', 'ПП 2 154671-26.pdf', 'ПП 154671-26.pdf'];
        $this->assertSame('ответ в переписке', AutoOffer::notAnOffer('RE: Вывоз ГОТС по делу 154671/2026, г. Тюмень', $body, $files));
        $this->assertSame('письмо о платеже', AutoOffer::notAnOffer('Вывоз ГОТС по делу 154671/2026', $body, $files));
    }

    public function test_forwarded_reply_is_not_an_offer(): void
    {
        $body = "-------- Пересылаемое сообщение --------\nОт кого: Протопопов <a@sovcomins.ru>\nТема: RE: Вывоз ГОТС по делу 106123/2026\n\nПринято, спасибо";
        $this->assertSame('ответ в переписке', AutoOffer::notAnOffer('Fwd: Вывоз ГОТС по делу 106123/2026', $body, []));
    }
}
