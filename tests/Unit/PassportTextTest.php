<?php

namespace Tests\Unit;

use App\Billing\Jobs\ReadPassport;
use App\Billing\Passport\PassportText;
use PHPUnit\Framework\TestCase;

/**
 * Разбор паспорта по тексту OCR (07.11.2026, скан владельца: прописка «0904 2008.», серия и номер не прочитались). Текст —
 * того же вида, что вернул `ocr`, данные изменены.
 */
class PassportTextTest extends TestCase
{
    /** Сельский штамп: адрес над «ЗАРЕГИСТРИРОВАН», дата без точек. */
    public function test_registration_address_above_the_stamp_word(): void
    {
        $text = "РЕСПУБЛИКА ДАГЕСТАН\nКАРАБУДАХКЕНТСКИЙ РАЙОН\nАДМИНИСТРАЦИЯ С.АДАНАК\nЗАРЕГИСТРИРОВАН\n1205   2009.\nподпись";

        $this->assertSame('РЕСПУБЛИКА ДАГЕСТАН, КАРАБУДАХКЕНТСКИЙ РАЙОН, С. АДАНАК', PassportText::registration($text)['fields']['reg_address']);
    }

    /** Печатный штамп — по-прежнему строки после слова. */
    public function test_registration_printed_stamp(): void
    {
        $text = "Место жительства\nЗАРЕГИСТРИРОВАН\n15.06.2015\nРег-н: САМАРСКАЯ ОБЛ.\nПункт: ГОР. САМАРА\nУлица: МОЛОДОГВАРДЕЙСКАЯ\nДом: 151   Кв.: 23\nОТДЕЛОМ УФМС РОССИИ";

        $this->assertSame('САМАРСКАЯ ОБЛ, ГОР. САМАРА, МОЛОДОГВАРДЕЙСКАЯ, Д. 151, КВ. 23', PassportText::registration($text)['fields']['reg_address']);
    }

    /** Серия и номер из обрезанных отпечатков, прочитанных поперёк: «07 512946» сверху, «82 07 5129» снизу. */
    public function test_series_and_number_from_cut_prints(): void
    {
        $result = PassportText::main($this->main()."\n".PassportText::ACROSS."\n07 512946\n82 07 5129");

        $this->assertSame('82 07 512946', $result['fields']['passport']);
        $this->assertSame('050-003', $result['fields']['passport_code']);
    }

    /** Один отпечаток без подтверждения регионом — пусто: неверный номер хуже пустого. */
    public function test_unconfirmed_series_stays_empty(): void
    {
        $result = PassportText::main(str_replace('050-003', '770-003', $this->main())."\n".PassportText::ACROSS."\n512946\n82 07 5129");

        $this->assertArrayNotHasKey('passport', $result['fields']);
    }

    public function test_name_and_birth_place(): void
    {
        $fields = PassportText::main($this->main())['fields'];

        $this->assertSame('Алиев Фусейн Магомедович', $fields['name']);
        $this->assertSame('ГОР. МАХАЧКАЛА', $fields['birth_place']);
    }

    /** ФИО паспорта поверх вписанного, буква OCR — по вписанному: «Фусейн» → «Гусейн». */
    public function test_passport_name_keeps_typed_spelling(): void
    {
        $this->assertSame('Алиев Гусейн Магомедович', ReadPassport::spelled('Алиев Фусейн Магомедович', 'Гусейн Алиев'));
        $this->assertSame('Алиев Фусейн Магомедович', ReadPassport::spelled('Алиев Фусейн Магомедович', 'Новый покупатель'));
    }

    private function main(): string
    {
        return "рОССЙЙ.СКАЯ ФЕДЕРАЦИЯ\nПаспорт выдан.   ОТДЕЛОМ УФМС РОССИИ\nПО РЕСП. ДАГЕСТАН   8\nВ СОВЕТСКОМ Р-НЕ ГОР. МАХАЧКАЛЫ\n"
            ."Дата выдач19.03.2008 Код подразделения 050-003   0\nАичнй код   512946\nlllcg\nAЛИЕВ\nФам.Аня\nФYCEЙH\nМАГОМЕДОВИЧ\n"
            ."Отчество   0\nПо.МУЖ   Δaτa   19.09.1982\nрождения\nMeero   ГОР. МАХАЧКАЛА\nрождения\n512946";
    }
}
