<?php

namespace Tests\Feature;

use App\Http\Admin\OfferController;
use App\Offers\Actions\AskEstimates;
use App\Offers\Actions\CloseEstimates;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «На оценке у менеджеров» (01.11.2026, начальник): отдали черновик без цены продажи на оценку — у админа он во вкладке
 * после «Без продажной цены» и из неё уходит; сняли просьбы — возвращается. Модератору вкладки нет.
 */
class EstimatingTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_asked_offer_moves_to_estimating_and_back(): void
    {
        config(['xcar.mercure.hub' => '']);
        $admin = User::create(['name' => 'Катя', 'phone' => '79000000040', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $manager = User::create(['name' => 'Иван', 'phone' => '79000000041', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $offer = Offer::create(['vendor_id' => Vendor::create(['name' => 'Альфа тест'])->id, 'claim_ref' => '5691/046/00885/26', 'floor_price' => 300000]);
        $in = fn (string $tab, bool $admin = true) => OfferController::scopeFor($tab, Offer::whereKey($offer->id), $admin)->exists();

        $this->assertTrue($in('unpriced'));
        $this->assertFalse($in('estimating'));

        $this->travel(1)->minutes();
        app(AskEstimates::class)($offer->fresh(), [$manager], $admin);
        $this->assertTrue($in('estimating'));
        $this->assertFalse($in('unpriced'));
        $this->assertTrue($in('unpriced', admin: false));
        $this->assertSame('На оценке у менеджеров', $offer->fresh()->crmTab($admin));
        $this->assertEqualsWithDelta(now()->timestamp, $offer->fresh()->tab_at->timestamp, 2);
        $this->assertArrayNotHasKey('estimating', OfferController::presets(User::create(['name' => 'Мод', 'phone' => '79000000042', 'roles' => [Role::Moderator], 'approved_at' => now()])));

        app(CloseEstimates::class)($offer->fresh(), $admin, only: $offer->estimates()->pluck('id')->all());
        $this->assertTrue($in('unpriced'));
        $this->assertFalse($in('estimating'));
    }
}
