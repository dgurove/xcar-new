<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Tag;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Метки по вендору (11.10.2026): черновик, которому встал вендор, получает его метки; снятая у предложения не
 * возвращается; смена вендора меняет его метки, ручные остаются; опубликованное не трогается.
 */
class VendorTagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_tags_follow_vendor_on_drafts(): void
    {
        $brand = Brand::create(['slug' => 'kia', 'name' => 'Kia']);
        $model = CarModel::create(['brand_id' => $brand->id, 'slug' => 'rio', 'name' => 'Rio']);
        $alfa = Vendor::create(['name' => 'Альфа тест']);
        $tbank = Vendor::create(['name' => 'Т тест']);
        [$invoice, $defer, $direct] = [Tag::create(['name' => 'Оплата по счету', 'color' => 'orange', 'sort' => 1]),
            Tag::create(['name' => 'Отсрочка оплаты', 'color' => 'blue', 'sort' => 2]), Tag::create(['name' => 'Прямая сделка', 'color' => 'blue', 'sort' => 3])];
        $alfa->defaultTags()->sync([$invoice->id, $defer->id]);
        $tbank->defaultTags()->sync([$direct->id]);

        $offer = Offer::create(['brand_id' => $brand->id, 'model_id' => $model->id, 'vendor_id' => $alfa->id, 'tags' => ['Срочно']])->fresh();
        $this->assertSame(['Срочно', 'Оплата по счету', 'Отсрочка оплаты'], $offer->tags);

        // Сняли у одного — сохранение его не возвращает.
        $offer->update(['tags' => ['Срочно', 'Оплата по счету'], 'floor_price' => 500000]);
        $offer->update(['floor_price' => 510000]);
        $this->assertSame(['Срочно', 'Оплата по счету'], $offer->fresh()->tags);

        // Смена вендора: метки прежнего уходят, нового встают, ручные остаются.
        $offer->update(['vendor_id' => $tbank->id]);
        $this->assertSame(['Срочно', 'Прямая сделка'], $offer->fresh()->tags);

        // Опубликованное не трогается.
        $open = Offer::create(['brand_id' => $brand->id, 'model_id' => $model->id, 'vendor_id' => $tbank->id])->fresh();
        $open->forceFill(['state' => OfferState::Open])->save();
        $open->update(['vendor_id' => $alfa->id]);
        $this->assertSame(['Прямая сделка'], $open->fresh()->tags);
    }
}
