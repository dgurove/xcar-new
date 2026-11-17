<?php

namespace Tests\Feature;

use App\Billing\Party;
use App\Billing\PartyKind;
use App\Billing\PartyRules;
use App\Billing\Seller;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\ChangeDealScheme;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * «Через Р.О.С.» (17.11.2026, владелец: «машина от Альфы с НДС — платёж от ООО «Р.О.С.», покупатель платит Р.О.С.; как это
 * указать в CRM — не понял»): схема идёт за «С НДС» сама, наше юрлицо покупателем не вписать, вписанное снимается переводом.
 */
class RosSchemeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private function deal(bool $vat = false): Deal
    {
        Notification::fake();
        Queue::fake();
        config(['xcar.mercure.hub' => '']);
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Роман Корабельников', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'АльфаСтрахование тест']);
        $vendor->forceFill(['pay_before_pickup' => true])->save();
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '5491/046/00861/26', 'floor_price' => 900000, 'asking_price' => 1100000,
            'prices_include_vat' => $vat, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());

        return app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1150000), $this->admin, scheme: DealScheme::Insurer)->fresh();
    }

    public function test_scheme_follows_vat(): void
    {
        $deal = $this->deal();
        $this->assertSame(DealScheme::Insurer, $deal->schemeOf());

        app(UpdateOffer::class)($deal->offer->fresh(), ['prices_include_vat' => true], $this->admin);
        $this->assertSame(DealScheme::Ros, $deal->fresh()->schemeOf(), 'с НДС — через Р.О.С.');
        $this->assertSame('Счёт АльфаСтрахование тест на '.Party::seller(Seller::Ros)->name.', с НДС', $deal->fresh()->supplierInvoiceLine());

        app(UpdateOffer::class)($deal->offer->fresh(), ['prices_include_vat' => false], $this->admin);
        $this->assertSame(DealScheme::Insurer, $deal->fresh()->schemeOf(), 'без НДС — снова напрямую');
    }

    public function test_our_company_is_not_a_buyer(): void
    {
        $inn = (string) Seller::Ros->config()['inn'];
        $rules = PartyRules::passport('buyer');
        $v = Validator::make(['buyer' => ['kind' => 'company', 'org' => 'ООО «Р.О.С.»', 'inn' => $inn, 'legal_address' => 'Москва', 'director' => 'Кузнецов']], $rules);
        $this->assertTrue($v->fails());
        $this->assertStringContainsString('не покупатель', $v->errors()->first('buyer.inn'));

        // Вписанный раньше «покупатель» Р.О.С. снимается переводом сделки через Р.О.С.
        $deal = $this->deal();
        $ros = User::create(['name' => 'ООО «Р.О.С.»', 'roles' => [Role::Buyer], 'manager_id' => $deal->buyer_id, 'approved_at' => now(), 'access' => []]);
        $party = Party::forUser($ros);
        $party->forceFill(['kind' => PartyKind::Company, 'inn' => $inn])->save();
        DealContract::for($deal)->forceFill(['buyer_user_id' => $ros->id, 'payer' => 'buyer'])->save();

        app(ChangeDealScheme::class)($deal->fresh(), DealScheme::Ros, $this->admin);
        $this->assertSame(DealScheme::Ros, $deal->fresh()->schemeOf());
        $this->assertNull(DealContract::for($deal->fresh())->buyer_user_id);
    }
}
