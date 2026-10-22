<?php

namespace Tests\Feature;

use App\Notifications\NoBidsNotice;
use App\Offers\Actions\NoBidsAlarm;
use App\Offers\Actions\ReturnClosedToRated;
use App\Offers\Actions\SyncViewers;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Ни одно предложение без подтверждений (22.10.2026, владелец): за 3 часа — «Ни одного подтверждения» тем, кто открывал;
 * «Сгорает!» на плитке; приём кончился без подтверждений — назад в «Оцененные» с «приём закрыт». Ошибка тут — деньги.
 */
class NoBidsTest extends TestCase
{
    use RefreshDatabase;

    private function open(string $closes): Offer
    {
        $offer = Offer::create(['floor_price' => 500000, 'asking_price' => 700000]);
        $offer->forceFill(['state' => OfferState::Open, 'published_at' => now()->subDay(), 'bids_close_at' => now()->modify($closes)])->save();
        app(SyncViewers::class)($offer->fresh());

        return $offer->fresh();
    }

    public function test_alarm_burning_and_return_to_rated(): void
    {
        Queue::fake();
        Notification::fake();
        $reader = User::create(['name' => 'Читал', 'phone' => '79000000021', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $other = User::create(['name' => 'Не открывал', 'phone' => '79000000022', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $offer = $this->open('+2 hours');
        DB::table('offer_reads')->insert(['user_id' => $reader->id, 'offer_id' => $offer->id, 'read_at' => now()]);

        $this->assertTrue($offer->burningUnbid());
        $this->assertSame(1, app(NoBidsAlarm::class)());
        Notification::assertSentTo($reader, NoBidsNotice::class);
        Notification::assertNotSentTo($other, NoBidsNotice::class);
        $this->assertSame(0, app(NoBidsAlarm::class)(), 'один раз на срок');
        // «Уведомить менеджеров» из CRM — всем, кому открыта.
        $this->assertSame(2, app(NoBidsAlarm::class)->notify($offer->fresh()));

        // Подтверждение есть — не горит.
        DB::table('bids')->insert(['offer_id' => $offer->id, 'user_id' => $other->id, 'amount' => 650000, 'state' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse($offer->fresh()->burningUnbid());

        // Приём кончился без подтверждений — в «Оцененные» с меткой.
        $closed = $this->open('-5 minutes');
        $this->assertSame(1, app(ReturnClosedToRated::class)());
        $closed = $closed->fresh();
        $this->assertSame(OfferState::Draft, $closed->state);
        $this->assertNotNull($closed->intake_closed_at);
    }
}
