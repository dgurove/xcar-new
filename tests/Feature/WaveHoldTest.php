<?php

namespace Tests\Feature;

use App\Offers\Actions\SyncViewers;
use App\Offers\Bid;
use App\Offers\BidState;
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
 * «Этим сразу, остальным через N дней»: подтвердил кто-то из первых — остальным не открывается, отозвал — открывается по
 * прежнему расписанию (07.11.2026, владелец).
 */
class WaveHoldTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_confirmation_holds_later_waves(): void
    {
        $roma = $this->manager('79000000901');
        $other = $this->manager('79000000902');
        $offer = Offer::create(['vendor_id' => Vendor::create(['name' => 'Совкомбанк тест'])->id, 'floor_price' => 900000, 'asking_price' => 1000000,
            'bids_close_at' => now()->addDays(10), 'audience_rules' => [['type' => 'user', 'id' => $roma->id, 'delay' => 0], ['type' => 'rest', 'id' => null, 'delay' => 7200]]]);
        $offer->forceFill(['state' => OfferState::Open, 'published_at' => now(), 'number' => OfferNumber::next()])->save();
        app(SyncViewers::class)($offer->fresh());
        $this->assertTrue($offer->viewers()->where('user_id', $other->id)->exists());

        $bid = Bid::create(['offer_id' => $offer->id, 'user_id' => $roma->id, 'amount' => 1000000, 'state' => BidState::Active]);
        $this->assertFalse($offer->viewers()->where('user_id', $other->id)->exists());
        $this->travel(6)->days();
        $this->assertFalse($offer->fresh()->isVisibleTo($other));
        $this->assertTrue($offer->events()->where('payload->text', 'Остальным не открывается: есть подтверждение')->exists());

        // Отозвал — пять дней прошли: остальным сразу.
        $bid->update(['state' => BidState::Withdrawn]);
        $this->assertTrue($offer->fresh()->isVisibleTo($other));
    }

    private function manager(string $phone): User
    {
        $user = User::create(['name' => 'Менеджер '.$phone, 'phone' => $phone, 'roles' => [Role::Manager], 'approved_at' => now()]);
        DB::table('offer_viewers')->where('user_id', $user->id)->delete();

        return $user;
    }
}
