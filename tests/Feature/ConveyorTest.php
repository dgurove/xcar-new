<?php

namespace Tests\Feature;

use App\Http\Admin\OfferController;
use App\Offers\Offer;
use App\Space\Conveyor;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Конвейер главной CRM (04.11.2026, этапы по людям — 05.11.2026): числа подэтапов — те же двери, что вкладки предложений,
 * Каркаде на схеме нет (владелец: «забей»), живые числа — только сотрудникам, подэтап ведёт в свой список. Молча ломается то,
 * чего на схеме не заметить: лишняя машина в числе.
 */
class ConveyorTest extends TestCase
{
    use RefreshDatabase;

    private const CRM = 'http://crm.xcar.localhost';

    public function test_counts_skip_carcade_and_stream_is_staff_only(): void
    {
        config(['xcar.mercure.hub' => '']);
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000060', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Менеджер', 'phone' => '79000000061', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $alfa = Vendor::create(['name' => 'Альфа тест']);
        // Каркаде заводят миграции — тот, кого прячет `OfferController::carcadeId`.
        Offer::create(['vendor_id' => $alfa->id, 'claim_ref' => 'AT-1']);
        Offer::create(['vendor_id' => OfferController::carcadeId(), 'claim_ref' => 'CC-1']);
        Offer::create(['vendor_id' => $alfa->id, 'claim_ref' => 'AT-2', 'floor_price' => 500000]);

        $steps = collect(Conveyor::view())->flatMap(fn ($stage) => $stage['steps'])->keyBy('key');
        $this->assertSame(1, $steps['floor']['n']);
        $this->assertSame(1, $steps['price']['n']);
        $this->assertSame(0, $steps['bids']['n']);
        $this->assertSame(0, $steps['floor']['hot']);

        $this->get(self::CRM.'/space/conveyor')->assertRedirect();
        $this->actingAs($manager)->get(self::CRM.'/space/conveyor')->assertForbidden();
        $this->actingAs($admin)->get(self::CRM.'/space/conveyor')->assertOk()
            ->assertSee('href="/offers?preset=nofloor"', false)->assertDontSee('<html', false);
    }
}
