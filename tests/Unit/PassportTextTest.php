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

    /** Дата штампа месяцем словом в адрес не попадает (13.11.2026, Мишин: «23 АПРЕЛЯ 2004Г., ЗНАМЕНСКИЕ САДКИ УЛ., …»). */
    public function test_registration_date_in_words(): void
    {
        $text = "ЗАРЕГИСТРИРОВАН\n23 апреля 2004г.\nЗНАМЕНСКИЕ САДКИ УЛ.\nД. 1, КВ. 56";

        $this->assertSame('ЗНАМЕНСКИЕ САДКИ УЛ., Д. 1, КВ. 56', PassportText::registration($text)['fields']['reg_address']);
    }

    /** Греческая «Φ» от OCR — кириллицей: «РΦ ГОР. МОСКВА» → «РФ …». */
    public function test_greek_lookalikes(): void
    {
        $fields = PassportText::main(str_replace('ГОР. МАХАЧКАЛА', 'РΦ ГОР. МАХАЧКАЛА', $this->main()))['fields'];

        $this->assertStringNotContainsString('Φ', (string) ($fields['birth_place'] ?? ''));
    }

    /** Серия и номер из обрезанных отпечатков, прочитанных поперёк: «07 512946» сверху, «82 07 5129» снизу. */
    public function test_series_and_number_from_cut_prints(): void
    {
        $result = PassportText::main($this->main()."\n".PassportText::ACROSS."\n07 512946\n82 07 5129");

        $this->assertSame('8207 512946', $result['fields']['passport']);
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

    /** Зона не прочиталась, подпись «Отчество» прочитана «Отчестае», «рождения» — строчными рядом с полом (16.11.2026). */
    public function test_misread_labels_are_not_names(): void
    {
        $text = "РОССИЙСКЛЯ.ФЕДЕРАЦИЯ\nУМВД РОССИИ ПО КАЛУЖСКОЙ ОБЛАСТИ\n2\n6\nAaт24.04.2019 Кох нодрааделення   400-009\n"
            ."АИчнай КОд   916342\nАичнах подиись\nФАЙЗУЛАЕВ\nКОМИЛ\nОтчестае   АХМЕДЖОНОВИЧ\nAara\nllax   МУЖ.раждеви   22.01.1994\n"
            ."Mecro\nрождения   ФОР.ДУШАНБЕ\nРЕСП.ТАДЖИКИСТАН   916342\nAI! 1EV<<KOMIL<AHMEDJONOV13<<<<<<<<<<\n"
            .":  63 JS9401220M<<<<<<<8190424400009<52\n‹поперёк›\n29 18 916342 29 18 916342\n100 000\n5000000800006";
        $fields = PassportText::main($text)['fields'];

        $this->assertSame('Файзулаев Комил Ахмеджонович', $fields['name']);
        $this->assertSame('ГОР. ДУШАНБЕ РЕСП.ТАДЖИКИСТАН', $fields['birth_place']);
        $this->assertSame('2918 916342', $fields['passport']);
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
