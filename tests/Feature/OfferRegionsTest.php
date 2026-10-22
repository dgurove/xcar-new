<?php

namespace Tests\Feature;

use App\Cars\Region;
use App\Offers\Actions\SyncViewers;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Actions\SetOfferRegions;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Регионы менеджера (22.10.2026, Сахиб с Урала): машина не из его регионов уходит из его волн показа — нет в каталоге, у
 * покупателей и в галерее; машину без региона видят все. Ошибка тут молчалива: менеджер просто не увидит машину.
 */
class OfferRegionsTest extends TestCase
{
    use RefreshDatabase;

    private Region $moscow;

    private Region $ural;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        // Справочник регионов кладут миграции.
        $this->moscow = Region::where('plate', '77')->firstOrFail();
        $this->ural = Region::where('plate', '66')->firstOrFail();
    }

    private function open(?string $address, ?int $city = null): Offer
    {
        $offer = Offer::create(['floor_price' => 500000, 'asking_price' => 700000, 'inspection_address' => $address, 'settlement_id' => $city]);
        $offer->forceFill(['state' => OfferState::Open, 'published_at' => now()->subMinute(), 'bids_close_at' => now()->addDay()])->save();
        app(SyncViewers::class)($offer->fresh());

        return $offer->fresh();
    }

    public function test_manager_sees_only_his_regions(): void
    {
        $sahib = User::create(['name' => 'Сахиб', 'phone' => '79000000011', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $buyer = User::create(['name' => 'Покупатель', 'phone' => '79000000012', 'roles' => [Role::Buyer], 'manager_id' => $sahib->id, 'approved_at' => now()]);
        $city = \App\Cars\Settlement::where('name', 'Москва')->where('region_id', $this->moscow->id)->value('id');
        $moscow = $this->open('ул. Ленина, 1', $city);
        $ekb = $this->open('Свердловская область, г. Екатеринбург, ул. Мира, 5');
        $unknown = $this->open(null);
        $this->assertSame($this->moscow->id, $moscow->region_id);
        $this->assertTrue($moscow->isVisibleTo($sahib));

        app(SetOfferRegions::class)($sahib, [$this->ural->id]);

        $this->assertFalse($moscow->fresh()->isVisibleTo($sahib->fresh()));
        $this->assertFalse($moscow->fresh()->isVisibleTo($buyer->fresh()));
        $this->assertTrue($ekb->fresh()->isVisibleTo($sahib->fresh()));
        $this->assertTrue($ekb->fresh()->isVisibleTo($buyer->fresh()));
        $this->assertTrue($unknown->fresh()->isVisibleTo($sahib->fresh()));
        $this->assertSame('Свердл. обл.', SetOfferRegions::label($sahib->fresh()));

        // Новая московская после выбора — тоже мимо.
        $this->assertFalse($this->open('Тверская, 3', $city)->isVisibleTo($sahib->fresh()));

        // Галерея: волн нет, условие у неё своё.
        $draft = Offer::create(['floor_price' => 500000, 'settlement_id' => $city]);
        $draft->forceFill(['gallery_from' => now()->subHour(), 'gallery_until' => now()->addDay()])->save();
        $this->assertFalse($draft->fresh()->isVisibleTo($sahib->fresh()));
        $this->assertFalse($draft->fresh()->isVisibleTo($buyer->fresh()));

        // Вся Россия — снова видно.
        app(SetOfferRegions::class)($sahib->fresh(), Region::pluck('id')->all());
        $this->assertNull($sahib->fresh()->offer_regions);
        $this->assertTrue($moscow->fresh()->isVisibleTo($sahib->fresh()));
        $this->assertTrue($draft->fresh()->isVisibleTo($sahib->fresh()));
    }

    public function test_regions_page_saves_for_self_and_admin(): void
    {
        $sahib = User::create(['name' => 'Сахиб', 'phone' => '79000000011', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000001', 'roles' => [Role::Admin], 'approved_at' => now()]);

        $this->actingAs($sahib)->get('http://xcar.localhost/account/regions')->assertOk()->assertSee('Уральский');
        $this->actingAs($sahib)->put('http://xcar.localhost/account/regions', ['regions' => [$this->ural->id]])->assertSessionHasNoErrors();
        $this->assertSame([$this->ural->id], $sahib->fresh()->offer_regions);
        $this->actingAs($sahib)->put('http://xcar.localhost/account/regions', ['regions' => []])->assertSessionHasErrors('regions');

        $this->actingAs($admin)->get('http://crm.xcar.localhost/settings/users/'.$sahib->id.'/regions')->assertOk();
        $this->actingAs($admin)->put('http://crm.xcar.localhost/settings/users/'.$sahib->id.'/regions', ['regions' => [$this->moscow->id]])->assertSessionHasNoErrors();
        $this->assertSame([$this->moscow->id], $sahib->fresh()->offer_regions);
    }
}
