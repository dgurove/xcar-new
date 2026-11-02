<?php

namespace Tests\Feature;

use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\WhatsApp\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Группа WhatsApp с номера владельца (03.11.2026). Это доступ и номер владельца: машина раньше волны или «только вам»
 * другому — в группе её увидят все; дубль или рассылка без пауз — повод заблокировать личный WhatsApp. Ошибка молчалива:
 * группа просто получит лишнее.
 */
class WhatsAppGroupTest extends TestCase
{
    use RefreshDatabase;

    private const ME = '971500000001';

    private User $manager;

    private Offer $offer;

    /** @var list<string> номера участников, которые отдаёт служба */
    private array $members;

    private array $status = ['connected' => true, 'logged_in' => true, 'stopped' => null, 'in_group' => true, 'group' => 'XCAR admin'];

    private int $sendStatus = 200;

    /** @var list<array{key: string, caption: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-04 12:00', 'Europe/Moscow'));
        config(['xcar.mercure.hub' => '', 'xcar.whatsapp' => ['via' => 'http://zurich:9444', 'token' => 't',
            'since' => now()->subDay()->toIso8601String(), 'daily' => 100]]);
        Http::fake([
            'zurich:9444/status' => fn () => Http::response($this->status + ['me' => self::ME, 'members' => $this->members]),
            'zurich:9444/send' => function ($r) {
                if ($this->sendStatus !== 200) {
                    return Http::response(['error' => 'нет'], $this->sendStatus);
                }
                // Тело — multipart: части по имени.
                $parts = collect($r->data())->pluck('contents', 'name');
                $this->sent[] = ['key' => $parts['key'], 'caption' => $parts['caption']];

                return Http::response(['id' => 'M'.count($this->sent)]);
            },
        ]);
        $this->manager = User::create(['name' => 'Роман', 'phone' => '79000000601', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->members = [self::ME, '79000000601'];
        $this->offer = $this->openOffer();
    }

    private function openOffer(): Offer
    {
        $offer = Offer::create(['vendor_id' => Vendor::firstOrCreate(['name' => 'Альфа тест'])->id, 'floor_price' => 900000,
            'asking_price' => 1000000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open, 'published_at' => now()->subMinutes(10)])->save();

        return $offer->fresh();
    }

    private function wave(Offer $offer, User $user, $opens): void
    {
        $offer->viewers()->updateOrCreate(['user_id' => $user->id], ['opens_at' => $opens, 'notified_at' => $opens]);
    }

    private function step(): bool
    {
        return app(Group::class)->step();
    }

    /** Пауза прошла. */
    private function later(): void
    {
        $this->travel(11)->minutes();
    }

    public function test_offer_goes_once_when_open_to_everyone_in_group(): void
    {
        // Волна участника ещё не наступила — в группу нельзя: увидят все.
        $this->wave($this->offer, $this->manager, now()->addHours(2));
        $this->assertFalse($this->step());
        $this->assertSame([], $this->sent);

        $this->wave($this->offer, $this->manager, now()->subMinute());
        $this->assertTrue($this->step());
        $this->assertCount(1, $this->sent);
        $this->assertSame('offer:'.$this->offer->id, $this->sent[0]['key']);
        $this->assertStringContainsString('1 000 000', $this->sent[0]['caption']);
        $this->assertStringNotContainsString('Срочно', $this->sent[0]['caption']);
        $this->assertSame('M1', DB::table('whatsapp_sent')->where('offer_id', $this->offer->id)->value('message_id'));

        // Ушла — второй раз никогда, и после снятия с продажи и возврата тоже.
        $this->later();
        $this->assertFalse($this->step());
        $this->offer->forceFill(['state' => OfferState::Draft])->save();
        $this->offer->forceFill(['state' => OfferState::Open, 'published_at' => now()])->save();
        $this->later();
        $this->assertFalse($this->step());
        $this->assertCount(1, $this->sent);
    }

