<?php

namespace Tests\Feature;

use App\Garage\Car as GarageCar;
use App\Logistics\SaleState;
use App\Logistics\Transport;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Таблица «Вывоза» (18.10.2026). Тихие дорогие ошибки: чип «Продажа» и сортировка (SQL) разошлись со словом строки (PHP)
 * — Катя отдаёт исполнителю машину, у которой уже есть покупатель; «Срочно» считает не те строки; открытая карточка
 * подменяет строку списка строкой без галочки — столбцы съезжают (владелец: «нажал на строку — всё нахер съезжает»).
 */
class LogisticsTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_state_matches_sql_and_moderator_leads_the_table(): void
    {
        Notification::fake();
        $moderator = User::create(['name' => 'Лиза', 'phone' => '79000000003', 'roles' => [Role::Moderator], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Иван Петров', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $offer = fn (string $ref, OfferState $state) => tap(Offer::create(['claim_ref' => $ref, 'floor_price' => 500000]), fn (Offer $o) => $o->forceFill(['state' => $state])->save());
        $task = fn (Offer $o, int $days) => tap(Transport::create(['car_id' => $o->car_id, 'state' => 'new', 'origin' => 'sale', 'kind' => 'pickup']), fn (Transport $t) => Transport::whereKey($t->id)->update(['created_at' => now()->subDays($days)]));

        $draft = $task($offer('T-1/26', OfferState::Draft), 1);
        $old = $task($offer('T-2/26', OfferState::Draft), 4);
        $open = $task($offer('T-3/26', OfferState::Open), 5);
        $sold = $offer('T-4/26', OfferState::Sold);
        Deal::create(['offer_id' => $sold->id, 'buyer_id' => $manager->id, 'state' => DealState::Active, 'cost' => 500000]);
        $deal = $task($sold, 6);
        $kept = $offer('T-5/26', OfferState::Garage);
        GarageCar::create(['offer_id' => $kept->id, 'manager_id' => $manager->id, 'taken_at' => now()]);
        $garage = $task($kept, 6);

        $sql = Transport::query()->selectRaw('id, '.SaleState::KEY_SQL.' k, '.SaleState::MANAGER_SQL.' m')->pluck('k', 'id');
        $managers = Transport::query()->selectRaw('id, '.SaleState::MANAGER_SQL.' m')->pluck('m', 'id');
        $states = Transport::with(SaleState::WITH)->get()->mapWithKeys(fn (Transport $t) => [$t->id => SaleState::of($t)]);
        foreach ($states as $id => $s) {
            $this->assertSame($s->key, $sql[$id], "продажа задачи {$id}");
            $this->assertSame($s->manager?->id, $managers[$id] === null ? null : (int) $managers[$id], "менеджер задачи {$id}");
        }
        $this->assertSame(['draft', 'draft', 'open', 'deal', 'garage'], [$states[$draft->id]->key, $states[$old->id]->key, $states[$open->id]->key, $states[$deal->id]->key, $states[$garage->id]->key]);
        $this->assertTrue($states[$draft->id]->waiting, 'ищем покупателя, время есть — приглушена');
        $urgent = $states->filter->urgent->keys()->sort()->values()->all();
        $this->assertSame([$old->id, $open->id], $urgent, 'срочно — без покупателя три дня и больше');
        $this->assertSame($urgent, SaleState::urgent(Transport::query())->orderBy('id')->pluck('id')->all(), 'чип «Срочно» — те же строки');

        // Модератор ведёт логистику: «Без исполнителя» со всеми задачами, а свежая строка карточки — с галочкой, как в списке.
        $host = 'http://'.config('xcar.logistics_host');
        $this->actingAs($moderator)->get($host.'/transports')->assertOk()->assertSee('Без исполнителя')->assertSee('pick-transport-'.$deal->id, false);
        $this->actingAs($moderator)->get($host.'/transports?peek='.$deal->id, ['Turbo-Frame' => 'detail'])
            ->assertOk()->assertSee('pick-transport-'.$deal->id, false)->assertSee('Иван П.');
    }

    /** «Уже вывезли» задним числом: машина у менеджера с гаражом этой датой, подготовка началась; пачкой — что не прошло, в тост. */
    public function test_backdate_closes_task_as_delivered(): void
    {
        Notification::fake();
        $moderator = User::create(['name' => 'Лиза', 'phone' => '79000000003', 'roles' => [Role::Moderator], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Иван Петров', 'phone' => '79000000002', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $kept = tap(Offer::create(['claim_ref' => 'T-7/26', 'floor_price' => 500000]), fn (Offer $o) => $o->forceFill(['state' => OfferState::Garage])->save());
        $car = GarageCar::create(['offer_id' => $kept->id, 'manager_id' => $manager->id, 'taken_at' => now(), 'state' => 'waiting']);
        $garage = Transport::create(['car_id' => $kept->car_id, 'state' => 'new', 'origin' => 'sale', 'kind' => 'pickup']);
        $draft = Offer::create(['claim_ref' => 'T-8/26', 'floor_price' => 500000]);
        $lone = Transport::create(['car_id' => $draft->car_id, 'state' => 'new', 'origin' => 'sale', 'kind' => 'pickup']);
        $host = 'http://'.config('xcar.logistics_host');
        $at = now()->subDay()->setTime(14, 30);

        $this->actingAs($moderator)->put($host.'/transports/backdate', ['transports' => [$garage->id, $lone->id], 'picked_at' => $at->format('Y-m-d\TH:i'), 'destination' => 'keeper', 'keys' => '1'])
            ->assertSessionHasNoErrors()->assertSessionHas('toast', fn ($t) => str_contains($t, 'Отмечено вывезенными 1') || str_contains($t, ': у машины нет менеджера'));
        $garage->refresh();
        $this->assertSame('delivered', $garage->state->value);
        $this->assertSame($at->format('Y-m-d H:i'), $garage->delivered_at->format('Y-m-d H:i'));
        $this->assertSame('keeper', $kept->fresh()->car_place->value);
        $this->assertSame('repair', $car->fresh()->state->value, 'гаражная машина довезена — подготовка');
        $this->assertSame(1, $kept->fresh()->car->keys_count);
        $this->assertSame('new', $lone->fresh()->state->value, 'без менеджера «к менеджеру» не проходит');

        // Одна задача — к нам, датой; позже «сейчас» нельзя.
        $this->actingAs($moderator)->post($host.'/transports/'.$lone->id.'/backdate', ['picked_at' => now()->addDay()->format('Y-m-d\TH:i')])->assertSessionHasErrors('picked_at');
        $this->actingAs($moderator)->post($host.'/transports/'.$lone->id.'/backdate', ['picked_at' => $at->format('Y-m-d\TH:i'), 'destination' => 'ours'])->assertSessionHasNoErrors();
        $this->assertSame('delivered', $lone->fresh()->state->value);
        $this->assertSame('with_us', $draft->fresh()->car_place->value);
    }
}
