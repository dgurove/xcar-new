<?php

namespace Tests\Feature;

use App\Billing\Party;
use App\Billing\Seller;
use App\Http\Admin\MailController;
use App\Mail\Message;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\SaveDealContract;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\InsurerReplies;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\AnswerRequirement;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Asks;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Альфа после «Подтвердили покупку» просит реквизиты для счёта (03.11.2026, владелец): «страховой напрямую» — данные
 * покупателя у менеджера (или админа за него), шаг проходит сам, когда они со сканом; платим мы (Р.О.С.) — сразу письмо с
 * нашими реквизитами. Ошибка молчалива: Альфа выставит счёт не на того или письмо уйдёт пустым.
 */
class AlfaRequisitesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private function deal(bool $vat): array
    {
        Storage::fake('private');
        Notification::fake();
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Денис Трофимов', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        $vendor->forceFill(['pay_before_pickup' => true])->save();
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1200000, 'prices_include_vat' => $vat, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 1200000), $this->admin, 50000, CommissionMode::Payout,
            scheme: DealScheme::forOffer($offer->fresh()), ownerPrice: 900000);
        $this->exit($offer, 'Поставщик согласовал');
        $this->exit($offer, 'Беру в работу');
        $this->assertSame('Подтвердили покупку поставщику', $offer->fresh()->stage()->name);

        return [$offer->fresh(), $deal->fresh()];
    }

    private function exit(Offer $offer, string $label): void
    {
        $o = $offer->fresh();
        $deal = $o->deal()->first();
        $exit = $o->stage()->exits->first(fn ($e) => $e->label === $label && $e->fits($deal));
        $this->assertNotNull($exit, 'на «'.$o->stage()->name.'» нет «'.$label.'»');
        app(TakeExit::class)($o, $exit, $exit->actor, $exit->actor === Actor::Manager ? $this->manager : $this->admin);
    }

    public function test_reply_asking_requisites_is_recognized(): void
    {
        $ask = new Message(['direction' => 'in', 'text_body' => "Добрый день!\nПросьба прислать реквизиты для выставления счета. Без НДС.\nС уважением, Берсенева Елена"]);
        $other = new Message(['direction' => 'in', 'text_body' => "Добрый день!\nГОТС поступили на реализацию."]);
        $this->assertTrue(InsurerReplies::asksRequisites($ask));
        $this->assertFalse(InsurerReplies::asksRequisites($other));
    }

    public function test_insurer_scheme_asks_buyer_data_then_letter_with_passport(): void
    {
        [$offer, $deal] = $this->deal(vat: false);
        $this->assertSame(DealScheme::Insurer, $deal->scheme);

        $this->exit($offer, 'Поставщик просит реквизиты');
        $stage = $offer->fresh()->stage();
        $this->assertSame('Данные покупателя для счёта', $stage->name);
        $this->assertSame(Asks::Buyer, $stage->asks);
        $this->assertTrue($deal->fresh()->collectsBuyer());
        $requirement = $deal->fresh()->openRequirement()->firstOrFail();
        $this->assertSame('Заполните данные покупателя', $requirement->title);

        // Кнопкой без данных шаг не пройти — письмо ушло бы пустым.
        try {
            app(AnswerRequirement::class)($requirement, $stage->exitsFor(Actor::Manager, $deal->fresh())->first(), $this->manager);
            $this->fail('без данных покупателя шаг прошёл');
        } catch (ValidationException) {
        }

        // Админ за менеджера: покупатель — сам менеджер, паспорт со сканом — шаг проходит сам, ход наш (письмо не ушло).
        app(SaveDealContract::class)(DealContract::for($deal->fresh()), ['buyer_id' => 'me'], $this->admin);
        $this->assertSame('Данные покупателя для счёта', $offer->fresh()->stage()->name, 'без скана шаг стоит');
        $this->passportFor($this->manager);
        $offer = $offer->fresh();
        $this->assertSame('Реквизиты поставщику', $offer->stage()->name);
        $this->assertSame(WaitsFor::Us, $offer->position(Track::Sale)->waits_for);
        $this->assertNull($deal->fresh()->contract->ready_at, 'договора у «страховой напрямую» нет — «ДКП готов» не шлём');

        // В письме — реквизиты покупателя, скан — у него же (вложение черновика).
        $buyer = MailController::placeholders($offer)['buyer'];
        $this->assertStringContainsString('Денис Трофимов', $buyer);
        $this->assertStringContainsString('Паспорт: 11 11 № 111111', $buyer);
        $this->assertTrue($deal->fresh()->supplierInvoicee()->hasPassportScans());

        $this->exit($offer, 'Счёт от поставщика получен');
        $this->assertSame('Счёт выставлен менеджеру', $offer->fresh()->stage()->name);
    }

    public function test_ros_scheme_sends_our_requisites_without_manager(): void
    {
        [$offer, $deal] = $this->deal(vat: true);
        $this->assertSame(DealScheme::Ros, $deal->scheme);
        Party::seller(Seller::Ros)->update(['name' => 'ООО «Р.О.С.»', 'inn' => '7700000000', 'kind' => 'company']);

        $this->exit($offer, 'Поставщик просит реквизиты');
        $offer = $offer->fresh();
        $this->assertSame('Реквизиты поставщику', $offer->stage()->name);
        $this->assertNull($deal->fresh()->openRequirement()->first(), 'менеджеру задачи нет');
        $buyer = MailController::placeholders($offer)['buyer'];
        $this->assertStringContainsString('Р.О.С.', $buyer);
        $this->assertStringContainsString('ИНН 7700000000', $buyer);
    }

    public function test_supplier_may_invoice_right_away(): void
    {
        [$offer] = $this->deal(vat: false);
        $this->exit($offer, 'Поставщик сразу выставил счёт');
        $this->assertSame('Счёт выставлен менеджеру', $offer->fresh()->stage()->name);
    }
}
