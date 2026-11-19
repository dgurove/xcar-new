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

    /**
     * Контакты владельца без слова-якоря и сразу двое (17.11.2026, Т-Страхование по AUT-26-982161): «ФИО телефон» строками;
     * подпись сотрудника страховой и наше письмо в цитате («From: XCAR») — не контакты.
     */
    public function test_owner_contacts_without_anchor_two_people(): void
    {
        $body = "Партнёры, добрый день!\nДокументы во вложении, просьба связаться, организовать вывоз и оплату, спасибо\n"
            ."Нужина Елена Александровна +7(906)041-41-67\nНужин Владислав Николаевич +7(967)124-01-10\n\nС уважением,\nРоевнев Павел\n"
            ."Тел.: +7 (495) 648-10-00, Доб. 8787\nМоб.: 8 (995) 121-60 -35\n\nFrom: XCAR <deal@xcar.ru>\nSent: Sunday\n\nПросим прислать контакты.";
        $fields = \App\Mail\Extraction\Templates\Template::common('RE: AUT-26-982161', $body);

        $this->assertSame([['name' => 'Нужина Елена Александровна', 'phone' => '+7 906 041-41-67'], ['name' => 'Нужин Владислав Николаевич', 'phone' => '+7 967 124-01-10']], $fields['contacts']['value']);
        $this->assertSame('Нужина Елена Александровна', $fields['insured_name']['value']);
        $this->assertSame('+7 906 041-41-67', $fields['insured_phone']['value']);
        $this->assertArrayNotHasKey('contact_name', $fields, 'наш адрес в цитате — не ответственный');

        // Подпись без «С уважением» и цитата Gmail — не контакты; владелец с «тел.» — контакт.
        $tail = \App\Mail\Extraction\Templates\Template::contacts("Документы во вложении.\nНужин Владислав Николаевич, тел. 8 967 124-01-10\nСпасибо!\nИванова Мария, специалист\n8 (495) 111-22-33, доб. 12\n"
            ."пн, 17 нояб. 2026 г. в 11:46, Павел <p.roevnev@tbank.ru>:\nПетров Пётр 8 999 000-11-22");
        $this->assertSame([['name' => 'Нужин Владислав Николаевич', 'phone' => '+7 967 124-01-10']], $tail);
        $gmail = \App\Mail\Extraction\Templates\Template::contacts("Ок\nпн, 17 нояб. 2026 г. в 11:46, Павел <p.roevnev@tbank.ru>:\nПетров Пётр 8 999 000-11-22");
        $this->assertSame([], $gmail);
    }

    /** Ссылку пишет внешний отправитель, ходит по ней наш сервер: только https и не внутренние адреса. */
    public function test_cloud_link_rejects_private_and_plain_http(): void
    {
        $this->assertSame([], CloudShare::links("В архиве http://data.tbank.ru/s/AbCdEf123456\nпароль: Z*9aK"));
        $this->assertSame([], CloudShare::links("В архиве https://10.0.0.5/s/AbCdEf123456\nпароль: Z*9aK"));
        $this->assertSame([], CloudShare::links("В архиве https://localhost/s/AbCdEf123456"));
        $text = Intent::reply("> пароль: Z*9aK(W]-\n>\n> С уважением,\n> Мария", marks: true);
        $this->assertSame([], Patterns::phones($text));
        $this->assertStringContainsString('Z*9aK(W]-', $text);
    }

    /** «Большие файлы» Mail.ru (04.11.2026, ТИТ): фото Evolute пришли ссылкой, а не вложением — черновик стоял без фото. */
    public function test_mailru_big_file_link(): void
    {
        $links = CloudShare::links("К этому письму приложены ссылки на следующие файлы: *1. Фото торги.rar* (189.6 Мб)\nСсылка для скачивания файлов: https://cloud.mail.ru/stock/4qZ4aEPPiufzdm8Hh18LLZuj\nФайлы будут храниться до 27.04.2027");
        $this->assertCount(1, $links);
        $this->assertSame(['mailru', '4qZ4aEPPiufzdm8Hh18LLZuj'], [$links[0]['kind'], $links[0]['token']]);
    }
}
