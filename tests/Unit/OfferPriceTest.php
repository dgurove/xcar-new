<?php

namespace Tests\Unit;

use App\Mail\Extraction\Templates\Template;
use App\Mail\Extraction\Templates\Tinkoff;
use Tests\TestCase;

/**
 * Закупочная из писем Т-Страхования (14.10.2026): AITO M5 завёлся без цены — «Сумма за лот» шаблон не знал, а у Lotus
 * звёздочки разметки рвали «Цена за ЛОТ». Без цены черновик встаёт в «Без закупочной», ошибка молчит.
 */
class OfferPriceTest extends TestCase
{
    private function fields(string $body): array
    {
        return array_map(fn ($f) => $f['value'], (new Tinkoff)->extract('', $body));
    }

    public function test_lot_price_forms(): void
    {
        $cases = [
            "Предлагаем принять участие в оценке ТС.\nСумма за лот 685 713 руб.\nТС не кредит. Авто в Череповце. Пробег 60 000." => 685713,
            "Предлагаем принять участие в оценке ТС.\nСтоимость лота 933333\nАвто в Краснодаре" => 933333,
            "Предлагаем принять участие в оценке ТС.\n*Цена за* *ЛОТ* 5 211 000,00р.\n*ТС* *не кредитное*" => 5211000,
            'Цена за Лот* по победному ОП 779 000, предлагаем забрать за 750 000' => 779000,
        ];
        foreach ($cases as $body => $price) {
            $this->assertSame($price, $this->fields($body)['floor_price'] ?? null, $body);
        }
    }

    public function test_owner_price_and_mileage(): void
    {
        $fields = $this->fields("Цена за Лот* по победному ОП 779 000, предлагаем забрать за 750 000\nПробег 60 000");
        $this->assertSame(750000, $fields['owner_price']);
        $this->assertSame(60000, $fields['mileage']);
        // Число цитаты, разорванное переносом строки.
        $this->assertSame(115254, $this->fields("Haval Jolion, 2023 г.в., пробег 115\n> 254 км.")['mileage']);
    }

    public function test_city_in_prepositional(): void
    {
        $this->assertSame(['Череповце', 'Череповц', 'Череповца', 'Череповць', 'Череповця', 'Череповец', 'Череповоц'], Template::nominative('Череповце'));
        $this->assertContains('Нижний Новгород', Template::nominative('Нижнем Новгороде'));
        $this->assertContains('Казань', Template::nominative('Казани'));
    }
}
