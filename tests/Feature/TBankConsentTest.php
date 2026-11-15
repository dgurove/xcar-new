<?php

namespace Tests\Feature;

use App\Chats\Message as ChatMessage;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\Templates\Tinkoff;
use App\Mail\Message;
use App\Notifications\Notify;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\FillFromLetter;
use App\Offers\Actions\PlaceBid;
use App\Offers\CommissionMode;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferState;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Т-Страхование (маршрут `TBank`): сначала «Берёте в работу?» с машиной и VIN, и только после «Беру в работу» — письмо
 * поставщику, одинаковым текстом «Прошу прислать контакты…» (15.11.2026: письмо «интерес за 500 000 ₽» ушло до согласия и
 * при лоте 833 000). Ошибка здесь — письмо поставщику, которое не отзовёшь.
 */
class TBankConsentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private function accepted(): array
    {
        Storage::fake('private');
        Notification::fake();
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Т-Страхование тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::TBank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => 'AUT-26-781222', 'vin' => 'WDD2050402R514538', 'floor_price' => 500000,
            'owner_price' => 500000, 'asking_price' => 650000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 600000), $this->admin, 0, CommissionMode::Withheld, scheme: DealScheme::OwnerDkp);

        return [$offer->fresh(), $deal->fresh()];
    }

    public function test_manager_is_asked_with_vin_before_any_letter_and_letter_is_contacts_request(): void
    {
        [$offer, $deal] = $this->accepted();
        $stage = $offer->stage();
        $this->assertSame('Согласие менеджера', $stage->name);
        // Письма поставщику на этом шаге нет — ни кнопки, ни окна после «Принять».
        $this->assertNull($offer->vendorLetterUrl($stage));
        $ask = ChatMessage::whereNotNull('requirement_id')->latest('id')->firstOrFail();
        $this->assertStringStartsWith(Notify::CONSENT_ASK."\n", $ask->text);
        $this->assertStringContainsString('VIN WDD2050402R514538', $ask->text);

        app(TakeExit::class)($offer, $stage->exitsFor(Actor::Manager, $deal)->firstWhere('label', 'Беру в работу'), Actor::Manager, $this->manager);
        $offer = $offer->fresh();
        $position = $offer->position(Track::Sale);
        $this->assertSame('Письмо о покупке', $position->stage->name);
        $this->assertSame('unsent', $position->letterStatus());
        // Одинаковый текст, без суммы, машины и номера.
        $this->assertSame("Добрый день!\n\nПрошу прислать контакты и данные для организации сделки.\n\nС уважением,\nАндрей Кузнецов", $position->stage->template->body);
    }

    public function test_changed_mind_leads_back_to_bidding(): void
    {
        [$offer, $deal] = $this->accepted();
        $exits = $offer->stage()->exitsFor(Actor::Manager, $deal);
        $this->assertSame(['Беру в работу', 'Я передумал'], $exits->pluck('label')->all());
        $this->assertSame('Приём подтверждений', $exits->firstWhere('label', 'Я передумал')->to->name);
        $this->assertTrue($exits->firstWhere('label', 'Я передумал')->isRetreat());
    }

    public function test_vin_from_tbank_subjects_and_reply_fills_empty_vin_of_sold_offer(): void
    {
        foreach ([
            'AUT-26-781222 || 2019 Mercedes-Benz C-class (Р380КХ774 / WDD2050402R514538) || Москва' => 'WDD2050402R514538',
            'RE: AUT-26-1003653 2014 Mitsubishi Outlander (О345ЕА47 / Z8TXTGF2WEM014888)' => 'Z8TXTGF2WEM014888',
            'AUT-26-971451 // 2025 Volkswagen Tiguan L (А333ВР64 / LSVXEABD4S2033884)' => 'LSVXEABD4S2033884',
        ] as $subject => $vin) {
            $this->assertSame($vin, (new Tinkoff)->extract($subject, '')['vin']['value'] ?? null, $subject);
        }

        $vendor = Vendor::create(['name' => 'Т-Страхование тест', 'senders' => ['tbank.ru']]);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => 'AUT-26-1003653']);
        $offer->forceFill(['state' => OfferState::Sold])->save();
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers',
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $message = Message::create(['account_id' => $account, 'direction' => 'in', 'from_email' => 'p.roevnev@tbank.ru',
            'subject' => 'RE: AUT-26-1003653 2014 Mitsubishi Outlander (О345ЕА47 / Z8TXTGF2WEM014888)',
            'parsed' => ['keys' => ['code:'.Code::key('AUT-26-1003653')], 'fields' => ['vin' => ['value' => 'Z8TXTGF2WEM014888', 'source' => 'subject']]]]);

        app(FillFromLetter::class)($offer->fresh(), $message, read: false);
        $this->assertSame('Z8TXTGF2WEM014888', $offer->fresh()->vin);
    }
}
