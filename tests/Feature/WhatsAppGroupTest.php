<?php

namespace Tests\Feature;

use App\Offers\Offer;
use App\Offers\OfferState;
use App\Vendors\Vendor;
use App\WhatsApp\Group;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Группа WhatsApp с номера владельца (03.11.2026). Это номер владельца: дубль или рассылка без пауз — повод заблокировать
 * личный WhatsApp; старое при запуске — сотня сообщений разом. Ошибка молчалива: группа просто получит лишнее.
 */
class WhatsAppGroupTest extends TestCase
{
    use RefreshDatabase;

    private Offer $offer;

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
            'zurich:9444/status' => fn () => Http::response($this->status),
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
        $this->offer = $this->openOffer();
    }

    private function openOffer(): Offer
    {
        $offer = Offer::create(['vendor_id' => Vendor::firstOrCreate(['name' => 'Альфа тест'])->id, 'floor_price' => 900000,
            'asking_price' => 1000000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open, 'published_at' => now()->subMinutes(10)])->save();

        return $offer->fresh();
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

    public function test_offer_goes_once(): void
    {
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

    public function test_old_demo_and_locked_do_not_go(): void
    {
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

    public function test_pause_night_and_daily_limit(): void
    {
        $second = $this->openOffer();
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
        Cache::forget('whatsapp:pace');
        $this->artisan('whatsapp:test', ['numbers' => [(string) $this->offer->number]])->assertSuccessful();
        $this->assertCount(1, $this->sent);
        $this->assertStringStartsWith('test:', $this->sent[0]['key']);
        $this->assertFalse(DB::table('whatsapp_sent')->exists());
    }
}
