<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Cars\Car;
use App\Cars\CarModel;
use App\Http\Admin\GalleryController;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Interest;
use App\Offers\InterestState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * Галерея всем авторизованным; публикация черновика закрывает окно галереи сейчас
 * (как «Убрать из галереи»), а интерес остаётся — в «Откликах» CRM и рядом
 * с подтверждениями. Начало окна хранится — по нему «Отклики» находят побывавшие.
 */
class GalleryAccessTest extends TestCase
{
    use RefreshDatabase;

    private function galleryOffer(): Offer
    {
        Notification::fake();
        $brand = Brand::create(['slug' => 'lada', 'name' => 'Лада']);
        $model = CarModel::create(['brand_id' => $brand->id, 'slug' => 'vesta', 'name' => 'Веста']);
        $vendor = Vendor::create(['name' => 'Т-Страхование тест', 'deal_format' => 'commission']);
        $offer = Offer::create(['vendor_id' => $vendor->id, 'brand_id' => $brand->id, 'model_id' => $model->id,
            'floor_price' => 500000, 'asking_price' => 600000]);
        Media::create(['model_type' => Car::class, 'model_id' => $offer->car_id, 'collection_name' => 'photos',
            'name' => 'кадр', 'file_name' => 'kadr.jpg', 'mime_type' => 'image/jpeg', 'disk' => 'media', 'size' => 10,
            'manipulations' => [], 'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => []]);
        $offer->forceFill(['gallery_from' => now()->subHour(), 'gallery_until' => now()->addDays(2)])->save();

        return $offer->fresh();
    }

    private function user(string $name, Role $role): User
    {
        return User::create(['name' => $name, 'phone' => '7900'.random_int(1000000, 9999999), 'roles' => [$role], 'approved_at' => now()]);
    }

    public function test_gallery_visible_to_all_authorized(): void
    {
        $offer = $this->galleryOffer();
        $this->assertTrue($offer->isGallery());

        foreach ([Role::Admin, Role::Manager, Role::Buyer, Role::Visitor, Role::Reviewer] as $role) {
            $user = $this->user('Тест '.$role->value, $role);
            $this->assertTrue(Offer::visibleTo($user)->whereKey($offer->id)->exists(), $role->value);
        }
        // Гость — ничего, как было.
        $this->assertFalse(Offer::visibleTo(null)->whereKey($offer->id)->exists());
    }

    public function test_publish_closes_gallery_window_but_keeps_interests(): void
    {
        $offer = $this->galleryOffer();
        $buyer = $this->user('Покупаев Пётр', Role::Buyer);
        Interest::create(['offer_id' => $offer->id, 'user_id' => $buyer->id, 'state' => InterestState::New]);

        app(ChangeOfferState::class)($offer, OfferState::Open, $this->user('Владелец', Role::Admin));
        $offer = $offer->fresh();

        // Из галереи ушло: окно закрыто сейчас, черновиком больше не всплывёт.
        $this->assertSame(OfferState::Open, $offer->state);
        $this->assertFalse($offer->isGallery());
        $this->assertNotNull($offer->gallery_from);
        $this->assertTrue($offer->gallery_until->isPast());
        $this->assertFalse(Offer::query()->inGallery()->whereKey($offer->id)->exists());
        // Интерес остался: в «Откликах» и в предложении.
        $this->assertSame(1, $offer->interests()->count());
        $this->assertTrue(GalleryController::scopeFor('interest', Offer::query())->whereKey($offer->id)->exists());
        // В истории — «Убрано из галереи».
        $this->assertTrue($offer->events()->where('type', OfferEventType::Gallery)->exists());
        // А в продаже появилось.
        $this->assertTrue(Offer::query()->onSale()->whereKey($offer->id)->exists());
    }
}
