<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Media\CleanFor;
use App\Media\MediaUrl;
use App\Offers\Actions\AskEstimates;
use App\Offers\Actions\GiveEstimate;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Кадр без водяного знака (24.11.2026, владелец: «знаки — для менеджеров, пока не дадут оценку; зачем они в админке»): знак
 * впечён в файл, пока кто-то оценивает; сотрудник и ответивший менеджер получают чистую копию закрытым адресом, остальные —
 * файл со знаком. Молчаливо: чужой знак в CRM никто не заметит, а чистый кадр оценивающему — утечка.
 */
class CleanPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_and_answered_manager_get_clean_photo(): void
    {
        config(['xcar.mercure.hub' => '']);
        Storage::fake('public');
        Storage::fake('private');
        $admin = User::create(['name' => 'Андрей', 'phone' => '79000000060', 'roles' => [Role::Admin], 'approved_at' => now()]);
        $roma = User::create(['name' => 'Роман', 'phone' => '79000000061', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $ivan = User::create(['name' => 'Иван', 'phone' => '79000000062', 'roles' => [Role::Manager], 'approved_at' => now()]);
        $offer = Offer::create(['vendor_id' => Vendor::create(['name' => 'Альфа'])->id, 'claim_ref' => '5691/046/00920/26',
            'brand_id' => Brand::create(['slug' => 'kamaz', 'name' => 'КАМАЗ'])->id, 'floor_price' => 900000]);
        $offer->addMedia(UploadedFile::fake()->image('a.jpg', 800, 600))->toMediaCollection('photos');
        $offer = $offer->fresh();

        $asked = app(AskEstimates::class)($offer, [$roma, $ivan], $admin);
        \App\Media\Jobs\StampPhotos::dispatchSync($offer->carRecord());
        $media = $offer->carRecord()->getFirstMedia('photos')->fresh();
        $this->assertTrue((bool) $media->getCustomProperty('watermarked'), 'на время оценки кадр со знаком');

        // Оценивающему — со знаком, и закрытый адрес ему не отдаётся.
        $this->actingAs($roma);
        $this->assertFalse(CleanFor::allows($roma, $media));
        $this->assertStringNotContainsString('/clean/', MediaUrl::for($media, 'w640'));
        $this->get('/clean/'.$media->id.'/640')->assertNotFound();

        // Сотруднику — чистый, и он отдаётся.
        $this->actingAs($admin);
        $this->assertStringContainsString('/clean/'.$media->id.'/640', MediaUrl::for($media->fresh(), 'w640'));
        $this->get('http://crm.xcar.localhost/clean/'.$media->id.'/640')->assertOk();
        $this->get('http://crm.xcar.localhost/clean/'.$media->id.'/jpg')->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // Рома оценил, Иван ещё думает: знак у файла остаётся, Роме — чистый, Ивану — нет.
        app(GiveEstimate::class)($asked->firstWhere('user_id', $roma->id), 1200000, $roma);
        \App\Media\Jobs\StampPhotos::dispatchSync($offer->carRecord());
        $media = $media->fresh();
        $this->assertTrue((bool) $media->getCustomProperty('watermarked'));
        \Illuminate\Support\Once::flush(); // новый запрос
        $this->assertTrue(CleanFor::allows($roma->fresh(), $media));
        $this->assertFalse(CleanFor::allows($ivan->fresh(), $media));
    }
}
