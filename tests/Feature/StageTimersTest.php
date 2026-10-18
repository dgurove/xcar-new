<?php

namespace Tests\Feature;

use App\Notifications\Notify;
use App\Notifications\StageDueNotice;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use App\Workflow\Actions\ApplyPreset;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\SaveStage;
use App\Workflow\Events\StageDue;
use App\Workflow\Outcome;
use App\Workflow\Preset;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Таймеры этапов (19.01.2027, начальник: «если сменим 3 дня на 5 — точно поменяется везде?»): правка срока доходит до сделок
 * на этапе, рабочие дни не считают выходных, «Продлить срок» одной сделке, сотрудникам «скоро» — только когда ход наш.
 */
class StageTimersTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_limit_reaches_deals_on_stage(): void
    {
        [$admin, $offer, $stage] = $this->onStage('Проверка оплаты');
        $this->travelTo(Carbon::parse('2026-10-14 12:00', 'Europe/Moscow'));
        $offer = $this->enter($offer, $stage);
        $entered = $offer->position(Track::Sale)->entered_at;
        $this->assertTrue($offer->position(Track::Sale)->deadline_at->equalTo($entered->copy()->addDay()));

        // 1 д → 3 д: срок сделки — от того же входа плюс 3 дня, «просрочено» снято.
        $this->save($stage, ['limit_value' => 3, 'limit_unit' => 'days'], $admin);
        $position = $offer->fresh()->position(Track::Sale);
        $this->assertTrue($position->deadline_at->equalTo($entered->copy()->addDays(3)));
        $this->assertNull($position->overdue_at);

        // 3 д → 2 раб. дн: среда 12:00 + 2 рабочих дня = пятница 12:00.
        $this->save($stage->fresh(), ['limit_value' => 2, 'limit_unit' => 'workdays'], $admin);
        $this->assertSame('2026-10-16 12:00', $offer->fresh()->position(Track::Sale)->deadline_at->timezone('Europe/Moscow')->format('Y-m-d H:i'));

        // Без галки «и N сделкам» — сделки живут по прежнему сроку.
        $this->save($stage->fresh(), ['limit_value' => 5, 'limit_unit' => 'days', 'retime' => false], $admin);
        $this->assertSame('2026-10-16 12:00', $offer->fresh()->position(Track::Sale)->deadline_at->timezone('Europe/Moscow')->format('Y-m-d H:i'));
    }

    /** Этап был без срока — срок получают и сделки на нём, от входа (18.10.2026: падало на сравнении с пустым сроком). */
    public function test_limit_on_stage_without_one_reaches_deals(): void
    {
        [$admin, $offer, $stage] = $this->onStage('Проверка оплаты');
        $stage->update(['limit_minutes' => null]);
        $this->travelTo(Carbon::parse('2026-10-14 12:00', 'Europe/Moscow'));
        $offer = $this->enter($offer, $stage->fresh());
        $this->assertNull($offer->position(Track::Sale)->deadline_at);

        app(\App\Workflow\Actions\SetStageLimit::class)($stage->fresh(), 2 * 24 * 60, false, true, $admin);
        $position = $offer->fresh()->position(Track::Sale);
        $this->assertTrue($position->deadline_at->equalTo($position->entered_at->copy()->addDays(2)));
    }

    public function test_workdays_skip_weekend(): void
    {
        $friday = Carbon::parse('2026-10-16 15:00');
        $this->assertSame('2026-10-19 15:00', Stage::addLimit($friday, 1440, true)->format('Y-m-d H:i'));
        $this->assertSame('2026-10-16 15:00', Stage::subLimit(Carbon::parse('2026-10-19 15:00'), 1440, true)->format('Y-m-d H:i'));
        $this->assertSame('1 д 12 ч', Stage::durationLabel(2160));
        $this->assertSame('3 раб. дн', Stage::durationLabel(4320, true));
    }

    public function test_extend_one_deal(): void
    {
        [$admin, $offer, $stage] = $this->onStage('Проверка оплаты');
        $offer = $this->enter($offer, $stage);
        $before = $offer->position(Track::Sale)->deadline_at;
        $this->actingAs($admin)->post('http://'.config('xcar.crm_host').'/offers/'.$offer->number.'/deadline', ['track' => 'sale', 'days' => 3])
            ->assertSessionHasNoErrors();
        $this->assertTrue($offer->fresh()->position(Track::Sale)->deadline_at->equalTo($before->copy()->addDays(3)));
    }

    public function test_staff_hear_only_own_turn_until_overdue(): void
    {
        Notification::fake();
        [$admin, $offer, $stage] = $this->onStage('Оплата по счёту ПРАЙМ');
        $offer = $this->enter($offer, $stage);
        $position = $offer->position(Track::Sale);
        $position->update(['waits_for' => WaitsFor::Manager]);
        app(Notify::class)->stageDue(new StageDue($offer, $position->fresh(), false));
        Notification::assertNotSentTo($admin, StageDueNotice::class);
        app(Notify::class)->stageDue(new StageDue($offer, $position->fresh(), true));
        Notification::assertSentTo($admin, StageDueNotice::class);
    }

    /** @return array{0: User, 1: Offer, 2: Stage} */
    private function onStage(string $name): array
    {
        $admin = User::create(['name' => 'Владелец', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        app(ApplyPreset::class)($vendor->workflowOrNew(Track::Sale), Preset::Sovcombank);
        $offer = Offer::create(['claim_ref' => '900001/2026', 'floor_price' => 500000, 'vendor_id' => $vendor->id]);
        $stage = Stage::where('workflow_id', $vendor->fresh()->workflow(Track::Sale)->id)->where('name', $name)->firstOrFail();

        return [$admin, $offer, $stage];
    }

    private function enter(Offer $offer, Stage $stage): Offer
    {
        app(EnterStage::class)($offer->fresh(), $stage);

        return $offer->fresh()->unsetRelation('positions');
    }

    private function save(Stage $stage, array $limit, User $by): void
    {
        $data = $limit + [
            'name' => $stage->name, 'waits_for' => $stage->waits_for->value, 'deadline_source' => 'own', 'offer_state' => $stage->offer_state?->value,
            'car_place' => null, 'asks' => $stage->asks->value, 'template_id' => $stage->template_id,
            'exits' => $stage->exits->map(fn (Outcome $e) => ['id' => $e->id, 'label' => $e->label, 'actor' => $e->actor->value, 'to_stage_id' => $e->to_stage_id])->all(),
        ];
        app(SaveStage::class)($stage->workflow, $stage->block, $data, $stage, $by);
    }
}
