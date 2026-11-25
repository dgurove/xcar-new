<?php

namespace Tests\Feature;

use App\Logistics\Channel;
use App\Mail\Events\MessageParsed;
use App\Mail\Message;
use App\Mail\Thread;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\ContactDue;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Менеджер забирает ТС сам, а контакта владельца не видел — ход наш (25.11.2026, BMW X5 915891/2026: телефон только строкой письма
 * Совкомбанка «Готовы к передаче – 8-988-967-10-40», в карточке пусто, шаг писал «Ждём менеджера»). Ошибка молчаливая: менеджер
 * не знает, кому звонить, а у нас всё «ок».
 */
class ContactDueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private Deal $deal;

    private function deal(): Offer
    {
        Notification::fake();
        config(['xcar.mercure.hub' => '']);
        $this->admin = User::create(['name' => 'Катя', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Макаров Дмитрий', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '915891/2026', 'floor_price' => 944000, 'asking_price' => 1050000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $this->deal = app(AcceptBid::class)(app(PlaceBid::class)($offer->fresh(), $this->manager, 1050000), $this->admin, 30000, scheme: DealScheme::Prime)->fresh();
        // «Кто вывозит» — менеджер сам (как «Менеджер забирает ТС» у Совкомбанка).
        app(\App\Offers\Actions\AssignDealPickup::class)($offer->fresh(), $this->deal, 'manager', $this->admin);
        $account = DB::table('mail_accounts')->insertGetId(['slug' => 'deal', 'title' => 'deal', 'email' => 'deal@xcar.ru', 'scope' => 'offers', 'is_active' => true,
            'imap_host' => 'x', 'imap_username' => 'x', 'imap_password' => 'x', 'smtp_host' => 'x', 'smtp_username' => 'x', 'smtp_password' => 'x']);
        $thread = Thread::create(['account_id' => $account, 'subject' => '915891-26 BMW X5 Волгоград', 'offer_id' => $offer->id, 'last_message_at' => now()]);
        $message = Message::create(['account_id' => $account, 'thread_id' => $thread->id, 'direction' => 'in', 'from_email' => 'mikhail.korochkin@sovcomins.ru',
            'from_name' => 'Корочкин Михаил', 'subject' => '915891-26 BMW X5 Волгоград', 'date_at' => now()->subDay(),
            'text_body' => "1 778 095 руб. Готовы к передаче – 8-988-967-10-40\r\n\r\nКорочкин Михаил Александрович\r\nтел.: 88001002100\r\n",
            'parsed' => ['keys' => ['code:'.\App\Mail\Extraction\Code::key('915891/2026')]]]);
        MessageParsed::dispatch($message);

        return $offer->fresh();
    }

    public function test_manager_pickup_without_contact_is_our_turn(): void
    {
        $offer = $this->deal();
        $position = $offer->position(Track::Sale);
        $this->assertSame('Забрать автомобиль', $position->stage->name);
        $pickup = $offer->pickup();
        $this->assertSame(Channel::Manager, $pickup?->channel);
        $this->assertSame($this->manager->id, $pickup->executor_id);
        // Телефон из письма — в карточке сам (25.11.2026: «что из письма парсится — в админке встаёт само»), подпись Корочкина — нет.
        $this->assertSame('79889671040', \App\Support\Phone::normalize($offer->insured_phone));

        // Ход наш: дело, вкладка «Показать контакты», то же в SQL.
        $this->assertTrue(ContactDue::due($this->deal));
        $this->assertTrue($position->needsUs());
        $this->assertSame('contact', $position->todo()['key']);
        $this->assertTrue(Position::whereKey($position->id)->needsUs()->exists());
        $this->assertSame([$pickup->id], ContactDue::transportIds());
        $this->actingAs($this->admin)->get('http://crm.xcar.localhost/offers?preset=pickup_contacts')->assertOk()->assertSee('Показать контакты');

        // Страница: вместо «Ждём менеджера» — дело и окно «Что видит менеджер» с контактом, отмеченным к показу.
        $this->get('http://crm.xcar.localhost/offers/'.$offer->number.'?contact=1')->assertOk()
            ->assertSee('Показать контакты менеджеру')->assertDontSee('Ждём менеджера')->assertSee('+7 988 967-10-40')->assertDontSee('+7 800 100-21-00');
        $this->assertFalse(\App\Offers\ManagerView::sees($this->deal->fresh(), 'contact'));

        // Показали — менеджер видит, дело ушло.
        $this->put("http://crm.xcar.localhost/work/deals/{$this->deal->id}/manager-view", ['show' => ['contact']])->assertSessionHasNoErrors();
        $this->deal = $this->deal->fresh();
        $this->assertFalse(ContactDue::due($this->deal));
        $this->assertSame([], ContactDue::transportIds());
        $this->assertNotSame('contact', $offer->fresh()->position(Track::Sale)->todo()['key'] ?? null);
        $this->actingAs($this->manager)->get("http://xcar.localhost/deals/{$this->deal->id}")->assertOk()->assertSee('+7 988 967-10-40');
    }

    public function test_contact_typed_by_hand(): void
    {
        $offer = $this->deal();
        // Контакт правят в карточке (карандаш окна — `PUT /offers/{n}`), показывают — окном.
        $this->actingAs($this->admin)->put('http://crm.xcar.localhost/offers/'.$offer->number, ['_fields' => ['contacts'], 'contacts' => [['name' => 'Иван Сидоров', 'phone' => '8 921 340-06-57']]])->assertSessionHasNoErrors();
        $this->assertTrue(ContactDue::due($this->deal));
        $this->put("http://crm.xcar.localhost/work/deals/{$this->deal->id}/manager-view", ['show' => ['contact']])->assertSessionHasNoErrors();
        $offer = $offer->fresh();
        $this->assertSame('Иван Сидоров', $offer->insured_name);
        $this->assertSame('79213400657', \App\Support\Phone::normalize($offer->insured_phone));
        $this->assertFalse(ContactDue::due($this->deal->fresh()));
        $this->actingAs($this->manager)->get("http://xcar.localhost/deals/{$this->deal->id}")->assertOk()->assertSee('+7 921 340-06-57')->assertSee('Иван Сидоров');

        // Скрыли — у менеджера контакта нет, снова наш ход.
        $this->actingAs($this->admin)->put("http://crm.xcar.localhost/work/deals/{$this->deal->id}/manager-view", ['hide' => ['contact']])->assertSessionHasNoErrors();
        $this->assertTrue(ContactDue::due($this->deal->fresh()));
        $this->actingAs($this->manager)->get("http://xcar.localhost/deals/{$this->deal->id}")->assertOk()->assertDontSee('+7 921 340-06-57');
    }

    /**
     * Показали — в шаге «Менеджер видит контакт» со шторкой; правка контакта сотрудником уходит менеджеру сама: сообщением в чат
     * «Контакт для получения ТС изменён» и уведомлением (25.11.2026). До показа и правка системой — нет.
     */
    public function test_shown_contact_editable_and_changes_reach_manager(): void
    {
        $offer = $this->deal();
        $page = 'http://crm.xcar.localhost/offers/'.$offer->number;
        $url = "http://crm.xcar.localhost/work/deals/{$this->deal->id}/manager-view";

        // До показа правка карточки менеджеру не уходит — висит дело.
        $this->actingAs($this->admin)->put($page, ['_fields' => ['insured_name'], 'insured_name' => 'Корочкин Михаил'])->assertSessionHasNoErrors();
        $this->assertSame(0, \App\Offers\Disclosure::where('deal_id', $this->deal->id)->count());

        $this->put($url, ['show' => ['contact']])->assertSessionHasNoErrors();
        $chat = \App\Chats\Chat::where('offer_id', $offer->id)->where('user_id', $this->manager->id)->firstOrFail();
        $said = $chat->messages()->where('text', 'like', 'Контакт для получения ТС%')->latest('id')->firstOrFail()->text;
        $this->assertStringContainsString('988 967-10-40', $said);
        $this->get($page)->assertOk()->assertSee('Менеджер видит контакт')->assertSee('+7 988 967-10-40')
            ->assertSee('Ждём менеджера')->assertDontSee('Показать контакты менеджеру');

        // Поправили телефон в «Контакте владельца» — новый показ, сообщение и уведомление.
        Notification::fake();
        $this->put($page, ['_fields' => ['contacts'], 'contacts' => [['name' => 'Корочкин Михаил', 'phone' => '8 988 111-22-33']]])->assertSessionHasNoErrors();
        $this->assertSame(2, \App\Offers\Disclosure::where('deal_id', $this->deal->id)->count());
        $said = $chat->messages()->where('text', 'like', 'Контакт для получения ТС изменён%')->latest('id')->firstOrFail();
        $this->assertStringContainsString('+7 988 111-22-33', $said->text);
        Notification::assertSentTo($this->manager, \App\Notifications\ChatNotice::class);
        $this->actingAs($this->manager)->get("http://xcar.localhost/deals/{$this->deal->id}")->assertOk()->assertSee('+7 988 111-22-33');

        // Система (письмо) контакт поменяла — менеджеру не уходит.
        app(\App\Offers\Actions\UpdateOffer::class)($offer->fresh(), ['inspection_address' => 'Волгоград, ул. Мира, 1'], null);
        $this->assertSame(2, \App\Offers\Disclosure::where('deal_id', $this->deal->id)->count());
    }
}
