<?php

namespace Tests\Feature;

use App\Offers\Actions\ApplyValuations;
use App\Offers\Actions\MatchValuations;
use App\Offers\Offer;
use App\Offers\ValuationSheet;
use App\Offers\ValuationText;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Таблица оценок Альфы «23.10-31.10.xlsx» (01.11.2026): рядом с листом оценок — лист «Выплаты по ОР24» без шапки (646
 * номеров PVU встали бы в «Нет в CRM» с галкой «завести»), а среди сумм — 317 и 0. Берётся только лист с шапкой суммы;
 * 317 и 0 доходят до окна подозрительными: без галки, и бот их не проставляет.
 */
class ValuationSheetTest extends TestCase
{
    use RefreshDatabase;

    private function book(): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([
            ['НОМЕР УБЫТКА', 'ф/ю/л', 'МАКСИМАЛЬНАЯ СТАВКА', 'VIN НОМЕР', 'ФИЛИАЛ (место нахождения ТС)'],
            ['5691/046/00885/26', 'ю', 557500, 'XTS99064AK0000067', 'Новосибирск'],
            ['3692/046/06383/26', 'ф', 317, 'WMA89XZZ68W115328', 'ДНР'],
            ['2141/046/02759/26', 'ф', 0, 'VF1LM0C0H39339472', 'Белгород'],
            ['0760/046/17304/26', 'ф', null, 'XZGFF06A5PA345099', 'Москва'],
        ]);
        $book->createSheet()->setTitle('Выплаты по ОР24')->fromArray([
            ['0194/PVU/00163/24', 1, 20659.04],
            ['0323/PVU/00248/24', 1, 19712],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'val').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    public function test_only_headed_sheet_and_small_amounts_survive(): void
    {
        $text = ValuationSheet::read($this->book())['text'];
        $this->assertStringNotContainsString('PVU', $text);
        $rows = collect(ValuationText::parse($text)['rows'])->mapWithKeys(fn ($r) => [$r['ref'] => $r['amount']]);
        $this->assertSame(['5691/046/00885/26' => 557500, '3692/046/06383/26' => 317, '2141/046/02759/26' => 0, '0760/046/17304/26' => 0], $rows->all());
    }

    public function test_without_header_first_number_but_not_a_date(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['5691/046/00885/26', 46318, 557500, 'XTS99064AK0000067']]);
        $sheet->getStyle('B1')->getNumberFormat()->setFormatCode('dd.mm.yyyy');
        $path = tempnam(sys_get_temp_dir(), 'val').'.xlsx';
        (new Xlsx($book))->save($path);

        $rows = ValuationText::parse(ValuationSheet::read($path)['text'])['rows'];
        $this->assertSame(557500, $rows[0]['amount']);
        $this->assertSame('XTS99064AK0000067', $rows[0]['vin']);
    }

    public function test_suspicious_amounts_are_marked_and_bot_skips_them(): void
    {
        config(['xcar.mercure.hub' => '']);
        $admin = User::create(['name' => 'Катя', 'phone' => '79000000030', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $alfa = Vendor::create(['name' => 'Альфа тест']);
        $alfa->forceFill(['floor_by_value' => true])->save();
        $fine = Offer::create(['vendor_id' => $alfa->id, 'claim_ref' => '5691/046/00885/26']);
        $tiny = Offer::create(['vendor_id' => $alfa->id, 'claim_ref' => '3692/046/06383/26']);
        $drop = Offer::create(['vendor_id' => $alfa->id, 'claim_ref' => '0790/046/07542/26', 'value' => 4873000]);

        $items = collect(app(MatchValuations::class)($admin, ValuationText::parse(
            "5691/046/00885/26 557 500.00\n3692/046/06383/26 317.00\n2141/046/02759/26 0.00\n0790/046/07542/26 1 200 000.00")['rows']))
            ->keyBy('ref');
        $this->assertNull($items['5691/046/00885/26']['suspicious']);
        $this->assertStringContainsString('слишком мала', $items['3692/046/06383/26']['suspicious']);
        $this->assertSame('сумма 0', $items['2141/046/02759/26']['suspicious']);
        $this->assertSame('absent', $items['2141/046/02759/26']['status']);
        $this->assertStringContainsString('в 4 раз меньше прежней', $items['0790/046/07542/26']['suspicious']);

        $saved = ApplyValuations::prepare($admin, $items->values()->all());
        $this->assertSame([$fine->id], $saved['sure']);
        $this->assertContains($tiny->id, $saved['checkable']);
        $this->assertTrue($saved['create'][$items['2141/046/02759/26']['key']]['suspicious']);
        $this->assertNotContains($drop->id, $saved['sure']);
    }
}
