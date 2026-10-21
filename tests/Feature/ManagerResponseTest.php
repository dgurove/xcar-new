<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\OfferViewer;
use App\Support\Surface;
use App\Users\ManagerResponse;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Отклик менеджера в CRM (21.10.2026): открылось, открыл, подтвердил сам («за менеджера» не в счёт); значок приложения на
 * сайте — чаты и ход по сделкам, без уведомлений ленты.
 */
class ManagerResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_seen_opened_and_own_bids(): void
    {
        $manager = User::create(['name' => 'Менеджер Отклика', 'phone' => '79000000088', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $staff = User::create(['name' => 'Админ', 'phone' => '79000000089', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $brand = Brand::create(['slug' => 'haval', 'name' => 'Haval']);
        $vendor = Vendor::create(['name' => 'Т-Страхование тест', 'deal_format' => 'commission']);
        $offers = collect(range(1, 3))->map(function ($i) use ($brand, $vendor, $manager) {
            $model = CarModel::create(['brand_id' => $brand->id, 'slug' => 'm'.$i, 'name' => 'M'.$i]);
            $offer = Offer::create(['vendor_id' => $vendor->id, 'brand_id' => $brand->id, 'model_id' => $model->id, 'floor_price' => 500000, 'asking_price' => 600000]);
            $offer->forceFill(['state' => OfferState::Open, 'published_at' => now()->subDays(2), 'bids_close_at' => now()->addDay()])->save();
            OfferViewer::insert(['offer_id' => $offer->id, 'user_id' => $manager->id, 'opens_at' => now()->subDays(2)]);

            return $offer;
        });
        foreach ($offers->take(2) as $o) {
            DB::table('offer_reads')->insert(['user_id' => $manager->id, 'offer_id' => $o->id, 'read_at' => now()->subDays(2)->addHour()]);
        }
        DB::table('bids')->insert([
            ['offer_id' => $offers[0]->id, 'user_id' => $manager->id, 'amount' => 600000, 'state' => 'active', 'placed_by' => null, 'created_at' => now(), 'updated_at' => now()],
            ['offer_id' => $offers[2]->id, 'user_id' => $manager->id, 'amount' => 600000, 'state' => 'active', 'placed_by' => $staff->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $r = ManagerResponse::of($manager);
        $this->assertSame([3, 2, 1], [$r->seen, $r->opened, $r->bids]);
        $this->assertSame('67 %', $r->shareLabel());
        $this->assertSame(3600, $r->medianSeconds);
        $this->assertFalse($r->low());
    }

    public function test_site_app_badge_has_no_feed_notifications(): void
    {
        $manager = User::create(['name' => 'Менеджер Значка', 'phone' => '79000000090', 'roles' => [Role::Manager], 'approved_at' => now()]);
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => User::class, 'notifiable_id' => $manager->id,
            'data' => json_encode(['title' => 'Что-то', 'href' => '/offers']), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(1, $manager->unreadCount(Surface::Site));
        $this->assertSame(0, $manager->badgeCount(Surface::Site));
    }
}
