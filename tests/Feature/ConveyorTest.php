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
 * Конвейер главной CRM (04.11.2026): числа этапов — те же двери, что вкладки предложений, Каркаде на схеме нет (владелец:
 * «забей»), живые числа — только сотрудникам. Молча ломается то, чего на схеме не заметить: лишняя машина в числе.
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

        $nodes = Conveyor::view();
        $this->assertSame(1, $nodes['floor']['total']);
        $this->assertSame(1, $nodes['price']['total']);
        $this->assertSame(0, $nodes['bids']['total']);

        $this->get(self::CRM.'/space/conveyor')->assertRedirect();
        $this->actingAs($manager)->get(self::CRM.'/space/conveyor')->assertForbidden();
        $this->actingAs($admin)->get(self::CRM.'/space/conveyor')->assertOk()
            ->assertHeader('Content-Type', 'text/vnd.turbo-stream.html; charset=utf-8')
            ->assertSee('target="conveyor"', false)->assertSee('data-node="floor"', false);
    }
}
