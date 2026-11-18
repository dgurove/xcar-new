<?php

namespace Tests\Feature;

use App\Mail\Attachment;
use App\Mail\Extraction\AttachmentReader;
use App\Mail\Extraction\DocumentFields;
use App\Mail\Extraction\Extractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 641546/2026 (Совкомбанк, 06.11.2026): пересланная переписка, единственная сумма в тексте — «выплату страхового в
 * неоспариваемой части, в размере 880 000 руб.», она стала закупочной; в договоре комиссии — «назначает цену 260 000».
 */
class MailPriceGuessTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = "Всем привет! Я так понимаю с Вами правки в ДК согласованы?\n\n"
        ."From: Корочкин Михаил Александрович\nSubject: RE: 641546-26 уведомление о полной гибели ТС CITROEN C4\n\n"
        ."Светлана Андреевна, добрый день!\nВ этом случае, вынуждены осуществить выплату страхового в неоспариваемой части, в размере 880 000 руб.\nТС остается у Вас.\n\n"
        ."2.  На месте, где находится машина, Вы подписываете договор комиссии и акт приема-передачи ГОТС.\n";

    private function extract(array $file): array
    {
        $reader = new class($file) implements AttachmentReader
        {
            public function __construct(private array $file) {}

            public function read(Attachment $attachment): array
            {
                return $this->file;
            }
        };
        $files = [new Attachment(['filename' => 'Договор комиссии с правками 05.11.26.docx', 'is_inline' => false])];

        return (new Extractor($reader))->extract('FW: 641546-26 уведомление о полной гибели ТС CITROEN C4', self::BODY, 'mikhail.korochkin@sovcomins.ru', now(), $files);
    }

    public function test_payout_is_not_price_and_contract_wins(): void
    {
        $fields = $this->extract([]);
        $this->assertArrayNotHasKey('floor_price', $fields, 'выплата — не цена');
        $this->assertArrayNotHasKey('location', $fields, '«где находится машина, Вы…» — не место');

        $fields = $this->extract(['floor_price' => ['value' => 260000, 'source' => 'file']]);
        $this->assertSame(260000, $fields['floor_price']['value']);
        $this->assertSame('file', $fields['floor_price']['source']);
    }

    /**
     * Росгосстрах (18.11.2026, BMW X5 Т053КВ977): «Стоимость авто 13 000 000 руб. Пробег на момент страхования» — страховая
     * стоимость, не закупочная; закупочная — «База стоимость 6375000₽» в записке Андрея над пересылкой.
     */
    public function test_insured_value_is_not_price_and_staff_note_is(): void
    {
        $reader = new class implements AttachmentReader
        {
            public function read(Attachment $attachment): array
            {
                return [];
            }
        };
        $body = "Андрей добрый день!\nа/м BMW X5\n2024\nСтоимость авто 13 000 000 руб. Пробег на момент страхования 13 тыс км.\nСрок действия полиса с января 2026";
        $this->assertArrayNotHasKey('floor_price', (new Extractor($reader))->extract('BMW X5 № Т053КВ977', $body, 'sergey_avanesov@rgs.ru', now(), []));
        $this->assertSame(6375000, \App\Mail\Extraction\Templates\Template::notedPrice('База стоимость 6375000₽'));
        $this->assertNull(\App\Mail\Extraction\Templates\Template::notedPrice('Посмотри, пожалуйста'));
        // Совкомбанк в начале года — четыре цифры (5303-26), ПСБ и СОГАЗ — своими номерами.
        $codes = new \App\Mail\Extraction\CodeMatcher;
        $this->assertSame(['5303-26'], $codes->findAll('5303-26 LADA GRANTA пгт. Кугеси'));
        $this->assertSame(['0207-26-06'], $codes->findAll('ПСБ 0207-26-06 Тойота'));
    }

    public function test_contract_price_beats_bare_sum_in_text(): void
    {
        $reader = new class implements AttachmentReader
        {
            public function read(Attachment $attachment): array
            {
                return ['floor_price' => ['value' => 260000, 'source' => 'file']];
            }
        };
        $files = [new Attachment(['filename' => 'Договор комиссии.docx', 'is_inline' => false])];
        $fields = (new Extractor($reader))->extract('641546-26 CITROEN C4', "Добрый день!\n1 259 990 руб.\n", 'x@example.com', now(), $files);
        $this->assertSame(260000, $fields['floor_price']['value']);
    }

    /**
     * ЭПТС фургона на шасси ГАЗ (18.11.2026, 5991/046/00482/26): «Марка: Отсутствует» — марка базового ТС с коммерческим
     * наименованием, а не марка «2834Nj»; слой сканера «почти русский» не читается — его читает наш OCR.
     */
    public function test_epts_without_brand_and_garbled_scanner_layer(): void
    {
        $text = "Идентификационный номер   XU42834NJP0000310\nМарка   Отсутствует\nКоммерческоенаименование   2834NJ\n"
            ."Годизготовления   2023\nМарка базового ТС (шасси)   ГАЗ\n";
        $f = DocumentFields::extract($text);
        $this->assertSame(['XU42834NJP0000310', 'ГАЗ', '2834NJ', 2023], [$f['vin']['value'] ?? null, $f['brand']['value'] ?? null, $f['model']['value'] ?? null, $f['year']['value'] ?? null]);
        $this->assertNull(\App\Cars\Brand::resolve('2834NE'));
        // OCR второй раз: «Мapka» латиницей, «Коммерческоенайменование», город — только адрес завода.
        $ocr = "Выписка из электронного паспорта транспортного средства\nИдентификационный номер   XU42834NJP0000310\nМapka   Отсутствует\n"
            ."Коммерческоенайменование   2834NJ\nНомер шасси (рамы)   X96330250P2885463\nМарка базового ТС (шасси)   ГАЗ\nАдрес изготовителя   603032, Российская\nГород Нижний Новгород,\n";
        $f = DocumentFields::extract($ocr);
        $this->assertSame(['ГАЗ', '2834NJ', null], [$f['brand']['value'] ?? null, $f['model']['value'] ?? null, $f['city']['value'] ?? null]);
        // Акт Альфы: «Марка/модель ТС   SHACAMAN 3558» (2610222544).
        \App\Cars\Brand::create(['name' => 'Shacman (Shaanxi)', 'slug' => 'shacman']);
        // Код модели с другой буквой — другая модель, а не опечатка OCR.
        $gaz = \App\Cars\Brand::known('ГАЗ') ?? \App\Cars\Brand::create(['name' => 'ГАЗ', 'slug' => 'gaz']);
        $gaz->models()->create(['name' => '2834NJ', 'slug' => '2834nj']);
        $this->assertSame('2834NE', \App\Cars\CarModel::resolve($gaz, '2834NE')->name);
        // Подпись VIN слитно после OCR (ЭПТС прицепа 4224).
        $this->assertSame('EAR853371R0000489', DocumentFields::extract("Идентификационныйномер   EAR853371R0000489\n")['vin']['value'] ?? null);
        $this->assertSame('3558', DocumentFields::extract("Марка/модель ТС   SHACAMAN 3558   Гос.рег.номер\n")['model']['value'] ?? null);

        $garbled = str_repeat("Ндевтифихаrдrrонншй Hor,tep транспортного средства Коммерчесхое яаименование 2Ез4NJ Год ивгот.овлвння 2о2з паспорта Выписка\n", 3);
        $this->assertFalse(\App\Mail\Extraction\AttachmentText::readable($garbled));
        $this->assertTrue(\App\Mail\Extraction\AttachmentText::readable(str_repeat("Идентификационный номер XU42834NJP0000310 транспортного средства Коммерческое наименование 2834NJ Год изготовления 2023 паспорта Выписка\n", 3)));
    }

    public function test_agreement_price(): void
    {
        $text = 'Страховая сумма по Договору страхования составляет 1 140 000,00 (один миллион сто сорок тысяч) рублей 00 копеек.'
            .'Стоимость поврежденного транспортного средства, указанного в п. 1 настоящего Соглашения, составляет 260 000,00 (двести шестьдесят тысяч) рублей 00 копеек.'
            .'за вычетом стоимости поврежденного транспортного средства, указанной в п. 1.2, в размере 880 000,00 рублей';
        $this->assertSame(260000, DocumentFields::extract($text)['floor_price']['value'] ?? null);
    }
}
