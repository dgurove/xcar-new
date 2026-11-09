<?php

namespace Tests\Feature;

use App\Cars\Actions\FillCar;
use App\Cars\Brand;
use App\Cars\Category;
use App\Offers\Actions\AskEstimates;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\DeclineEstimate;
use App\Offers\Actions\FirstLook;
use App\Offers\Actions\GiveEstimate;
use App\Offers\Actions\PlaceBid;
use App\Offers\Actions\ReturnClosedToRated;
use App\Offers\Actions\UpdateOffer;
use App\Offers\AudienceRules;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Estimate;
use App\Offers\MigtorgFields;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Оценка с эксклюзивом (02.11.2026, владелец и Андрей): админ отдаёт одному, его оценка — продажа только ему на N дней, не
 * подтвердил — всем; сама система никого не просит. И тип ТС с Мигторга: «Граз» с кузовом «Прицеп» стоял легковым.
 */
class HeavyEstimateTest extends TestCase
{
    use RefreshDatabase;

    private User $roma;

    private User $other;

    private User $admin;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['xcar.mercure.hub' => '']);
        Storage::fake('public');
        $this->roma = User::create(['name' => 'Роман Корабельников', 'phone' => '79000000050', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->other = User::create(['name' => 'Иван', 'phone' => '79000000051', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $this->admin = User::create(['name' => 'Андрей', 'phone' => '79000000052', 'roles' => [Role::Admin], 'approved_at' => now()]);
        config(['xcar.heavy_estimator' => $this->roma->id]);
        $this->vendor = Vendor::create(['name' => 'Альфа тест']);
    }

    private function draft(string $brand, string $ref): Offer
    {
        $b = Brand::create(['slug' => mb_strtolower($brand), 'name' => $brand]);
        $offer = Offer::create(['vendor_id' => $this->vendor->id, 'claim_ref' => $ref, 'brand_id' => $b->id, 'floor_price' => 900000]);
        $offer->addMedia(UploadedFile::fake()->image('a.jpg', 800, 600))->toMediaCollection('photos');

        return $offer->fresh();
    }

    public function test_heavy_is_not_asked_by_itself_and_exclusive_opens_to_all(): void
    {
        $truck = $this->draft('КАМАЗ', '5691/046/00901/26');
        $pickup = $this->draft('Changan', '5691/046/00904/26');
        $pickup->update(['body' => 'pickup']);
        $this->assertTrue($truck->isHeavy());
        $this->assertFalse($pickup->fresh()->isHeavy());
        $this->assertSame(Category::Light, $pickup->fresh()->category());

        // Сама система никого не просит (02.11.2026, Андрей) — только админ.
        $this->artisan('offers:tick');
        $this->assertFalse(Estimate::where('offer_id', $truck->id)->exists());

        $this->actingAs($this->admin)->post('http://crm.xcar.localhost/offers/'.$truck->number.'/estimates', ['managers' => [$this->roma->id], 'declared' => [$this->roma->id => 1], 'days' => 3, 'exclusive' => 5])->assertRedirect()->assertSessionHasNoErrors();
        $estimate = Estimate::where('offer_id', $truck->id)->sole();
        $this->assertSame(5, $estimate->exclusive_days);
        $this->assertEqualsWithDelta(now()->addDays(3)->timestamp, $estimate->due_at->timestamp, 5);

        // Оценка сама в продажу не выводит (07.11.2026, владелец): ждёт админа, «Взять» — продажа только ему.
        app(GiveEstimate::class)($estimate, 1200000, $this->roma);
        $this->assertSame(OfferState::Draft, $truck->fresh()->state);
        $this->assertNull($truck->fresh()->asking_price);
        $this->actingAs($this->admin)->post('http://crm.xcar.localhost/offers/'.$truck->number.'/estimates/'.$estimate->id.'/take', ['price_ok' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $truck = $truck->fresh();
        $this->assertSame(OfferState::Open, $truck->state);
        $this->assertSame(1200000, $truck->asking_price);
        $this->assertTrue($truck->first_look_until->equalTo($truck->bids_close_at));
        $this->assertSame(5, (int) now()->startOfDay()->diffInDays($truck->first_look_until->copy()->startOfDay()));
        $this->assertSame([$this->roma->id], $truck->viewers()->pluck('user_id')->all());

        // Эксклюзив прошёл без подтверждения: «Не продано» не забирает, открывается всем с новым сроком.
        $this->travelTo($truck->first_look_until->copy()->addMinute());
        $this->assertSame(0, app(ReturnClosedToRated::class)());
        $this->assertSame(1, app(FirstLook::class)->due());
        $truck = $truck->fresh();
        $this->assertSame(OfferState::Open, $truck->state);
        $this->assertNull($truck->first_look_until);
        $this->assertTrue($truck->bids_close_at->isFuture());
        $this->assertSame(AudienceRules::everyone(), AudienceRules::of($truck));
        $this->assertContains($this->other->id, $truck->viewers()->pluck('user_id')->all());
    }

    public function test_exclusive_only_for_one_manager(): void
    {
        $two = $this->draft('КАМАЗ', '5691/046/00906/26');
        app(AskEstimates::class)($two, [$this->roma, $this->other], $this->admin, days: 2, exclusive: 5);
        $this->assertSame([null, null], Estimate::where('offer_id', $two->id)->pluck('exclusive_days')->all());

        // Отдали ещё одному — эксклюзив прежней просьбы гаснет.
        $one = $this->draft('Sitrak', '5691/046/00907/26');
        app(AskEstimates::class)($one, [$this->roma], $this->admin, exclusive: 5);
        app(AskEstimates::class)($one->fresh(), [$this->other], $this->admin);
        $this->assertSame([null, null], Estimate::where('offer_id', $one->id)->orderBy('id')->pluck('exclusive_days')->all());

        // Уже думает другой — эксклюзива новому нет.
        $busy = $this->draft('MAN', '5691/046/00911/26');
        app(AskEstimates::class)($busy, [$this->other], $this->admin);
        app(AskEstimates::class)($busy->fresh(), [$this->roma], $this->admin, exclusive: 5);
        $this->assertSame([null, null], Estimate::where('offer_id', $busy->id)->orderBy('id')->pluck('exclusive_days')->all());
    }

    public function test_exclusive_follows_intake(): void
    {
        // «+1 ч» к приёму двигает и эксклюзив.
        $truck = $this->draft('КАМАЗ', '5691/046/00908/26');
        app(AskEstimates::class)($truck, [$this->roma], $this->admin, exclusive: 3);
        $this->giveAndTake($truck);
        $truck = $truck->fresh();
        $this->assertSame($this->roma->id, $truck->first_look_user_id);
        app(UpdateOffer::class)($truck, ['bids_close_at' => $truck->bids_close_at->copy()->addHour()], $this->admin);
        $this->assertTrue($truck->fresh()->first_look_until->equalTo($truck->fresh()->bids_close_at));
    }

    public function test_exclusive_ends_when_offer_leaves_sale(): void
    {
        $truck = $this->draft('КАМАЗ', '5691/046/00912/26');
        app(AskEstimates::class)($truck, [$this->roma], $this->admin, exclusive: 5);
        $this->giveAndTake($truck);
        $this->assertNotNull($truck->fresh()->first_look_until);

        app(ChangeOfferState::class)($truck->fresh(), OfferState::Draft, $this->admin);
        $truck = $truck->fresh();
        $this->assertNull($truck->first_look_until);
        $this->assertNull($truck->first_look_user_id);
        $this->assertNull($truck->audience_rules);
    }

    public function test_ask_again_keeps_days_and_exclusive(): void
    {
        $truck = $this->draft('КАМАЗ', '5691/046/00910/26');
        $estimate = app(AskEstimates::class)($truck, [$this->roma], $this->admin, days: 5, exclusive: 7)->sole();
        app(DeclineEstimate::class)($estimate, 900000, null, $this->roma);
        $this->actingAs($this->admin)->post('http://crm.xcar.localhost/offers/'.$truck->number.'/estimates/'.$estimate->id.'/again')->assertRedirect();
        $again = Estimate::where('offer_id', $truck->id)->where('state', 'asked')->sole();
        $this->assertSame(7, $again->exclusive_days);
        $this->assertEqualsWithDelta(now()->addDays(5)->timestamp, $again->due_at->timestamp, 5);
    }

    public function test_confirmed_exclusive_stays_with_estimator(): void
    {
        $truck = $this->draft('КАМАЗ', '5691/046/00903/26');
        app(AskEstimates::class)($truck, [$this->roma], $this->admin, exclusive: 5);
        $this->giveAndTake($truck);
        app(PlaceBid::class)($truck->fresh(), $this->roma, 1200000);

        // Срок эксклюзива прошёл — остальным не открывается, пока у него живое подтверждение (07.11.2026, владелец).
        $this->travelTo($truck->fresh()->first_look_until->copy()->addMinute());
        $this->assertSame(0, app(FirstLook::class)->due());
        $truck = $truck->fresh();
        $this->assertNotNull($truck->first_look_until);
        $this->assertSame([$this->roma->id], $truck->viewers()->pluck('user_id')->all());
        $this->assertSame(0, app(ReturnClosedToRated::class)());

        // Отказали — всем, с новым сроком приёма.
        Bid::where('offer_id', $truck->id)->update(['state' => BidState::Declined]);
        $this->assertSame(1, app(FirstLook::class)->due());
        $truck = $truck->fresh();
        $this->assertNull($truck->first_look_until);
        $this->assertTrue($truck->bids_close_at->isFuture());
        $this->assertContains($this->other->id, $truck->viewers()->pluck('user_id')->all());
    }

    public function test_migtorg_trailer_beats_guessed_passenger(): void
    {
        $fields = MigtorgFields::of(['lot' => ['brand' => ['title' => 'Граз', 'car_type_id' => 4], 'model' => ['title' => 'Граз'], 'carcass' => ['title' => 'Прицеп']]]);
        $this->assertSame('trailer', $fields['category']['value']);
        $this->assertSame('long', MigtorgFields::of(['lot' => ['brand' => ['title' => 'Sitrak'], 'carcass' => ['title' => 'Седельный Тягач']]])['category']['value']);
        $this->assertSame('light', MigtorgFields::of(['lot' => ['brand' => ['title' => 'Луидор'], 'model' => ['title' => '3009Z6'], 'carcass' => ['title' => 'Грузовик']]])['category']['value']);

        // Черновик завели пустым, марка пришла позже — догадка «Легковой», её перебивает Мигторг.
        $offer = Offer::create(['vendor_id' => $this->vendor->id, 'claim_ref' => 'Z691/046/13687/26']);
        app(FillCar::class)($offer, $fields, null, FillCar::EMPTY);
        $this->assertSame(Category::Trailer, $offer->fresh()->category());

        // Тип, выбранный человеком, Мигторг не трогает.
        $chosen = Offer::create(['vendor_id' => $this->vendor->id, 'claim_ref' => 'Z691/046/13688/26', 'brand_id' => Brand::where('name', 'Граз')->value('id'), 'vehicle_category' => Category::Special]);
        app(FillCar::class)($chosen, $fields, null, FillCar::EMPTY);
        $this->assertSame(Category::Special, $chosen->fresh()->category());
    }

    /**
     * Daf XF 2611093833 (09.11.2026): Рома оценил 1 500 000, Андрей вписал свою 1 630 000 и выставил «Всем сразу» — эксклюзив
     * всё равно Роме; «Показ» и «Срочно» его не снимают, повторный выход — без него.
     */
    public function test_own_price_keeps_exclusive(): void
    {
        $truck = $this->draft('DAF', '0196/046/03097/26');
        app(AskEstimates::class)($truck, [$this->roma], $this->admin, exclusive: 5);
        app(GiveEstimate::class)(Estimate::where('offer_id', $truck->id)->sole(), 1500000, $this->roma);
        app(UpdateOffer::class)($truck->fresh(), ['asking_price' => 1630000], $this->admin);
        $this->assertSame('closed', Estimate::where('offer_id', $truck->id)->sole()->state->value);

        app(ChangeOfferState::class)($truck->fresh(), OfferState::Open, $this->admin);
        $truck = $truck->fresh();
        $opens = $truck->viewers()->pluck('opens_at', 'user_id');
        $this->assertTrue($opens[$this->roma->id]->lte(now()));
        $this->assertEqualsWithDelta($truck->published_at->copy()->addDays(5)->timestamp, $opens[$this->other->id]->timestamp, 60);
        $this->assertNotNull(Estimate::where('offer_id', $truck->id)->sole()->exclusive_until);

        // «Показ» на «Всем сразу» и «Срочно» — остальные всё равно через 5 дней.
        app(UpdateOffer::class)($truck, ['audience_rules' => AudienceRules::everyone()], $this->admin);
        $truck->forceFill(['urgent_at' => now()])->save();
        app(\App\Offers\Actions\SyncViewers::class)($truck->fresh());
        $this->assertTrue($truck->viewers()->where('user_id', $this->other->id)->value('opens_at') > now()->addDays(4));

        // Эксклюзив прошёл, машина снова в черновике и снова в продаже — волны свои.
        $this->travel(6)->days();
        app(ChangeOfferState::class)($truck->fresh(), OfferState::Draft, $this->admin);
        app(UpdateOffer::class)($truck->fresh(), ['audience_rules' => AudienceRules::everyone()], $this->admin);
        app(ChangeOfferState::class)($truck->fresh(), OfferState::Open, $this->admin);
        $this->assertTrue($truck->viewers()->where('user_id', $this->other->id)->value('opens_at') <= now());
    }

    /** Рома оценил, админ взял: с эксклюзивом — продажа только ему. */
    private function giveAndTake(Offer $truck): void
    {
        $estimate = app(GiveEstimate::class)(Estimate::where('offer_id', $truck->id)->sole(), 1200000, $this->roma);
        app(FirstLook::class)->open($estimate, $this->admin);
    }
}