    public function test_only_for_another_manager_never_goes(): void
    {
        // «Открыто только Вам» другому: участнику группы оно не откроется никогда.
        $other = User::create(['name' => 'Денис', 'phone' => '79000000602', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->offer->viewers()->delete();
        $this->wave($this->offer, $other, now()->subMinute());
        $this->assertFalse($this->step());
        $this->assertSame([], $this->sent);
    }

    public function test_old_demo_and_locked_do_not_go(): void
    {
        $this->wave($this->offer, $this->manager, now()->subMinute());
        // Вышло до `since` — старое при запуске не шлём; `since` пуст — само не уходит ничего.
        config(['xcar.whatsapp.since' => now()->toIso8601String()]);
        $this->assertFalse($this->step());
        config(['xcar.whatsapp.since' => null]);
        $this->assertFalse($this->step());
        config(['xcar.whatsapp.since' => now()->subDay()->toIso8601String()]);

        // Шеринг закрыт — в WhatsApp не защитить от пересылки.
        $this->offer->forceFill(['share_locked' => true])->save();
        $this->assertFalse($this->step());
        $this->assertSame([], $this->sent);
    }

    public function test_stranger_or_closed_member_stops_the_group(): void
    {
        $this->wave($this->offer, $this->manager, now()->subMinute());
        $this->members[] = '79990001122';
        $this->assertFalse($this->step());
        $this->assertStringContainsString('+79990001122', implode(' ', app(Group::class)->problems()));

        // Свой, но доступ закрыт — тоже посторонний.
        array_pop($this->members);
        $this->manager->forceFill(['approved_at' => null, 'rejected_at' => now()])->save();
        $this->assertFalse($this->step());
        $this->assertStringContainsString('Роман', implode(' ', app(Group::class)->problems()));
        $this->assertSame([], $this->sent);
    }

    public function test_pause_night_and_daily_limit(): void
    {
        $second = $this->openOffer();
        $this->wave($this->offer, $this->manager, now()->subMinute());
        $this->wave($second, $this->manager, now()->subMinute());
        $this->assertTrue($this->step());
        // Сразу следом — нет: пауза 30–90 с.
        $this->assertFalse($this->step());
        $this->assertGreaterThanOrEqual(30, app(Group::class)->wait());
        $this->travel(91)->seconds();
        // Ночью тишина — уходит утром.
        $this->travelTo(Carbon::parse('2026-11-04 23:30', 'Europe/Moscow'));
        $this->assertFalse($this->step());
        $this->travelTo(Carbon::parse('2026-11-05 07:59', 'Europe/Moscow'));
        $this->assertFalse($this->step());
        $this->travelTo(Carbon::parse('2026-11-05 09:00', 'Europe/Moscow'));
        config(['xcar.whatsapp.daily' => 0]);
        $this->assertFalse($this->step());
        config(['xcar.whatsapp.daily' => 100]);
        $this->assertTrue($this->step());
        $this->assertCount(2, $this->sent);
    }

    public function test_stopped_service_and_refusals(): void
    {
        $this->wave($this->offer, $this->manager, now()->subMinute());
        // Бан или отвязка — ничего не шлём и машину не берём.
        $this->status['stopped'] = 'временная блокировка';
        $this->assertFalse($this->step());
        $this->assertFalse(DB::table('whatsapp_sent')->exists());
        $this->status['stopped'] = null;

        // Служба трижды отказала по самому сообщению — больше не пробуем.
        $this->sendStatus = 502;
        foreach (range(1, 3) as $i) {
            $this->assertFalse($this->step());
            $this->later();
        }
        $this->sendStatus = 200;
        $this->assertFalse($this->step());
        $this->assertSame(3, (int) DB::table('whatsapp_sent')->value('attempts'));
        $this->assertSame([], $this->sent);
    }

    public function test_check_sends_without_remembering(): void
    {
        $this->wave($this->offer, $this->manager, now()->subMinute());
        Cache::forget('whatsapp:pace');
        $this->artisan('whatsapp:test', ['numbers' => [(string) $this->offer->number]])->assertSuccessful();
        $this->assertCount(1, $this->sent);
        $this->assertStringStartsWith('test:', $this->sent[0]['key']);
        $this->assertFalse(DB::table('whatsapp_sent')->exists());
    }
}
