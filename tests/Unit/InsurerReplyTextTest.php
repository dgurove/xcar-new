<?php

namespace Tests\Unit;

use App\Mail\Extraction\CloudShare;
use App\Mail\Extraction\Intent;
use App\Mail\Extraction\Patterns;
use Tests\TestCase;

/**
 * Ответ страховой менеджеру — текст до подписи, как пришёл (05.10.2026). Письма приходят пересылкой с личного ящика
 * сотрудника, в цитате `>` и разметка mail.ru; пароль к архиву — в том же тексте. Ошибка тут — менеджер видит подпись
 * специалиста вместо контакта или архив не скачивается.
 */
class InsurerReplyTextTest extends TestCase
{
    private const FORWARDED = "Отправлено из мобильной Почты Mail\n\n-------- Пересылаемое сообщение --------\n"
        ."От: Специалист <spec@insurer.test>\nКому: Сотрудник <staff@mail.test>\nДата: понедельник, 5 октября 2026 г. в 16:51 +04:00\n"
        ."Тема: RE: 2019 Ford Kuga \\\\ AUT-26-000001\n\n>\n> \n> Добрый день!\n> *Иванов Иван Иванович*\n>\n>\n> +7(900)123-45-67\n>\n> С уважением,\n"
        ."> *Мария Специалист\n> * Ведущий Специалист\n>\n> *From:* Сотрудник <staff@mail.test>\n> Прошу прислать контакты\n";

    public function test_forwarded_reply_is_text_before_signature(): void
    {
        $this->assertSame("Добрый день!\nИванов Иван Иванович\n+7(900)123-45-67", Intent::reply(self::FORWARDED));
        $this->assertSame(['+7 900 123-45-67'], Patterns::phones(Intent::reply(self::FORWARDED)));
    }

    /** «Цена за Лот … 779 000, предлагаем забрать за 750 000»: закупочная своя, собственнику — дешевле (взаимозачёт). */
    public function test_tinkoff_offer_price_and_owner_price(): void
    {
        $fields = (new \App\Mail\Extraction\Templates\Tinkoff)->extract('2019 Ford Kuga', "*Цена за Лот* по победному ОП 779 000, предлагаем забрать за 750 000\n*Местонахождение :* Самара");

        $this->assertSame(779000, $fields['floor_price']['value']);
        $this->assertSame(750000, $fields['owner_price']['value']);
    }

    public function test_cloud_link_and_password_from_reply(): void
    {
        $text = Intent::reply("> В архиве https://data.example.test/s/AbCdEf123456\n> пароль: Z*9aK(W]-\n>\n> С уважением,\n> Мария", marks: true);

        $this->assertSame([['url' => 'https://data.example.test/s/AbCdEf123456', 'base' => 'https://data.example.test', 'token' => 'AbCdEf123456', 'password' => 'Z*9aK(W]-']], CloudShare::links($text));
        $this->assertSame([], Patterns::phones($text));
    }
}
