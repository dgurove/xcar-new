<?php

namespace Tests\Feature;

use App\Logistics\Actions\AssignExecutor;
use App\Logistics\Actions\Cancel;
use App\Logistics\Actions\LogistAssign;
use App\Logistics\Actions\OpenPickup;
use App\Logistics\Actions\StepBack;
use App\Logistics\Channel;
use App\Logistics\Reach;
use App\Logistics\Transport;
use App\Logistics\TransportEvent;
use App\Logistics\TransportState;
use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Вывоз 18.10.2026 (483511/2024: исполнителя меняли отменой — по машине копились задачи): исполнителя меняют в той же
 * задаче, логист сам не везёт, шаг за исполнителя видно в истории, шаги откатываются, «Вывезти» после отмены — та же задача.
 */
class TransportStepsTest extends TestCase
{
    use RefreshDatabase;

    private const LOG = 'http://logistics.xcar.localhost';

    private User $admin;

    private User $tanya;

    private User $ivan;

    private User $petr;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::create(['name' => 'Андрей Кузнецов', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $this->tanya = User::create(['name' => 'Татьяна Логинова', 'phone' => '79000000002', 'roles' => [Role::Logist], 'approved_at' => now()]);
        $this->ivan = User::create(['name' => 'Иван Петров', 'phone' => '79000000003', 'roles' => [Role::Contractor], 'logist_id' => $this->tanya->id, 'approved_at' => now()]);
        $this->petr = User::create(['name' => 'Пётр Сидоров', 'phone' => '79000000004', 'roles' => [Role::Contractor], 'logist_id' => $this->tanya->id, 'approved_at' => now()]);
        $vendor = Vendor::create(['name' => 'Совкомбанк тест', 'deal_format' => 'commission']);
        $this->offer = Offer::create(['vendor_id' => $vendor->id, 'claim_ref' => '483511/2024', 'floor_price' => 400000]);
    }

    private function viaTanya(): Transport
    {
        $t = app(OpenPickup::class)($this->offer->fresh(), $this->admin);
        app(AssignExecutor::class)($t, Channel::Logist, $this->tanya, $this->admin);

        return app(LogistAssign::class)($t->fresh(), ['executor_id' => $this->ivan->id, 'cost' => '65 000'], $this->tanya)->fresh();
    }

    public function test_executor_changes_in_same_task_and_logist_cannot_carry(): void
    {
        $t = $this->viaTanya();
        $this->assertSame($this->ivan->id, $t->executor_id);
        $this->assertSame($this->tanya->id, $t->logist_id);

        // Логист меняет своего исполнителя — та же задача, в истории «был Иван».
        $this->actingAs($this->tanya)->put(self::LOG.'/transports/'.$t->id.'/logist', ['executor_id' => $this->petr->id, 'cost' => '65 000'])->assertSessionHasNoErrors();
        $this->assertSame($this->petr->id, $t->fresh()->executor_id);
        $this->assertSame(1, Transport::where('car_id', $this->offer->car_id)->count());
        $changed = TransportEvent::where('transport_id', $t->id)->where('type', 'assigned')->latest('id')->first();
        $this->assertSame('Исполнитель Пётр С., был Иван П.', $changed->what());

        // Сама себя исполнителем — нет: логист распределяет.
        $this->actingAs($this->tanya)->put(self::LOG.'/transports/'.$t->id.'/logist', ['executor_id' => $this->tanya->id, 'cost' => '65 000'])->assertSessionHasErrors('executor_id');
    }

    public function test_step_for_executor_is_seen_and_undone(): void
    {
        $t = $this->viaTanya();

        // Татьяна отмечает созвон и «Забрал» за Ивана — в истории она и он.
        $this->actingAs($this->tanya)->post(self::LOG.'/transports/'.$t->id.'/call', ['pickup_at' => now()->addDay()->format('Y-m-d\TH:i'), 'pickup_place' => 'Казань'])->assertSessionHasNoErrors();
        $called = TransportEvent::where('transport_id', $t->id)->where('type', 'called')->first();
        $this->assertSame($this->tanya->id, $called->by_id);
        $this->assertSame($this->ivan->id, $called->for_id);
        $this->assertSame('Татьяна Л. за исполнителя Иван П.', $called->who());

        $this->actingAs($this->admin)->post(self::LOG.'/transports/'.$t->id.'/pickup', ['destination' => 'ours', 'keys' => 2])->assertSessionHasNoErrors();
        $this->assertSame(TransportState::Moving, $t->fresh()->state);
        $this->assertSame(CarPlace::Moving, $this->offer->fresh()->car_place);

        // «Забрал» назад: снова созвонились, машина у владельца.
        $this->actingAs($this->tanya)->post(self::LOG.'/transports/'.$t->id.'/back')->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame(TransportState::Agreed, $t->state);
        $this->assertNull($t->picked_at);
        $this->assertSame(CarPlace::Owner, $this->offer->fresh()->car_place);
        $this->assertSame('Отменили «Забрал»', TransportEvent::where('transport_id', $t->id)->latest('id')->first()->what());

        // Созвон назад, потом исполнитель — задача снова у логиста.
        $this->actingAs($this->tanya)->post(self::LOG.'/transports/'.$t->id.'/back')->assertSessionHasNoErrors();
        $this->assertSame(TransportState::Assigned, $t->fresh()->state);
        $this->actingAs($this->tanya)->post(self::LOG.'/transports/'.$t->id.'/back')->assertSessionHasNoErrors();
        $this->assertSame(TransportState::Logist, $t->fresh()->state);
        $this->assertNull($t->fresh()->executor_id);
    }

    public function test_delivered_is_undone_only_by_admin(): void
    {
        $t = $this->viaTanya();
        $this->actingAs($this->admin)->post(self::LOG.'/transports/'.$t->id.'/pickup', ['destination' => 'ours', 'keys' => 1])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(self::LOG.'/transports/'.$t->id.'/deliver')->assertSessionHasNoErrors();

        $this->actingAs($this->tanya)->post(self::LOG.'/transports/'.$t->id.'/back')->assertSessionHasErrors('transport');
        $this->actingAs($this->admin)->post(self::LOG.'/transports/'.$t->id.'/back')->assertSessionHasNoErrors();
        $this->assertSame(TransportState::Moving, $t->fresh()->state);
        $this->assertSame(CarPlace::Moving, $this->offer->fresh()->car_place);
    }

    public function test_pickup_after_cancel_reopens_same_task(): void
    {
        $t = $this->viaTanya();
        app(Cancel::class)($t, $this->admin, 'не тот исполнитель');
        $again = app(OpenPickup::class)($this->offer->fresh(), $this->admin);

        $this->assertSame($t->id, $again->id);
        $this->assertSame(TransportState::New, $again->state);
        $this->assertSame(1, Transport::where('car_id', $this->offer->car_id)->count());
        $this->assertSame(['opened', 'logist', 'assigned', 'cost', 'cancelled', 'reopened'], TransportEvent::where('transport_id', $t->id)->orderBy('id')->pluck('type')->all());
    }

    public function test_merge_cancelled_duplicates(): void
    {
        $t = $this->viaTanya();
        $old = Transport::create(['car_id' => $this->offer->car_id, 'origin' => 'sale', 'kind' => 'pickup', 'state' => 'cancelled', 'cancelled_at' => now()]);
        TransportEvent::record($old, 'cancelled', $this->admin);

        $this->artisan('logistics:merge-cancelled --apply')->assertSuccessful();

        $this->assertNull(Transport::find($old->id));
        $this->assertTrue(TransportEvent::where('transport_id', $t->id)->where('type', 'cancelled')->exists());
    }

    public function test_logist_step_cannot_be_undone_by_executor(): void
    {
        $t = $this->viaTanya();
        $this->expectException(ValidationException::class);
        app(StepBack::class)($t, $this->ivan);
    }

    public function test_route_is_set_with_executor_and_edited_later(): void
    {
        $t = app(OpenPickup::class)($this->offer->fresh(), $this->admin);

        // Без «внутри / между» — не назначается.
        $this->actingAs($this->admin)->put(self::LOG.'/transports/'.$t->id.'/executor', ['channel' => 'logist', 'who' => ['logist' => $this->tanya->id], 'destination' => 'ours'])
            ->assertSessionHasErrors('reach');
        $this->assertNull($t->fresh()->channel);

        $this->actingAs($this->admin)->put(self::LOG.'/transports/'.$t->id.'/executor', ['channel' => 'logist', 'who' => ['logist' => $this->tanya->id],
            'reach' => 'intercity', 'destination' => '', 'comment' => 'Пока неизвестно, кому везти'])->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame(Reach::Intercity, $t->reach);
        $this->assertNull($t->destination);
        $this->assertNull($t->routeLabel());
        $this->assertSame('Пока неизвестно, кому везти', $t->comment);

        // Стало известно — Татьяна ставит точку; исполнитель — другой адрес, дальность и комментарий ему не трогать.
        app(LogistAssign::class)($t, ['executor_id' => $this->ivan->id, 'cost' => '65 000'], $this->tanya);
        $this->actingAs($this->tanya)->put(self::LOG.'/transports/'.$t->id.'/route', ['reach' => 'local', 'destination' => 'ours', 'comment' => 'Везём к нам'])->assertSessionHasNoErrors();
        $this->assertSame('К нам', $t->fresh()->routeLabel());
        $this->actingAs($this->ivan)->put(self::LOG.'/transports/'.$t->id.'/route', ['destination' => 'other', 'to_address' => 'Казань, Победы 1', 'reach' => 'intercity', 'comment' => 'x'])->assertSessionHasNoErrors();
        $t->refresh();
        $this->assertSame('Казань, Победы 1', $t->routeLabel());
        $this->assertSame(Reach::Local, $t->reach);
        $this->assertSame('Везём к нам', $t->comment);
        $this->assertSame('Куда: Казань, Победы 1, внутри региона', TransportEvent::where('transport_id', $t->id)->where('type', 'route')->latest('id')->first()->what());
    }
}
