<?php

namespace Tests\Feature;

use App\Offers\Estimate;
use App\Offers\EstimateState;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\WhatsApp\Inbox;
use App\WhatsApp\LinkCode;
use App\WhatsApp\Sender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * WhatsApp с номера владельца (03.11.2026): открытое всем — в группу, открытое не всем и просьбы оценить — в личные, привязка
 * кодом. Это номер владельца: дубль или рассылка без пауз — повод заблокировать личный WhatsApp; старое при запуске — сотня
 * сообщений разом; «только вам» в группе — утечка чужого эксклюзива. Ошибка молчалива: просто уйдёт лишнее.
 */
class WhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private Offer $offer;

    private array $status = ['connected' => true, 'logged_in' => true, 'stopped' => null, 'in_group' => true, 'group' => 'XCAR admin'];

    private int $sendStatus = 200;

    /** Обрыв связи до службы вместо ответа. */
    private bool $offline = false;

    /** @var list<array{key: string, caption: string, to: string, files: list<string>}> */
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
                if ($this->offline) {
                    return Http::failedConnection();
                }
                if ($this->sendStatus !== 200) {
                    return Http::response(['error' => 'нет'], $this->sendStatus);
                }
                // Тело — multipart: части по имени.
                $parts = collect($r->data());
                $field = $parts->pluck('contents', 'name');
                $this->sent[] = ['key' => $field['key'], 'caption' => $field['caption'], 'to' => (string) ($field['to'] ?? ''),
                    'files' => $parts->whereNotNull('filename')->pluck('name')->all()];

                return Http::response(['id' => 'M'.count($this->sent)]);
            },
        ]);
        $this->offer = $this->openOffer();
    }

    private function openOffer(): Offer
    {
        $offer = Offer::create(['vendor_id' => Vendor::firstOrCreate(['name' => 'Альфа тест'])->id, 'floor_price' => 900000,
            'asking_price' => 1000000, 'bids_close_at' => now()->addDay()]);
        // Публичный номер — как при публикации (`ChangeOfferState`): без него в подписи нет ссылки.
        $offer->forceFill(['state' => OfferState::Open, 'published_at' => now()->subMinutes(10), 'number' => OfferNumber::next()])->save();

        return $offer->fresh();
    }

    private function step(): bool
    {
        return app(Sender::class)->step();
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
        $this->assertSame('', $this->sent[0]['to']);
        $this->assertSame('M1', DB::table('whatsapp_messages')->where('key', 'offer:'.$this->offer->id)->value('message_id'));

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
        $this->assertGreaterThanOrEqual(30, app(Sender::class)->wait());
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
        $this->assertFalse(DB::table('whatsapp_messages')->exists());
        $this->status['stopped'] = null;

        // Служба трижды отказала по самому сообщению — больше не пробуем.
        $this->sendStatus = 502;
        foreach (range(1, 3) as $i) {
            $this->assertFalse($this->step());
            $this->later();
        }
        $this->sendStatus = 200;
        $this->assertFalse($this->step());
        $this->assertSame(3, (int) DB::table('whatsapp_messages')->value('attempts'));
        $this->assertSame([], $this->sent);
    }

    public function test_pdf_with_preview_and_link_on_next_line(): void
    {
        $this->addPhotos($this->offer);
        $this->assertTrue($this->step());
        $this->assertSame(['pdf', 'thumb'], $this->sent[0]['files']);
        // Ссылка — следующей строкой, без пустой (владелец 03.11.2026).
        $this->assertMatchesRegularExpression('~000\nhttps?://\S+/offers/~u', $this->sent[0]['caption']);
        $this->assertStringNotContainsString("\n\n", $this->sent[0]['caption']);
    }

    public function test_broken_offer_does_not_hold_the_queue(): void
    {
        // PDF первой машины не собрать (кадр пропал с диска) — попытка тратится, после трёх очередь идёт дальше.
        $this->addPhotos($this->offer);
        $this->offer->fresh()->visiblePhotos()->each(fn ($m) => @unlink($m->getPath()));
        $second = $this->openOffer();
        foreach (range(1, 3) as $i) {
            $this->assertFalse($this->step());
            $this->later();
        }
        $this->assertTrue($this->step());
        $this->assertSame('offer:'.$second->id, $this->sent[0]['key']);
    }

    public function test_lost_connection_does_not_spend_attempts(): void
    {
        // Туннель лёг — машина ждёт, сколько бы ни лежал.
        $this->offline = true;
        foreach (range(1, 5) as $i) {
            $this->step();
            $this->later();
        }
        $this->assertSame(0, (int) DB::table('whatsapp_messages')->value('attempts'));
        $this->offline = false;
        $this->assertTrue($this->step());
        $this->assertCount(1, $this->sent);
    }

    private function addPhotos(Offer $offer): void
    {
        Storage::fake('public');
        Storage::fake('cache');
        foreach (range(1, 2) as $i) {
            $offer->addMedia(UploadedFile::fake()->image("p{$i}.jpg", 400, 300))->toMediaCollection('photos');
        }
    }

    public function test_check_sends_without_remembering(): void
    {
        Cache::forget('whatsapp:pace');
        $this->artisan('whatsapp:test', ['numbers' => [(string) $this->offer->number]])->assertSuccessful();
        $this->assertCount(1, $this->sent);
        $this->assertStringStartsWith('test:', $this->sent[0]['key']);
        $this->assertFalse(DB::table('whatsapp_messages')->exists());
    }

    /**
     * Менеджер; jid — WhatsApp подключён полчаса назад. Волны тест ставит сам (`wave`): новый менеджер раскладывает себе
     * открытое всем (`SyncViewers`), а здесь видно ровно то, что задано.
     */
    private function manager(string $phone, ?string $jid = null): User
    {
        $user = User::create(['name' => 'Менеджер '.$phone, 'phone' => $phone, 'roles' => [Role::Manager], 'approved_at' => now()]);
        DB::table('offer_viewers')->where('user_id', $user->id)->delete();
        if ($jid) {
            $user->forceFill(['whatsapp_jid' => $jid, 'whatsapp_phone' => $phone, 'whatsapp_linked_at' => now()->subMinutes(30)])->save();
        }

        return $user;
    }

    /** Его волна: открыто ему с `opens`, анонс — с `notified` (null — ещё не наступила). */
    private function wave(Offer $offer, User $user, $opens, $notified = null): void
    {
        $offer->viewers()->updateOrCreate(['user_id' => $user->id], ['opens_at' => $opens, 'notified_at' => $notified ?? ($opens <= now() ? $opens : null)]);
    }

    public function test_waves_go_to_group_after_the_last_one(): void
    {
        // Рома сразу, Денис через два часа: пока волна Дениса не наступила — в группу нельзя, Роме — лично.
        $roma = $this->manager('79000000601', '79000000601@s.whatsapp.net');
        $denis = $this->manager('79000000602');
        $this->wave($this->offer, $roma, now()->subMinutes(5));
        $this->wave($this->offer, $denis, now()->addHours(2));
        $this->assertTrue($this->step());
        $this->assertSame('personal:'.$this->offer->id.':'.$roma->id, $this->sent[0]['key']);
        $this->assertSame('79000000601@s.whatsapp.net', $this->sent[0]['to']);
        $this->assertStringNotContainsString('только', $this->sent[0]['caption']);
        $this->later();
        $this->assertFalse($this->step());

        $this->travel(2)->hours();
        $this->assertTrue($this->step());
        $this->assertSame('offer:'.$this->offer->id, $this->sent[1]['key']);
        $this->assertSame('', $this->sent[1]['to']);
    }

    public function test_hidden_from_one_goes_personal_never_to_group(): void
    {
        // «Не показывать» Денису: группе нельзя никогда, остальным с WhatsApp — лично; без WhatsApp — никак.
        $roma = $this->manager('79000000601', '79000000601@s.whatsapp.net');
        $lena = $this->manager('79000000603');
        $this->manager('79000000602');
        $this->wave($this->offer, $roma, now()->subMinutes(5));
        $this->wave($this->offer, $lena, now()->subMinutes(5));
        foreach (range(1, 3) as $i) {
            $this->step();
            $this->later();
        }
        $this->assertSame(['personal:'.$this->offer->id.':'.$roma->id], array_column($this->sent, 'key'));
    }

    public function test_personal_after_link_without_bid_and_without_pdf_when_locked(): void
    {
        $roma = $this->manager('79000000601', '79000000601@s.whatsapp.net');
        $this->manager('79000000602');
        // Открылось ему до привязки — не шлём: старое при подключении не сыплется.
        $this->wave($this->offer, $roma, now()->subHour());
        $this->assertFalse($this->step());
        // Уже подтвердил — не нужно.
        $second = $this->openOffer();
        $this->wave($second, $roma, now());
        DB::table('bids')->insert(['offer_id' => $second->id, 'user_id' => $roma->id, 'amount' => 1000000, 'state' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse($this->step());
        // Шеринг закрыт — лично, но текстом, без PDF.
        $third = $this->openOffer();
        $third->forceFill(['share_locked' => true])->save();
        $this->addPhotos($third);
        $this->wave($third, $roma, now());
        $this->assertTrue($this->step());
        $this->assertSame('personal:'.$third->id.':'.$roma->id, $this->sent[0]['key']);
        $this->assertSame([], $this->sent[0]['files']);
    }

    public function test_estimate_goes_personal_as_text(): void
    {
        $roma = $this->manager('79000000601', '79000000601@s.whatsapp.net');
        $draft = Offer::create(['vendor_id' => $this->offer->vendor_id, 'floor_price' => 500000]);
        $this->offer->forceFill(['state' => OfferState::Draft])->save();
        $ask = fn (array $extra = []) => Estimate::create(['offer_id' => $draft->id, 'user_id' => $roma->id, 'state' => EstimateState::Asked,
            'due_at' => now()->addDays(2), 'exclusive_days' => 5] + $extra);
        $estimate = $ask();
        $this->assertTrue($this->step());
        $this->assertSame('estimate:'.$estimate->id, $this->sent[0]['key']);
        $this->assertSame('79000000601@s.whatsapp.net', $this->sent[0]['to']);
        $this->assertSame([], $this->sent[0]['files']);
        $this->assertStringStartsWith('*Нужна оценка*', $this->sent[0]['caption']);
        $this->assertStringContainsString('После оценки — 5 дней только вам', $this->sent[0]['caption']);
        $this->assertMatchesRegularExpression('~/offers/\d+$~', $this->sent[0]['caption']);
        // Оценил или закрыли — не шлём; второй раз ту же — тоже.
        $this->later();
        $this->assertFalse($this->step());
        $estimate->forceFill(['state' => EstimateState::Closed])->save();
        $ask(['created_at' => now()->subHours(2)])->forceFill(['state' => EstimateState::Given])->save();
        $this->later();
        $this->assertFalse($this->step());
        $this->assertCount(1, $this->sent);
    }

    public function test_link_by_code_from_any_number(): void
    {
        $andrey = $this->manager('79251117111');
        $inbox = app(Inbox::class);
        // Пишет со своего WhatsApp — номер другой, чем в xcar: привязывается тот, откуда пришло.
        $inbox->handle(['id' => 1, 'jid' => '74951117111@s.whatsapp.net', 'phone' => '74951117111', 'text' => 'Подключить XCar: '.LinkCode::for($andrey), 'at' => 100]);
        $andrey->refresh();
        $this->assertSame('74951117111@s.whatsapp.net', $andrey->whatsapp_jid);
        $this->assertSame('74951117111', $andrey->whatsapp_phone);
        $this->assertSame('74951117111@s.whatsapp.net', $this->sent[0]['to']);
        $this->assertStringStartsWith('Готово, Менеджер', $this->sent[0]['caption']);

        // Вчерашний код — тоже; тот же WhatsApp к другому аккаунту — у прежнего снимается.
        $other = $this->manager('79000000604');
        $inbox->handle(['id' => 2, 'jid' => '74951117111@s.whatsapp.net', 'phone' => '74951117111', 'text' => 'xcar: '.strtolower(LinkCode::for($other, now()->subDay())), 'at' => 200]);
        $this->assertSame('74951117111@s.whatsapp.net', $other->fresh()->whatsapp_jid);
        $this->assertNull($andrey->fresh()->whatsapp_jid);

        // Позавчерашний или чужой код — не привязывает, просит нажать ещё раз; без кода — молчим.
        $inbox->handle(['id' => 3, 'jid' => '79990000000@s.whatsapp.net', 'phone' => '79990000000', 'text' => 'Подключить XCar: '.LinkCode::for($andrey, now()->subDays(2)), 'at' => 300]);
        $this->assertStringContainsString('устарела', $this->sent[2]['caption']);
        $this->assertNull(User::where('whatsapp_jid', '79990000000@s.whatsapp.net')->first());
        $inbox->handle(['id' => 4, 'jid' => '79990000000@s.whatsapp.net', 'phone' => '79990000000', 'text' => 'Привет', 'at' => 400]);
        $this->assertCount(3, $this->sent);
    }
}
