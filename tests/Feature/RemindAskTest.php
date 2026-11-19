<?php

namespace Tests\Feature;

use App\Chats\Message;
use App\Notifications\YourTurnNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\RemindAsks;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * «Напомнить через…» (19.11.2026, владелец): менеджер откладывает «Берёте в работу?» — в срок вопрос приходит снова
 * уведомлением и сообщением в чат, один раз; ответил раньше — не приходит. Ошибка молчалива: «не напомнили» никто не заметит.
 */
class RemindAskTest extends TestCase
{
    use RefreshDatabase;

    public function test_remind_resends_once_and_not_after_answer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 12:00', 'Europe/Moscow'));
        config(['xcar.telegram.token' => '', 'xcar.mercure.hub' => '']);
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Сергей Николаев', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1000000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $manager, 1000000), $admin, scheme: DealScheme::Prime);
        $position = fn () => Position::where('offer_id', $offer->id)->where('track', Track::Sale)->with('stage.exits.to')->firstOrFail();
        app(TakeExit::class)($offer->fresh(), $position()->stage->exits->firstWhere('label', 'Поставщик согласовал'), Actor::Staff, $admin);
        $this->assertSame('Согласие менеджера', $position()->stage->name);
        $requirement = $deal->fresh()->openRequirement;
        $this->assertNotNull($requirement);
        $asked = Message::where('requirement_id', $requirement->id)->count();

        // Менеджер нажал «Напомнить через… → 1 час».
        $this->actingAs($manager)->post('/deals/'.$deal->id.'/remind', ['when' => '1'])->assertSessionHas('toast', 'Напомним в 13:00');
        $this->assertSame('2026-11-19 13:00', $requirement->fresh()->remind_at->format('Y-m-d H:i'));

        // Рано — ничего; в срок — уведомление и сообщение с вопросом, напоминание погасло; второй тик — тишина.
        Notification::fake();
        $this->assertSame(0, app(RemindAsks::class)());
        Carbon::setTestNow(now()->addHour());
        $this->assertSame(1, app(RemindAsks::class)());
        Notification::assertSentTo($manager, YourTurnNotice::class);
        $this->assertSame($asked + 1, Message::where('requirement_id', $requirement->id)->count());
        $this->assertNull($requirement->fresh()->remind_at);
        $this->assertSame(0, app(RemindAsks::class)());

        // Снова отложил, но ответил раньше срока — напоминания нет.
        RemindAsks::set($requirement->fresh(), now()->addHour(), $manager);
        $take = $position()->stage->exits->first(fn ($x) => $x->actor === Actor::Manager && $x->takes());
        $this->actingAs($manager)->post('/deals/'.$deal->id.'/reply', ['exit' => $take->id]);
        $this->assertNotNull($requirement->fresh()->done_at);
        Carbon::setTestNow(now()->addHours(2));
        $this->assertSame(0, app(RemindAsks::class)());
    }
}
