<?php

namespace Tests\Feature;

use App\Mail\Message;
use App\Mail\Thread;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\Events\InsurerReplied;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Outcome;
use App\Workflow\Preset;
use App\Workflow\Presets\Route;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * «Поставщик подтвердил оплату» (18.11.2026, владелец по 2610142050: Альфа написала «Денежные средства на счету компании», а
 * сделка просила у менеджера платёжку): шаг «Оплата счёта» проходит без платёжки — кнопкой и само по письму поставщика.
 */
class SupplierPaidTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private function onInvoice(string $claim = '5491/046/00861/26'): Deal
    {
        Notification::fake();
        Queue::fake();
        config(['xcar.mercure.hub' => '']);
        $this->admin ??= User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Дмитрий Макаров', 'phone' => '7900000'.random_int(1000, 9999), 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'АльфаСтрахование '.$claim]);
        $vendor->forceFill(['pay_before_pickup' => true])->save();
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => $claim, 'floor_price' => 900000, 'asking_price' => 1100000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        $workflow = $vendor->fresh()->workflow(Track::Sale)->id;
        app(EnterStage::class)($offer, Stage::where('workflow_id', $workflow)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $manager, 1150000), $this->admin, scheme: DealScheme::Insurer)->fresh();
        app(EnterStage::class)($offer->fresh(), Stage::where('workflow_id', $workflow)->where('name', 'Оплата счёта')->firstOrFail());

        return $deal->fresh();
    }

    private function letter(Deal $deal, string $text): Message
    {
        $account = DB::table('mail_accounts')->where('slug', 'deal')->value('id') ?? DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = Thread::create(['account_id' => $account, 'subject' => $deal->offer->claim_ref, 'offer_id' => $deal->offer_id, 'last_message_at' => now()]);

        return Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => 'in', 'from_email' => 'implementation@alfastrah.ru',
            'date_at' => now(), 'subject' => 'RE: '.$deal->offer->claim_ref, 'text_body' => $text]);
    }

    public function test_button_passes_payment_without_proof(): void
    {
        $deal = $this->onInvoice();
        $offer = $deal->offer;
        $this->assertSame('Оплата счёта', $offer->position(Track::Sale)->stage->name);
        $this->assertTrue(Requirement::where('deal_id', $deal->id)->whereNull('done_at')->exists(), 'просьба приложить платёжку');
        $exit = Outcome::where('stage_id', $offer->position(Track::Sale)->stage_id)->where('label', Route::SUPPLIER_PAID)->firstOrFail();

        $this->actingAs($this->admin)->post('http://'.config('xcar.crm_host').'/offers/'.$offer->number.'/exit/'.$exit->id)->assertSessionHasNoErrors();

        $this->assertSame('Подготовка договора', $offer->fresh()->unsetRelation('positions')->position(Track::Sale)->stage->name);
        $this->assertFalse(Requirement::where('deal_id', $deal->id)->whereNull('done_at')->exists(), 'платёжку больше не просим');
        $this->assertNotNull($deal->fresh()->prepaid_at, 'Альфа оплачена — можно забирать');
    }

    public function test_supplier_letter_passes_payment_and_unpaid_letter_does_not(): void
    {
        $deal = $this->onInvoice('5491/046/00862/26');
        InsurerReplied::dispatch($deal, $this->letter($deal, "Добрый день!\nВнимание!!! Оплата принимается только от плательщика по счету!!! В случае неоплаты сделка расторгается."));
        $this->assertSame('Оплата счёта', $deal->offer->fresh()->position(Track::Sale)->stage->name, 'письмо со счётом — не подтверждение');
        InsurerReplied::dispatch($deal, $this->letter($deal, "Добрый день!\nОплата не поступила."));
        $this->assertSame('Оплата счёта', $deal->offer->fresh()->position(Track::Sale)->stage->name);

        InsurerReplied::dispatch($deal->fresh(), $this->letter($deal, "Добрый день! Денежные средства на счету компании, прошу подготовить и подписать с контрагентом ДКП, после чего передать поврежденное ТС."));
        $this->assertSame('Подготовка договора', $deal->offer->fresh()->position(Track::Sale)->stage->name);
        $this->assertNotNull($deal->fresh()->prepaid_at);
    }
}
