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

    public function test_regions_narrow_catalog_but_not_the_offer(): void
    {
        $sahib = User::create(['name' => 'Сахиб', 'phone' => '79000000011', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $buyer = User::create(['name' => 'Покупатель', 'phone' => '79000000012', 'roles' => [Role::Buyer], 'manager_id' => $sahib->id, 'approved_at' => now()]);
        $city = \App\Cars\Settlement::where('name', 'Москва')->where('region_id', $this->moscow->id)->value('id');
        $moscow = $this->open('ул. Ленина, 1', $city);
        $ekb = $this->open('Свердловская область, г. Екатеринбург, ул. Мира, 5');
        $unknown = $this->open(null);
        $this->assertSame($this->moscow->id, $moscow->region_id);

        app(SetOfferRegions::class)($sahib, [$this->ural->id]);
        $sahib = $sahib->fresh();

        // Фильтр, а не замок (22.10.2026): по ссылке открывается, в каталоге по умолчанию — только его регионы.
        $this->assertTrue($moscow->fresh()->isVisibleTo($sahib));
        $list = fn (User $u) => Offer::siteList($u)->inRegionsOf($u)->pluck('offers.id')->all();
        $this->assertNotContains($moscow->id, $list($sahib));
        $this->assertContains($ekb->id, $list($sahib));
        $this->assertContains($unknown->id, $list($sahib));
        $this->assertNotContains($moscow->id, $list($buyer->fresh()));
        $this->assertSame('Свердл. обл.', SetOfferRegions::label($sahib));

        $page = $this->actingAs($sahib)->get('http://xcar.localhost/offers?unread=0')->assertOk();
        $page->assertSee('offer-'.$ekb->number)->assertDontSee('offer-'.$moscow->number)->assertSee('Показывать из других регионов');
        $this->actingAs($sahib)->get('http://xcar.localhost/offers?unread=0&regions=0')->assertOk()
            ->assertSee('offer-'.$moscow->number)->assertSee('Все регионы');

        // Срочное из Москвы — без звонка; «только вам» — всегда.
        $this->assertFalse($moscow->fresh()->regionOpenTo($sahib));
        $this->assertTrue($ekb->fresh()->regionOpenTo($sahib));
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
