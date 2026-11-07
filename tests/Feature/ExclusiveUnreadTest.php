<?php

namespace Tests\Feature;

use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «Открыты только Вам» фильтр «Не просмотренные» не прячет (07.11.2026, владелец: Роме открыли две машины на 5 дней, он
 * их открыл, а потом «их не было видно» — по умолчанию включённый фильтр убрал просмотренные). Только вам — сейчас:
 * остальным волна ещё не наступила.
 */
class ExclusiveUnreadTest extends TestCase
{
    use RefreshDatabase;

    public function test_exclusive_stays_in_catalog_after_reading_while_common_one_hides(): void
    {
        $vendor = Vendor::create(['name' => 'Совкомбанк тест']);
        $roma = $this->manager('79000000801');
        $other = $this->manager('79000000802');
        $exclusive = $this->openOffer($vendor);
        $common = $this->openOffer($vendor);
        foreach ([$exclusive, $common] as $offer) {
            $offer->viewers()->updateOrCreate(['user_id' => $roma->id], ['opens_at' => now()->subDay(), 'notified_at' => now()->subDay()]);
            DB::table('offer_reads')->insert(['user_id' => $roma->id, 'offer_id' => $offer->id, 'read_at' => now()->subHours(20)]);
        }
        $exclusive->viewers()->updateOrCreate(['user_id' => $other->id], ['opens_at' => now()->addDays(4)]);
        $common->viewers()->updateOrCreate(['user_id' => $other->id], ['opens_at' => now()->subDay(), 'notified_at' => now()->subDay()]);

        $this->assertTrue(Offer::onlyFor($roma)->whereKey($exclusive->id)->exists());
        $this->assertFalse(Offer::onlyFor($roma)->whereKey($common->id)->exists());

        $page = $this->actingAs($roma)->get('http://xcar.localhost/offers', ['Accept' => 'text/html'])->assertOk();
        $page->assertSee('/offers/'.$exclusive->number, false);
        $page->assertDontSee('/offers/'.$common->number, false);
    }

    private function manager(string $phone): User
    {
        $user = User::create(['name' => 'Менеджер '.$phone, 'phone' => $phone, 'roles' => [Role::Manager], 'approved_at' => now()]);
        DB::table('offer_viewers')->where('user_id', $user->id)->delete();

        return $user;
    }

    private function openOffer(Vendor $vendor): Offer
    {
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1000000, 'bids_close_at' => now()->addDays(3)]);
        $offer->forceFill(['state' => OfferState::Open, 'published_at' => now()->subDay(), 'number' => OfferNumber::next()])->save();

        return $offer->fresh();
    }
}
