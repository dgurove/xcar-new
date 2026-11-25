<?php

namespace Tests\Feature;

use App\Chats\Actions\OpenDirect;
use App\Chats\Actions\OpenEnquiry;
use App\Chats\Actions\PostMessage;
use App\Chats\Chat;
use App\Chats\Direct;
use App\Support\Surface;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Личка (22.11.2026, `Chats\Direct`): кто кому может написать первым, одна личка на пару в обе стороны, чужую личку модератор
 * не открывает, админ читает без поля ответа, счётчики и «Обращение» её не путают. Доступ и утечки — поэтому тест.
 */
class DirectChatTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $moderator;

    private User $manager;

    private User $other;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::create(['name' => 'Андрей Админов', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->moderator = User::create(['name' => 'Алина Модерова', 'phone' => '79000000002', 'roles' => [Role::Moderator], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Дмитрий Макаров', 'phone' => '79000000003', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->other = User::create(['name' => 'Роман Другой', 'phone' => '79000000004', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->buyer = User::create(['name' => 'Пётр Покупатель', 'phone' => '79000000005', 'roles' => [Role::Buyer], 'manager_id' => $this->manager->id,
            'approved_at' => now(), 'buyer_access_at' => now()]);
    }

    public function test_who_can_start(): void
    {
        $this->assertTrue(Direct::canStart($this->admin, $this->manager));
        $this->assertTrue(Direct::canStart($this->moderator, $this->admin));
        $this->assertFalse(Direct::canStart($this->manager, $this->moderator), 'менеджер сотруднику — через «Администрацию XCAR»');
        $this->assertTrue(Direct::canStart($this->manager, $this->buyer));
        $this->assertTrue(Direct::canStart($this->buyer, $this->manager));
        $this->assertFalse(Direct::canStart($this->manager, $this->other), 'менеджер менеджеру — нет');
        $this->assertFalse(Direct::canStart($this->other, $this->buyer), 'чужому покупателю — нет');
        $this->assertFalse(Direct::canStart($this->buyer, $this->admin), 'покупатель — только своему менеджеру');
        $this->assertTrue(Direct::canStart($this->admin, $this->buyer), 'сотрудник покупателю — от «Администрации XCAR»');
        $this->assertFalse(Direct::canStart($this->admin, $this->admin));

        $this->actingAs($this->manager)->get(Surface::Site->url('/chats/with/'.$this->other->id))->assertNotFound();
        $this->actingAs($this->manager)->post(Surface::Site->url('/chats/with/'.$this->other->id), ['text' => 'Привет'])->assertNotFound();
        $this->actingAs($this->manager)->get(Surface::Site->url('/chats/with/'.$this->admin->id))->assertNotFound();
        $this->actingAs($this->admin)->get(Surface::Crm->url('/work/chats/with/'.$this->manager->id))->assertOk()->assertSee('Дмитрий Макаров');
    }

    /** Сотрудник менеджеру и покупателю пишет от «Администрации XCAR» — в его чат площадки, имя сотрудника скрыто. */
    public function test_staff_writes_as_platform(): void
    {
        $a = app(OpenDirect::class)($this->admin, $this->manager);
        $b = app(OpenDirect::class)($this->moderator, $this->manager);
        $this->assertSame($a->id, $b->id);
        $this->assertSame([$this->manager->id, null, null], [$a->user_id, $a->manager_id, $a->offer_id]);

        $this->actingAs($this->admin)->post(Surface::Crm->url('/chats/with/'.$this->buyer->id), ['text' => 'Добрый день'])->assertOk();
        $chat = Chat::where('user_id', $this->buyer->id)->sole();
        $this->assertNull($chat->manager_id);
        $this->assertSame(Chat::PLATFORM, $chat->messages()->sole()->authorName($chat, $this->buyer));
        $this->actingAs($this->admin)->get(Surface::Crm->url('/work/chats/with/'.$this->buyer->id))->assertRedirect(Chat::base().'/'.$chat->id);
    }

    public function test_one_chat_per_pair_both_ways(): void
    {
        $a = app(OpenDirect::class)($this->moderator, $this->admin);
        $b = app(OpenDirect::class)($this->admin, $this->moderator);
        $this->assertSame($a->id, $b->id);

        // Переписка покупателя с менеджером — та же личка пары.
        $enquiry = app(OpenEnquiry::class)($this->buyer, null, null);
        $this->assertSame($enquiry->id, app(OpenDirect::class)($this->manager, $this->buyer)->id);
        $this->assertSame(2, Chat::count());

        // Первое сообщение заводит личку, второе — в ту же.
        $this->actingAs($this->moderator)->post(Surface::Crm->url('/chats/with/'.$this->admin->id), ['text' => 'Привет'])->assertOk()->assertHeader('X-Chat-Id');
        $this->actingAs($this->admin)->post(Surface::Crm->url('/chats/with/'.$this->moderator->id), ['text' => 'Привет'])->assertOk();
        $this->assertSame(2, Chat::count());
    }

    public function test_access_and_counters(): void
    {
        $chat = $this->legacy($this->admin);
        app(PostMessage::class)($chat, $this->manager, 'Вопрос по оплате', []);

        // Модератор чужую личку не открывает ни списком, ни номером.
        $this->actingAs($this->moderator)->get(Surface::Crm->url('/work/chats/'.$chat->id))->assertNotFound();
        $this->actingAs($this->moderator)->get(Surface::Crm->url('/work/chats?q=Макаров'))->assertDontSee('Вопрос по оплате');
        $this->actingAs($this->moderator)->get(Surface::Site->url('/chats/'.$chat->id.'/messages'))->assertNotFound();

        // Админ — сторона: видит, пишет, счётчик «Чатов» CRM считает личку.
        $this->assertSame(1, $this->admin->fresh()->unreadDirectChats());
        $this->assertSame(1, $this->admin->fresh()->unreadChats());
        $this->actingAs($this->admin)->get(Surface::Crm->url('/work/chats'))->assertOk()->assertSee('Вопрос по оплате');
        $this->actingAs($this->admin)->get(Surface::Crm->url('/work/chats/'.$chat->id))->assertOk()->assertSee('data-chat-readonly-value="false"', false);
        $this->assertSame(0, $this->admin->fresh()->unreadDirectChats());

        // Чужую личку админ читает без поля ответа и писать в неё не может.
        $mod = $this->legacy($this->moderator);
        app(PostMessage::class)($mod, $this->manager, 'Модератору лично', []);
        $this->actingAs($this->admin)->get(Surface::Crm->url('/work/chats/'.$mod->id))->assertOk()->assertSee('data-chat-readonly-value="true"', false);
        $this->actingAs($this->admin)->post(Surface::Crm->url('/chats/'.$mod->id.'/messages'), ['text' => 'Влезу'])->assertNotFound();
        // Без поиска чужая личка в списке админа не стоит, с поиском — находится.
        $this->actingAs($this->admin)->get(Surface::Crm->url('/work/chats'))->assertDontSee('Модератору лично');
        $this->actingAs($this->admin)->get(Surface::Crm->url('/work/chats?q=Модерова'))->assertSee('Модератору лично');
    }

    public function test_search_puts_person_first(): void
    {
        $page = $this->actingAs($this->admin)->get(Surface::Crm->url('/work/chats?q=Макаров'))->assertOk();
        $page->assertSee('/work/chats/with/'.$this->manager->id, false);
        $this->actingAs($this->admin)->get(Surface::Crm->url('/work/chats?q=Покупатель'))->assertSee('/work/chats/with/'.$this->buyer->id, false);
        // Менеджер сотрудников поимённо не находит — только «Администрацию XCAR».
        $this->actingAs($this->manager)->get(Surface::Site->url('/chats?q=Админов'))->assertDontSee('/chats/with/'.$this->admin->id, false);
    }

    public function test_contacts_does_not_pick_up_direct_chat(): void
    {
        $chat = $this->legacy($this->admin);
        app(PostMessage::class)($chat, $this->admin, 'Лично менеджеру', []);
        $this->actingAs($this->manager)->get(Surface::Site->url('/contacts'))->assertDontSee('Лично менеджеру');
    }

    /** Личка сотрудника с менеджером, начатая до 25.11.2026: открыта обоим как была. */
    private function legacy(User $staff): Chat
    {
        return Chat::create(['offer_id' => null, 'user_id' => $this->manager->id, 'manager_id' => $staff->id]);
    }
}
