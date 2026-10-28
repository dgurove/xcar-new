<?php

namespace Tests\Feature;

use App\Notifications\ConsentGivenNotice;
use App\Notifications\YourTurnNotice;
use App\Offers\Actions\AcceptBid;
use App\Offers\Actions\PlaceBid;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Position;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * «За менеджера» (28.10.2026, владелец: «он мне уже устно подтвердил»): админ жмёт ответ менеджера в шаге — просьба
 * закрыта, в «Истории» «за менеджера»; «Уже согласился» у «Спросить менеджера» — сразу «Подтвердили покупку поставщику», и
 * менеджеру не уходит «Беру в работу?».
 */
class ActForManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private function deal(): array
    {
        config(['xcar.mercure.hub' => '']);
        $this->admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->manager = User::create(['name' => 'Иван Менеджер', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Альфа тест']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Alfa);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'floor_price' => 900000, 'asking_price' => 1000000, 'bids_close_at' => now()->addDay()]);
        $offer->forceFill(['state' => OfferState::Open])->save();
        $offer->viewers()->create(['user_id' => $this->manager->id, 'opens_at' => now()]);
        app(EnterStage::class)($offer, Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', 'Приём подтверждений')->firstOrFail());
        $deal = app(AcceptBid::class)(app(PlaceBid::class)($offer, $this->manager, 1000000), $this->admin, scheme: DealScheme::Prime);
        $position = fn () => Position::where('offer_id', $offer->id)->where('track', Track::Sale)->with('stage.exits.to')->firstOrFail();

        return [$offer->fresh(), $deal, $position];
    }

    public function test_take_for_manager_on_consent(): void
    {
        [$offer, $deal, $position] = $this->deal();
        $agreed = $position()->stage->exits->firstWhere('label', 'Поставщик согласовал');
        $this->actingAs($this->admin)->post("http://crm.xcar.localhost/offers/{$offer->number}/exit/{$agreed->id}")->assertRedirect();
        $this->assertSame('Согласие менеджера', $position()->stage->name);

        Notification::fake();
        $take = $position()->stage->exits->firstWhere('label', 'Беру в работу');
        $this->actingAs($this->admin)->post("http://crm.xcar.localhost/offers/{$offer->number}/exit/{$take->id}", ['for' => 'manager'])->assertRedirect();
        $this->assertSame('Подтвердили покупку поставщику', $position()->stage->name);
        $this->assertNull($deal->fresh()->openRequirement);
        $r = \App\Workflow\Requirement::where('deal_id', $deal->id)->latest('id')->first();
        $this->assertSame('staff', $r->answer['closed_by']);
        $this->assertSame($this->admin->id, $r->answer['by']);
        $event = $offer->events()->where('type', OfferEventType::StageEntered)->latest('id')->first();
        $this->assertSame('manager', $event->payload['for']);
        $this->assertStringContainsString('за менеджера', $event->text());
        Notification::assertNotSentTo($this->admin, ConsentGivenNotice::class);

        // Без права — нельзя и чужому исходу: исход сотрудника с for=manager отбит.
        $this->actingAs($this->manager)->post("http://crm.xcar.localhost/offers/{$offer->number}/exit/{$take->id}", ['for' => 'manager'])->assertStatus(403);
    }

    public function test_already_agreed_skips_the_question(): void
    {
        [$offer, $deal, $position] = $this->deal();
        Notification::fake();
        $agreed = $position()->stage->exits->firstWhere('label', 'Поставщик согласовал');
        $this->actingAs($this->admin)->post("http://crm.xcar.localhost/offers/{$offer->number}/exit/{$agreed->id}", ['then_take' => 1])->assertRedirect();
        $this->assertSame('Подтвердили покупку поставщику', $position()->stage->name);
        $this->assertTrue($deal->fresh()->agreed());
        // «Беру в работу?» ставилось в очередь на миг — к отправке просьба уже закрыта.
        Notification::assertSentTo($this->manager, YourTurnNotice::class, fn (YourTurnNotice $n) => ! $n->shouldSend($this->manager, 'database'));
    }
}
