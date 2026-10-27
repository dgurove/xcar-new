<?php

namespace Tests\Feature;

use App\Mail\Message;
use App\Notifications\ConsentGivenNotice;
use App\Notifications\InsurerReplyNotice;
use App\Notifications\YourTurnNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\DealScheme;
use App\Offers\Listeners\AdvanceOnReply;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actions\TickStages;
use App\Workflow\Actor;
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Альфа (27.10.2026, владелец): письмо «интерес» → ответ Альфы (в любой ящик) — ход наш → спрашиваем менеджера, срок до 17:00
 * следующего дня, кнопки в Telegram → «Беру» — сотрудникам ответить «актуально»; не ответил — горит у нас. До согласия
 * менеджер о письмах поставщика не знает ничего. Ошибка здесь молчалива: сделка неделями висит «ждём поставщика».
 */
class AlfaConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_reply_consent_by_telegram_and_staff_told_to_answer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 12:00', 'Europe/Moscow'));
        config(['xcar.telegram.token' => 'test-token', 'xcar.mercure.hub' => '']);
        $sent = [];
        $n = 10;
        Http::fake(['api.telegram.org/*' => function ($r) use (&$sent, &$n) {
            $sent[] = urldecode($r->body());

            return Http::response(['ok' => true, 'result' => ['message_id' => ++$n]]);
        }]);
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Владислав Митрофанов', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $manager->forceFill(['telegram_chat_id' => 777, 'telegram_linked_at' => now()])->save();
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1000000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $manager, 1000000), $admin, scheme: DealScheme::Prime);
        $position = fn () => Position::where('offer_id', $offer->id)->where('track', Track::Sale)->with('stage.exits.to')->firstOrFail();
        $this->assertSame('Уведомили поставщика о покупке', $position()->stage->name);

        // Альфа ответила на offer@ — не на deal@: ход наш, «поставщик ответил»; менеджеру ничего.
        Notification::fake();
        AdvanceOnReply::mark($deal->fresh(), (new Message)->forceFill(['date_at' => now()]));
        $this->assertSame('replied', $position()->letterStatus());
        $this->assertTrue($position()->needsUs());
        $this->assertTrue(Position::whereKey($position()->id)->needsUs()->exists());
        Notification::assertNotSentTo($manager, InsurerReplyNotice::class);

        // «Поставщик согласовал» — согласие менеджера до 17:00 завтра.
        app(TakeExit::class)($offer->fresh(), $position()->stage->exits->firstWhere('label', 'Поставщик согласовал'), Actor::Staff, $admin);
        $this->assertSame('Согласие менеджера', $position()->stage->name);
        $this->assertSame('2026-10-28 17:00', $position()->deadline_at->setTimezone('Europe/Moscow')->format('Y-m-d H:i'));
        $this->assertFalse($deal->fresh()->agreed());
        Notification::assertSentTo($manager, YourTurnNotice::class, fn (YourTurnNotice $n) => $n->important()
            && collect($n->toTelegram()['keyboard'][0] ?? [])->pluck('text')->all() === ['Беру в работу', 'Передумал']);

        // Срок вышел без ответа — горит у нас, кнопки менеджера живы.
        Carbon::setTestNow(Carbon::parse('2026-10-28 17:05', 'Europe/Moscow'));
        app(TickStages::class)();
        $this->assertTrue($position()->consentLate());
        $this->assertTrue(Position::whereKey($position()->id)->needsUs()->exists());

        // «Беру в работу» из Telegram — с переспросом; чужой мимо.
        $requirement = $deal->fresh()->openRequirement;
        $this->assertNotNull($requirement);
        $press = fn (int $from, string $action) => app(\App\Telegram\UpdateHandler::class)->handle(['update_id' => random_int(1, 9999), 'callback_query' => [
            'id' => 'q', 'from' => ['id' => $from], 'data' => "turn:{$requirement->id}:{$action}", 'message' => ['message_id' => 1, 'chat' => ['id' => $from]]]]);
        $press(888, 'suretake');
        $this->assertSame('Согласие менеджера', $position()->stage->name);
        $press(777, 'take');
        $this->assertSame('Согласие менеджера', $position()->stage->name);
        $press(777, 'suretake');
        $this->assertSame('Подтвердили покупку поставщику', $position()->stage->name);
        // Шаг с письмом поставщику: ход наш, пока письмо не ушло; сотрудникам — ответить «актуально».
        $this->assertSame('unsent', $position()->letterStatus());
        Notification::assertSentTo($admin, ConsentGivenNotice::class);
        $this->assertTrue($deal->fresh()->agreed());
    }
}
