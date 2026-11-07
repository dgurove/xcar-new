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

    public function test_agreement_price(): void
    {
        $text = 'Страховая сумма по Договору страхования составляет 1 140 000,00 (один миллион сто сорок тысяч) рублей 00 копеек.'
            .'Стоимость поврежденного транспортного средства, указанного в п. 1 настоящего Соглашения, составляет 260 000,00 (двести шестьдесят тысяч) рублей 00 копеек.'
            .'за вычетом стоимости поврежденного транспортного средства, указанной в п. 1.2, в размере 880 000,00 рублей';
        $this->assertSame(260000, DocumentFields::extract($text)['floor_price']['value'] ?? null);
    }
}
