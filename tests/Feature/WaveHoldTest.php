<?php

namespace Tests\Feature;

use App\Offers\Actions\ReturnClosedToRated;
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

    /** Примеры владельца: трое сразу, остальные через 5 дней. */
    public function test_others_see_it_only_when_every_live_confirmation_is_declined(): void
    {
        [$a, $b, $c] = [$this->manager('79000000911'), $this->manager('79000000912'), $this->manager('79000000913')];
        $rest = $this->manager('79000000914');
        $offer = $this->offer([$a, $b, $c]);
        $bidA = Bid::create(['offer_id' => $offer->id, 'user_id' => $a->id, 'amount' => 1000000, 'state' => BidState::Active]);
        $bidB = Bid::create(['offer_id' => $offer->id, 'user_id' => $b->id, 'amount' => 1100000, 'state' => BidState::Active]);
        $this->travel(6)->days();
        $this->assertFalse($offer->fresh()->isVisibleTo($rest));

        // Одному отказали, второе висит — не показываем.
        $bidA->update(['state' => BidState::Declined]);
        $this->assertFalse($offer->fresh()->isVisibleTo($rest));

        // Отказали обоим, третий не подтверждал — показываем.
        $bidB->update(['state' => BidState::Declined]);
        $this->assertTrue($offer->fresh()->isVisibleTo($rest));
    }

    /** Отказали после конца приёма — остальные не видят, машина уходит в «Без подтверждений». */
    public function test_declined_after_intake_end_opens_to_nobody(): void
    {
        $a = $this->manager('79000000921');
        $rest = $this->manager('79000000922');
        $offer = $this->offer([$a], closeDays: 2);
        $bid = Bid::create(['offer_id' => $offer->id, 'user_id' => $a->id, 'amount' => 1000000, 'state' => BidState::Active]);
        $this->travel(6)->days();

        $bid->update(['state' => BidState::Declined]);
        $this->assertFalse($offer->viewers()->where('user_id', $rest->id)->exists());
        $this->assertSame(1, app(ReturnClosedToRated::class)());
        $this->assertSame(OfferState::Draft, $offer->fresh()->state);
    }

    /** @param  list<User>  $first  кому сразу; остальным — через 5 дней */
    private function offer(array $first, int $closeDays = 10): Offer
    {
        $rules = [...array_map(fn (User $u) => ['type' => 'user', 'id' => $u->id, 'delay' => 0], $first), ['type' => 'rest', 'id' => null, 'delay' => 7200]];
        $offer = Offer::create(['vendor_id' => Vendor::firstOrCreate(['name' => 'Совкомбанк тест'])->id, 'floor_price' => 900000, 'asking_price' => 1000000,
            'bids_close_at' => now()->addDays($closeDays), 'audience_rules' => $rules]);
        $offer->forceFill(['state' => OfferState::Open, 'published_at' => now(), 'number' => OfferNumber::next()])->save();
        app(SyncViewers::class)($offer->fresh());

        return $offer->fresh();
    }

    private function manager(string $phone): User
    {
        $user = User::create(['name' => 'Менеджер '.$phone, 'phone' => $phone, 'roles' => [Role::Manager], 'approved_at' => now()]);
        DB::table('offer_viewers')->where('user_id', $user->id)->delete();

        return $user;
    }
}
