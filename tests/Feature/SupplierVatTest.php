<?php

namespace Tests\Feature;

use App\Billing\ChargeKind;
use App\Billing\InvoiceState;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\SaveDealContract;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\SupplierVat;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * НДС в счёте за ТС — как у того, у кого забираем машину (20.11.2026, бухгалтер): разбор писем и документов поставщика, ставка
 * договора по схеме, подбор отдельным счётом при другой ставке. Ошибка молчалива и дорога: не тот НДС в счёте.
 */
class SupplierVatTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_supplier_vat_from_real_wording(): void
    {
        $cases = [
            'Просьба прислать реквизиты для выставления счета. Без НДС.' => 0,
            'Просьба прислать реквизиты для выставления счета. C НДС.' => 22, // латинская C
            'Вывоз ГОТС. Сделка НДС не облагается.' => 0,
            'по договору комиссии № 511631/2026. НДС не облагается», в течении 3 (трех) рабочих дней' => 0,
            '260 000,00 рублей 00 копеек, в т.ч. НДС 22% - 46 852,5 руб.' => 22,
            'Триста тридцать три тысячи рублей. В том числе НДС 22% - 60 049,18 руб.' => 22,
            'В т.ч. НДС 22% Итого. В том числе НДС 22% - 0,00 руб.' => 0,
            'Всего к оплате, в т.ч. НДС 34 348,13 руб. (22%)' => 22,
            'НДС не облагается на основании применения упрощённой системы налогообложения' => 0,
            'Цена 1 000 000 руб., в т.ч. НДС 7%' => 7,
            'Добрый день, машину заберём завтра' => null,
            'Заказ-наряд № 12, работы, в т.ч. НДС 20% - 1 200,00' => null,
            'ООО «ПРАЙМ», ИНН 5007110932, в том числе НДС 5 %: 12 648,57' => null,
        ];
        foreach ($cases as $text => $rate) {
            $this->assertSame($rate, SupplierVat::fromText($text), $text);
        }
    }

    public function test_only_sale_documents_count(): void
    {
        $this->assertTrue(SupplierVat::saleDocument('Счет на оплату № 5. Оплата за поврежденное ТС RENAULT DUSTER, в т.ч. НДС 22%'));
        $this->assertTrue(SupplierVat::saleDocument('ДОГОВОР КОМИССИИ № 511631/2026. НДС не облагается'));
        $this->assertFalse(SupplierVat::saleDocument('Соглашение: 209 524,00 рубля с учетом НДС 22%, выплачивается в составе страхового возмещения'));
        $this->assertFalse(SupplierVat::saleDocument('Односторонний абандон, договор купли-продажи предмета лизинга, в том числе НДС 22% в размере 64 136,49'));
    }

    private function deal(?int $supplierVat): Deal
    {
        Storage::fake('private');
        Notification::fake();
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Тужиков Евгений', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 944000, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open, 'supplier_vat' => $supplierVat])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1050000), $admin, 30000, scheme: DealScheme::Prime);
        app(TakeExit::class)($offer->fresh(), $offer->fresh()->position(Track::Sale)->stage->exits->firstWhere('label', 'Автомобиль забрал'), Actor::Manager, $manager);
        app(SaveDealContract::class)(DealContract::for($deal->fresh()), ['buyer_id' => 'new', 'new_buyer' => ['phone' => '+7 900 555-44-33'], 'buyer' => ['kind' => 'person',
            'name' => 'Покупаев Пётр Петрович', 'birth_at' => '1985-05-05', 'passport' => '11 11 111111', 'passport_issued' => 'ГУ МВД', 'passport_issued_at' => '2010-10-10', 'reg_address' => 'г. Москва']], $manager);
        $this->passportFor($deal->fresh()->contract->buyer);

        return $deal->fresh();
    }

    private function live(Deal $deal, ChargeKind $kind)
    {
        return $deal->fresh()->issuedInvoices()->where('kind', $kind)->where('state', '!=', InvoiceState::Void)->get();
    }

    public function test_commission_with_supplier_vat_splits_selection(): void
    {
        $deal = $this->deal(22);
        $this->assertSame(22, $deal->contract->vatRate());
        $sale = $this->live($deal, ChargeKind::Sale)->sole();
        $this->assertSame(22, $sale->vat_rate);
        $this->assertEquals(944000, $sale->total, 'счёт за ТС — на закупочную');
        $this->assertSame(944000, $deal->fresh()->contract->price);
        $fee = $this->live($deal, ChargeKind::Selection)->sole();
        $this->assertEquals(1050000 - 30000 - 944000, $fee->total, 'подбор — остаток, своим счётом');
        $this->assertSame(5, $fee->vatRate());
    }

    public function test_unknown_supplier_vat_is_without_vat_and_split(): void
    {
        $deal = $this->deal(null);
        $this->assertSame(0, $deal->contract->vatRate());
        $this->assertSame('НДС поставщика не указан', $deal->contract->vatSource());
        $this->assertNull($this->live($deal, ChargeKind::Sale)->sole()->vat_rate);
        $this->assertCount(1, $this->live($deal, ChargeKind::Selection));
    }

    public function test_same_rate_keeps_one_line(): void
    {
        $deal = $this->deal(5);
        $this->assertEquals(1050000 - 30000, $this->live($deal, ChargeKind::Sale)->sole()->total);
        $this->assertCount(0, $this->live($deal, ChargeKind::Selection));
    }

    public function test_letter_moves_unpaid_invoice_but_not_human_choice(): void
    {
        $deal = $this->deal(null);
        $offer = $deal->offer->fresh();
        $this->assertTrue(SupplierVat::put($offer, 22, 'letter', null, now()));
        $this->assertSame(22, $this->live($deal, ChargeKind::Sale)->sole()->vat_rate);

        $offer->fresh()->forceFill(['supplier_vat' => 0, 'supplier_vat_source' => 'human'])->save();
        $this->assertFalse(SupplierVat::put($offer->fresh(), 22, 'document', null, now()->addDay()));
        $this->assertSame(0, $offer->fresh()->supplier_vat);
    }
}
