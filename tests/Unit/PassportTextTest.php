<?php

namespace Tests\Unit;

use App\Billing\Actions\SaveBuyerData;
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

        $this->assertSame('Республика Дагестан, Карабудахкентский Район, с. Аданак', PassportText::registration($text)['fields']['reg_address']);
    }

    /** Печатный штамп — по-прежнему строки после слова. */
    public function test_registration_printed_stamp(): void
    {
        $text = "Место жительства\nЗАРЕГИСТРИРОВАН\n15.06.2015\nРег-н: САМАРСКАЯ ОБЛ.\nПункт: ГОР. САМАРА\nУлица: МОЛОДОГВАРДЕЙСКАЯ\nДом: 151   Кв.: 23\nОТДЕЛОМ УФМС РОССИИ";

        $this->assertSame('Самарская обл., гор. Самара, Молодогвардейская, д. 151, кв. 23', PassportText::registration($text)['fields']['reg_address']);
    }

    /** Дата штампа месяцем словом в адрес не попадает (13.11.2026, Мишин: «23 АПРЕЛЯ 2004Г., ЗНАМЕНСКИЕ САДКИ УЛ., …»). */
    public function test_registration_date_in_words(): void
    {
        $text = "ЗАРЕГИСТРИРОВАН\n23 апреля 2004г.\nЗНАМЕНСКИЕ САДКИ УЛ.\nД. 1, КВ. 56";

        $this->assertSame('Знаменские Садки ул., д. 1, кв. 56', PassportText::registration($text)['fields']['reg_address']);
    }

    /** Греческая «Φ» от OCR — кириллицей: «РΦ ГОР. МОСКВА» → «РФ …». */
    public function test_greek_lookalikes(): void
    {
        $fields = PassportText::main(str_replace('гор. Махачкала', 'РΦ ГОР. МАХАЧКАЛА', $this->main()))['fields'];

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
        $this->assertSame('гор. Махачкала', $fields['birth_place']);
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
        $this->assertSame('гор. Душанбе респ.Таджикистан', $fields['birth_place']);
        $this->assertSame('2918 916342', $fields['passport']);
    }

    /** Штамп от руки: OCR даёт латинский мусор — адреса нет, а не мусор в договоре (16.11.2026). */
    public function test_handwritten_registration_gives_no_address(): void
    {
        $text = "MECTO ЖИТЕАЬСТВА\nЗАРЕГИСТРИРСЕАН\n16 ihebprahe dody\nP-H\nПункт   winp\nyn.\nМП Н 4 отлол по вопросам миграции\nMYМВД   \"Балашихинское”\nподпись";

        $this->assertSame([], PassportText::registration($text)['fields']);
    }

    /** Имя и фамилия покупателя из ФИО — порядок по отчеству, словарю имён и окончанию фамилии (16.11.2026, прод). */
    public function test_name_parts_from_fio(): void
    {
        $cases = [
            ['Файзулаев Комил Ахмеджонович', '', ['Комил', 'Файзулаев']],
            ['Ибрагимов Дашдамир Байрам оглы', '', ['Дашдамир', 'Ибрагимов']],
            ['Ирина Васильевна Холопова', '', ['Ирина', 'Холопова']],
            ['Индивидуальный Предприниматель Комаров Алексей Александрович', '', ['Алексей', 'Комаров']],
            ['Олег Данилин', 'Олег Данилин', ['Олег', 'Данилин']],
            ['МАКСИМ ГРИГОРЬЕВ', '', ['МАКСИМ', 'ГРИГОРЬЕВ']],
            ['Гамидов Тагир', '', ['Тагир', 'Гамидов']],
            ['Ekaterina Самохина', '', ['Ekaterina', 'Самохина']],
            ['Sergei Mak', 'Sergei Mak', null],
            ['Саулите Улдис', 'Саулите Улдис', null],
        ];
        foreach ($cases as [$fio, $current, $expected]) {
            $this->assertSame($expected, SaveBuyerData::nameParts($fio, $current), $fio);
        }
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
    /** Штамп «кем зарегистрирован» с ошибками OCR — конец адреса; подписи полей и дата словами — мимо (17.11.2026, паспорта прода). */
    public function test_registration_garbled_stamp_and_labels(): void
    {
        $text = "MECTO ЖИТЕАЬСТВА\nЗАРЕГИСТРИРОВАН\n20 ноября 200г г.\nPer-H: \u{0393}   MOCKBA\nРайон:\nПУНКТ:\nУннца: yn АКАДЕМИКА ПИЛЮГИНА\n14K.2 KB 361\nОТДЕЛЕНИЕ ПО ВОПРОСАМ НИГРАЦИИ ОНВД РОССИИ\n770-115   Заверил:";
        $this->assertSame('г Москва, ул. Академика Пилюгина, 14К.2 кв 361', PassportText::registration($text)['fields']['reg_address']);

        $text = "ЗАРЕГИСТРИРОВАН\n11.08.2023\nНИЖЕГОРОДСКАЯ ОБЛ.\nПЕР. КИПАРИСОВ,\nA.5KB24\nОТДЕЕ ПО БОНРОСАН НИГРАЦИИ ОТДЕНА ПОНИЦИИ\nР 6 УЛВД РОССИИ ПО Г. НИННЕНУ";
        $this->assertSame('Нижегородская обл., пер. Кипарисов,, д.5 кв. 24', PassportText::registration($text)['fields']['reg_address']);

        // Фото экрана телефона: время сообщения посреди штампа.
        $text = "ЗАРЕГИСТРИРОВАН\n05.02.2025   ЛЮБИМАЯ\nБРЯНСКАЯ ОБЛ. P-H   3 сентября, 10:53\nBPЯHCКИЙ   СУПОНЕВО\nУЛ. ФРУНЗЕ,Д.38\n\"ОВК НО ИВД РОССИИ";
        $this->assertSame('Брянская обл. р-н, Врянский Супонево, ул. Фрунзе,д.38', PassportText::registration($text)['fields']['reg_address']);
    }

    /** Мусор вместо адреса — пусто, а не «1ОКОРП». */
    public function test_registration_garbage_is_empty(): void
    {
        $this->assertSame([], PassportText::registration("ЗАРЕГИСТРИРОВАН\n01.01.2020\n1ОКОРП\nОТДЕЛОМ УФМС")['fields']);
    }

    /** Сор перед органом, пол и номер паспорта в месте рождения, Б/В в имени (17.11.2026). */
    public function test_issuer_place_and_name_cleanup(): void
    {
        $text = "НаОРТ ПЫ ГУ МВД РОССИИ ПО КРАСНОДАРСКОМУ КРАЮ\n230-004\nДта пдачи 05.06.2016 кол подразделения   610407\nЗАЙЦЕВ\nБИТАЛИЙ\nБИКТОРОВИЧ\nМYX.   11.11.1990\nГ.ХАРЬКОВ УКРАИНА 610407\n";
        $f = PassportText::main($text)['fields'];
        $this->assertSame('ГУ МВД РОССИИ ПО КРАСНОДАРСКОМУ КРАЮ', $f['passport_issued']);
        $this->assertSame('г. Харьков Украина', $f['birth_place']);
        // Без зоны внизу буквы не подменяются: редкое имя не по словарю осталось бы как есть.
        $this->assertSame('Зайцев Биталий Бикторович', $f['name']);
    }

    /** Б/В в имени и отчестве — по словарю, когда зона внизу не против (Зайцев с прода, 17.11.2026). */
    public function test_name_letters_fixed_by_zone(): void
    {
        $text = "ЗАЙЦЕВ\nБИТАЛИЙ\nБИКТОРОВИЧ\nPNRUSZAQCEV<<VITALIOVIKTOROVI3<<<<<<   <<\n4624956789RUS8407009M<<<<<<<1220201500057<76";
        $this->assertSame('Зайцев Виталий Викторович', PassportText::main($text)['fields']['name']);
    }

    /** Паспорт выдан в 12 лет — даты спорят: уходит дата рождения, дата выдачи остаётся. */
    public function test_impossible_dates_are_dropped(): void
    {
        $f = PassportText::main("ГУ МВД РОССИИ ПО Г. МОСКВЕ\n08.06.2002   770-115\nИВАНОВ\nИВАН\nИВАНОВИЧ\nМУЖ.   08.06.1990\n")['fields'];
        $this->assertArrayNotHasKey('birth_at', $f);
        $this->assertSame('2002-06-08', $f['passport_issued_at'] ?? null);
    }
}
