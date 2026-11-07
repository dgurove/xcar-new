<?php

namespace Tests\Feature;

use App\Notifications\UnreadClosingNotice;
use App\Offers\Actions\RemindUnreadClosing;
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
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * «Ни одного подтверждения» — строкой напоминания за 3 часа, а не отдельным громким на каждую машину (07.11.2026, владелец:
 * «уведомления — только где нужен я»). Ошибка в запросе молчалива: менеджеры просто перестали бы получать напоминание.
 */
class ClosingReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_opened_offers_without_bids_join_the_three_hour_reminder(): void
    {
        Notification::fake();
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        $roma = $this->manager('79000000701');
        $denis = $this->manager('79000000702');
        $closeAt = now()->addHours(2)->startOfMinute();
        $empty = $this->openOffer($vendor, $closeAt);
        $bidden = $this->openOffer($vendor, $closeAt);
        foreach ([$empty, $bidden] as $offer) {
            foreach ([$roma, $denis] as $user) {
                $offer->viewers()->updateOrCreate(['user_id' => $user->id], ['opens_at' => now()->subDay(), 'notified_at' => now()->subDay()]);
                DB::table('offer_reads')->insert(['user_id' => $user->id, 'offer_id' => $offer->id, 'read_at' => now()->subHours(5)]);
            }
        }
        // У второй машины подтверждение есть (у Дениса) — о ней молчим обоим.
        Bid::create(['offer_id' => $bidden->id, 'user_id' => $denis->id, 'amount' => 1000000, 'state' => BidState::Active]);

        app(RemindUnreadClosing::class)();

        foreach ([$roma, $denis] as $user) {
            Notification::assertSentToTimes($user, UnreadClosingNotice::class, 1);
            Notification::assertSentTo($user, UnreadClosingNotice::class, fn (UnreadClosingNotice $n) => $n->offerNumber() === $empty->number
                && $n->text() === 'Ни одного подтверждения' && $n->important());
        }

        // Второй прогон того же срока — тишина: отметка на просмотре.
        Notification::fake();
        app(RemindUnreadClosing::class)();
        Notification::assertNothingSent();
    }

    private function manager(string $phone): User
    {
        $user = User::create(['name' => 'Менеджер '.$phone, 'phone' => $phone, 'roles' => [Role::Manager], 'approved_at' => now()]);
        DB::table('offer_viewers')->where('user_id', $user->id)->delete();

        return $user;
    }

    private function openOffer(Vendor $vendor, $closeAt): Offer
    {
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1000000, 'bids_close_at' => $closeAt]);
        $offer->forceFill(['state' => OfferState::Open, 'published_at' => now()->subDay(), 'number' => OfferNumber::next()])->save();

        return $offer->fresh();
    }
}
