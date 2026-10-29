<?php

namespace Tests\Feature;

use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Память полей модели (`Support\FastAttributes`, 29.10.2026): ошибка там молчалива — после записи читалось бы старое
 * значение. Значение идёт за сырым любым путём, дата не делится между чтениями, метки времени — как у Eloquent при
 * выключенных метках, касты запроса и связи — свои.
 */
class FastAttributesTest extends TestCase
{
    use RefreshDatabase;

    private function offer(): Offer
    {
        config(['xcar.mercure.hub' => '']);

        return Offer::create(['vendor_id' => Vendor::create(['name' => 'Тест'])->id, 'floor_price' => 100000, 'bids_close_at' => now()->addDay()]);
    }

    public function test_value_follows_raw_on_write_refresh_and_hydration(): void
    {
        $offer = $this->offer()->fresh();
        $this->assertSame(100000, $offer->floor_price);
        $offer->floor_price = 150000;
        $this->assertSame(150000, $offer->floor_price);
        $this->assertSame(OfferState::Draft, $offer->state);
        $offer->state = OfferState::Open;
        $this->assertSame(OfferState::Open, $offer->state);
        $offer->save();

        DB::table('offers')->where('id', $offer->id)->update(['floor_price' => 200000, 'state' => OfferState::Archived->value]);
        $this->assertSame(150000, $offer->floor_price);
        $offer->refresh();
        $this->assertSame(200000, $offer->floor_price);
        $this->assertSame(OfferState::Archived, $offer->state);
        $this->assertSame(200000, Offer::find($offer->id)->floor_price);
    }

    public function test_dates_are_copies(): void
    {
        $offer = $this->offer();
        $close = $offer->bids_close_at;
        $close->addYear();
        $this->assertTrue($offer->bids_close_at->lt(now()->addDays(2)));
        $this->assertNotSame($offer->bids_close_at, $offer->bids_close_at);
    }

    public function test_timestamps_follow_without_timestamps(): void
    {
        $offer = Offer::find($this->offer()->id);
        Offer::withoutTimestamps(fn () => $this->assertIsString($offer->created_at));
        $this->assertInstanceOf(CarbonInterface::class, $offer->created_at);
        // Наоборот: запомненная дата при выключенных метках — снова сырое значение.
        Offer::withoutTimestamps(fn () => $this->assertIsString($offer->created_at));
        $this->assertInstanceOf(CarbonInterface::class, Offer::find($offer->id)->created_at);
    }

    public function test_query_casts_and_class_casts_stay_apart(): void
    {
        $offer = $this->offer();
        $withCast = Offer::query()->withExists('reprices as reprice_pending')->findOrFail($offer->id);
        $this->assertFalse($withCast->reprice_pending);
        $plain = Offer::query()->selectRaw('offers.*, 1 as reprice_pending')->findOrFail($offer->id);
        $this->assertSame(1, $plain->reprice_pending);
        $this->assertSame(100000, $withCast->floor_price);
        $this->assertSame(OfferState::Draft, $plain->state);
    }

    public function test_loaded_relation_and_replacement(): void
    {
        $offer = Offer::with('vendor')->findOrFail($this->offer()->id);
        $vendor = $offer->vendor;
        $this->assertSame($vendor, $offer->vendor);
        $other = Vendor::create(['name' => 'Другой']);
        $offer->setRelation('vendor', $other);
        $this->assertSame($other, $offer->vendor);
        $offer->unsetRelation('vendor');
        $this->assertSame('Тест', $offer->vendor->name);
    }

    public function test_roles_follow_writes(): void
    {
        $user = User::create(['name' => 'Катя', 'phone' => '79000000030', 'roles' => [Role::Moderator], 'approved_at' => now()]);
        $this->assertFalse($user->isAdmin());
        $this->assertFalse($user->canManageCrm());
        $user->roles = [Role::Admin, Role::Moderator];
        $this->assertTrue($user->isAdmin());
        $this->assertTrue($user->canManageCrm());
        $user->save();
        DB::table('users')->where('id', $user->id)->update(['roles' => json_encode([Role::Manager->value])]);
        $user->refresh();
        $this->assertFalse($user->isAdmin());
        $this->assertTrue($user->hasRole(Role::Manager));
    }
}
