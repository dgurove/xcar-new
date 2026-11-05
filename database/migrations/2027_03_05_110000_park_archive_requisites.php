<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Реквизиты, договоры и тарифы из архива «СТОЯНКА» (05.11.2026, `notes/zakrytie-mesyatsa-2026-11-05.md`): прежние счета и
 * акты ИП Кузнецова и ПРАЙМ страховым за 2022–2026. Пишется только в пустое (и в московский адрес Альфы СПб, который
 * поставила миграция 2026_12_28 из открытых данных) — вписанное руками не трогается.
 *
 * Тарифы СОГАЗа, РГС и Интери стояли ставками ВСК временно («договоров нет», 23.09.2026); счетов парковки ещё не было
 * ни одного — строки получают цены договоров с прежней даты. РГС и Интери платят по выбытию (акты — машины, выданные
 * в периоде), СОГАЗ — помесячно.
 */
return new class extends Migration
{
    private const VENDORS = [
        'АльфаСтрахование' => [
            'vendor' => ['agreement_number' => 'Е240020/06/03/22', 'agreement_date' => '2022-02-16', 'act_form' => 'report'],
            'party' => ['name' => 'АО «АльфаСтрахование»', 'full_name' => 'Акционерное общество «АльфаСтрахование»', 'inn' => '7713056834', 'kpp' => '772501001', 'ogrn' => '1027739431730',
                'legal_address' => '115280, г. Москва, вн. тер. г. муниципальный округ Даниловский, пр-кт Лихачёва, д. 15, помещ. 2/15'],
        ],
        'АльфаСтрахование СПб' => [
            'vendor' => ['act_form' => 'report'],
            'party' => ['full_name' => 'Акционерное общество «АльфаСтрахование»', 'ogrn' => '1027739431730'],
        ],
        'СОГАЗ' => [
            'vendor' => ['agreement_number' => 'СОХ/2/23/3350', 'agreement_date' => '2023-08-24', 'agreement_until' => '2027-05-31', 'act_form' => 'sogaz',
                'tow_agreement_number' => 'Э/2/23/3351', 'tow_agreement_date' => '2023-07-28'],
            'party' => ['name' => 'АО «СОГАЗ»', 'full_name' => 'Акционерное общество «Страховое общество газовой промышленности»', 'inn' => '7736035485', 'kpp' => '997950001', 'ogrn' => '1027739820921',
                'legal_address' => '107078, г. Москва, пр-кт Академика Сахарова, д. 10',
                'bank_name' => 'ЦЕНТРАЛЬНЫЙ ФИЛИАЛ АБ «РОССИЯ»', 'bik' => '044525220', 'account' => '40701810800010000015', 'corr_account' => '30101810145250000220',
                'director' => 'Сердцев А. Г.', 'director_full' => 'Сердцев Алексей Геннадиевич',
                'signed_by' => 'Исполнительного директора - директора Дирекции по организации работы с тендерами Общества Сердцева Алексея Геннадиевича',
                'director_basis' => 'доверенности № 1018/26 от «12» мая 2026 года'],
        ],
        'Росгосстрах' => [
            'vendor' => ['agreement_number' => '23-11/АХД25/1464', 'agreement_date' => '2025-04-08', 'act_form' => 'rgs', 'billing_cadence' => 'release'],
            'party' => ['name' => 'ПАО СК «Росгосстрах»', 'full_name' => 'Публичное акционерное общество Страховая компания «Росгосстрах»', 'inn' => '7707067683', 'kpp' => '773001001',
                'legal_address' => '121059, г. Москва, ул. Киевская, д. 7, корп. 1',
                'bank_name' => 'БАНК ВТБ (ПАО)', 'bik' => '044525187', 'account' => '40701810416809000005', 'corr_account' => '30101810700000000187'],
        ],
        'Интери' => [
            'vendor' => ['act_form' => 'works', 'billing_cadence' => 'release'],
            'party' => ['name' => 'ООО «СК ИНТЕРИ»', 'full_name' => 'Общество с ограниченной ответственностью «Страховая компания ИНТЕРИ»', 'inn' => '1655034323', 'kpp' => '773101001', 'ogrn' => '1021602849443',
                'legal_address' => '121596, г. Москва, вн. тер. г. муниципальный округ Можайский, ул. Горбунова, д. 2, стр. 3, помещ. 137'],
        ],
    ];

    /** Цены за сутки по типу ТС: СОГАЗ — легковые / грузовые / полуприцепы (акт от 08.09.2026), РГС и Интери — 100 за всё. */
    private const TARIFFS = [
        'СОГАЗ' => ['passenger' => 100, 'light' => 100, 'truck' => 220, 'special' => 220, 'long' => 220, 'trailer' => 320],
        'Росгосстрах' => ['passenger' => 100, 'light' => 100, 'truck' => 100, 'special' => 100, 'long' => 100, 'trailer' => 100],
        'Интери' => ['passenger' => 100, 'light' => 100, 'truck' => 100, 'special' => 100, 'long' => 100, 'trailer' => 100],
    ];

    /** Что поставила миграция 2026_12_28 Альфе СПб — московский адрес головной компании; в архиве у СПб свой. */
    private const SPB_SEEDED = ['legal_address' => '115162, г. Москва, ул. Шаболовка, д. 31, стр. Б', 'kpp' => '772501001'];

    private const SPB = ['legal_address' => '190013, г. Санкт-Петербург, Московский пр., д. 60/129, лит. Б', 'kpp' => '783843001'];

    public function up(): void
    {
        $this->self();
        foreach (self::VENDORS as $name => $set) {
            $vendor = DB::table('vendors')->where('name', $name)->first();
            if (! $vendor) {
                continue;
            }
            $fill = array_filter($set['vendor'], fn ($v, $k) => empty($vendor->{$k}) || ($k === 'billing_cadence'), ARRAY_FILTER_USE_BOTH);
            if ($fill) {
                DB::table('vendors')->where('id', $vendor->id)->update($fill + ['updated_at' => now()]);
            }
            $partyId = $vendor->party_id ?: DB::table('billing_parties')->insertGetId(['kind' => 'company', 'name' => $set['party']['name'] ?? $name, 'is_self' => false, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('vendors')->where('id', $vendor->id)->whereNull('party_id')->update(['party_id' => $partyId]);
            $party = DB::table('billing_parties')->find($partyId);
            $values = $set['party'];
            if ($name === 'АльфаСтрахование СПб') {
                foreach (self::SPB as $k => $v) {
                    if (empty($party->{$k}) || $party->{$k} === self::SPB_SEEDED[$k]) {
                        $values[$k] = $v;
                    }
                }
            }
            // Имя контрагента, равное имени вендора, — черновик `Party::forVendor`, не вписанное руками.
            $fill = array_filter($values, fn ($v, $k) => empty($party->{$k}) || ($k === 'name' && $party->name === $name) || ($name === 'АльфаСтрахование СПб' && isset(self::SPB[$k])), ARRAY_FILTER_USE_BOTH);
            if ($fill) {
                DB::table('billing_parties')->where('id', $partyId)->update($fill + ['updated_at' => now()]);
            }
        }
        $this->tariffs();
        $this->billedBefore();
    }

    /**
     * С чего считает новое закрытие: что прежняя бухгалтерия уже выставила, второй раз не выставляется. СОГАЗ — помесячно,
     * последний акт архива от 08.09.2026 за август: его ТС выставлены по 31.08.2026. Альфа Москва — по выбытию, счета
     * архива — по июль 2026 включительно: ТС, выданные до сентября, выставлены. Только у ТС без отметки и без счёта.
     */
    private function billedBefore(): void
    {
        $unbilled = fn (string $vendor) => DB::table('park_vehicles')->whereIn('vendor_id', DB::table('vendors')->where('name', $vendor)->select('id'))
            ->whereNotNull('accepted_at')->whereNull('storage_billed_until')
            ->whereNotExists(fn ($q) => $q->from('billing_charges')->whereColumn('billing_charges.vehicle_id', 'park_vehicles.id')->whereNotNull('billing_charges.invoice_id'));
        $unbilled('СОГАЗ')->where('accepted_at', '<', '2026-09-01')
            ->update(['storage_billed_until' => DB::raw("least(coalesce(released_at::date, date '2026-08-31'), date '2026-08-31')"), 'updated_at' => now()]);
        $unbilled('АльфаСтрахование')->where('released_at', '<', '2026-09-01')
            ->update(['storage_billed_until' => DB::raw('released_at::date'), 'updated_at' => now()]);
    }

    /** ИП Кузнецов — продавец парковки: реквизиты из счетов 2026 года (Сбербанк; ТБанк — до 2025). */
    private function self(): void
    {
        $ip = ['inn' => '770501722811', 'ogrn' => '319774600285121', 'legal_address' => '109240, г. Москва, Котельническая наб., д. 1/15, корп. Б, кв. 144',
            'phone' => '+7 (495) 111-71-11', 'email' => '6691111@mail.ru', 'bank_name' => 'ПАО Сбербанк', 'bik' => '044525225',
            'account' => '40802810838000143175', 'corr_account' => '30101810400000000225', 'full_name' => 'Индивидуальный предприниматель Кузнецов Андрей Викторович',
            'director_full' => 'Кузнецов Андрей Викторович', 'director' => 'Кузнецов А. В.'];
        $party = DB::table('billing_parties')->where('seller', 'park')->where('is_self', true)->first();
        if (! $party) {
            return; // заведёт `Party::seller` из конфига при первом счёте — там те же значения
        }
        $fill = array_filter($ip, fn ($v, $k) => empty($party->{$k}), ARRAY_FILTER_USE_BOTH);
        if ($fill) {
            DB::table('billing_parties')->where('id', $party->id)->update($fill + ['updated_at' => now()]);
        }
    }

    private function tariffs(): void
    {
        foreach (self::TARIFFS as $name => $prices) {
            $vendor = DB::table('vendors')->where('name', $name)->first();
            if (! $vendor) {
                continue;
            }
            $billed = DB::table('billing_charges')->join('park_vehicles', 'park_vehicles.id', '=', 'billing_charges.vehicle_id')
                ->where('park_vehicles.vendor_id', $vendor->id)->whereNotNull('billing_charges.invoice_id')->exists();
            if ($billed) {
                continue; // выставленное по старой цене — менять прайс только новой строкой, руками
            }
            $from = DB::table('park_tariffs')->where('vendor_id', $vendor->id)->where('service', 'storage')->min('valid_from') ?? '2023-01-01';
            foreach ($prices as $category => $price) {
                $rows = DB::table('park_tariffs')->where('vendor_id', $vendor->id)->whereNull('yard_id')->where('category', $category)->where('service', 'storage')->whereNull('valid_to');
                if ($rows->exists()) {
                    $rows->update(['price' => $price, 'updated_at' => now()]);
                } else {
                    DB::table('park_tariffs')->insert(['vendor_id' => $vendor->id, 'yard_id' => null, 'category' => $category, 'service' => 'storage', 'from_day' => 1,
                        'price' => $price, 'vat' => false, 'valid_from' => $from, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void {}
};
