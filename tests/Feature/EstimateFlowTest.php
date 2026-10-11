<?php

namespace Tests\Feature;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Media\Jobs\StampPhotos;
use App\Offers\Actions\AskEstimates;
use App\Offers\Actions\GiveEstimate;
use App\Offers\Actions\RejectEstimate;
use App\Offers\Actions\TakeEstimate;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Estimate;
use App\Offers\EstimateState;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Оценка цены продажи менеджером (11.10.2026): черновик виден только тем, кому отдан на оценку; «Отклонить» — снова
 * ждём, срок прежний; «Взять» ставит цену продажи и закрывает остальные просьбы; запрет шеринга снимается, только если
 * его поставила оценка.
 */
class EstimateFlowTest extends TestCase
{
    use RefreshDatabase;

    private function draft(bool $locked = false): Offer
    {
        Notification::fake();
        Queue::fake([StampPhotos::class]);
        $brand = Brand::create(['slug' => 'kia', 'name' => 'Kia']);
        $model = CarModel::create(['brand_id' => $brand->id, 'slug' => 'rio', 'name' => 'Rio']);

        return Offer::create(['brand_id' => $brand->id, 'model_id' => $model->id, 'floor_price' => 500000, 'share_locked' => $locked])->fresh();
    }

    private function user(string $name, Role $role): User
    {
        return User::create(['name' => $name, 'phone' => '7900'.random_int(1000000, 9999999), 'roles' => [$role], 'approved_at' => now()]);
    }

    public function test_asked_manager_sees_draft_and_take_sets_price(): void
    {
        $offer = $this->draft();
        $admin = $this->user('Админов Андрей', Role::Admin);
        [$ivan, $petr, $other] = [$this->user('Иванов Иван', Role::Manager), $this->user('Петров Пётр', Role::Manager), $this->user('Сидоров Семён', Role::Manager)];

        app(AskEstimates::class)($offer, [$ivan, $petr], $admin);
        $offer->refresh();
        $this->assertTrue($offer->share_locked);
        $this->assertTrue($offer->share_locked_by_estimate);
        Queue::assertPushed(StampPhotos::class);
        // Черновик — только тем, кому отдан: чужой менеджер его не видит, в каталоге своего круга его нет.
        $this->assertTrue($offer->isVisibleTo($ivan));
        $this->assertFalse($offer->isVisibleTo($other));
        $this->assertFalse(Offer::siteList($ivan)->whereKey($offer->id)->exists());
        $this->assertSame([$offer->id], Offer::askedOf($ivan)->pluck('id')->all());

        $estimate = Estimate::openFor($offer, $ivan);
        $due = $estimate->due_at->toIso8601String();
        app(GiveEstimate::class)($estimate, 1150000, $ivan);
        app(RejectEstimate::class)($estimate->fresh(), $admin);
        $estimate->refresh();
        $this->assertSame(EstimateState::Asked, $estimate->state);
        $this->assertNull($estimate->amount);
        $this->assertSame(1150000, $estimate->rejected_amount);
        $this->assertSame($due, $estimate->due_at->toIso8601String());

        app(GiveEstimate::class)($estimate, 1200000, $ivan);
        app(TakeEstimate::class)($estimate->fresh(), $admin);
        $offer->refresh();
        $this->assertSame(1200000, $offer->asking_price);
        $this->assertSame(EstimateState::Taken, $estimate->fresh()->state);
        $this->assertSame(EstimateState::Closed, Estimate::where('user_id', $petr->id)->first()->state);
        $this->assertFalse($offer->share_locked);
        $this->assertFalse($offer->share_locked_by_estimate);
        $this->assertFalse($offer->isVisibleTo($petr));
    }

    public function test_admin_lock_survives_and_own_price_closes(): void
    {
        $offer = $this->draft(locked: true);
        $admin = $this->user('Админов Андрей', Role::Admin);
        $ivan = $this->user('Иванов Иван', Role::Manager);

        app(AskEstimates::class)($offer, [$ivan], $admin);
        $this->assertFalse($offer->fresh()->share_locked_by_estimate);

        app(UpdateOffer::class)($offer->fresh(), ['asking_price' => 900000], $admin);
        $offer->refresh();
        $this->assertSame(EstimateState::Closed, Estimate::where('offer_id', $offer->id)->first()->state);
        // Запрет ставил админ — оценка его не снимает.
        $this->assertTrue($offer->share_locked);
    }

    public function test_correction_agree_take_keep_and_schedule(): void
    {
        $offer = $this->draft();
        $offer->forceFill(['asking_price' => 1200000])->save();
        $admin = $this->user('Админов Андрей', Role::Admin);
        [$ivan, $petr] = [$this->user('Иванов Иван', Role::Manager), $this->user('Петров Пётр', Role::Manager)];

        app(AskEstimates::class)($offer->fresh(), [$ivan, $petr], $admin);
        $ask = Estimate::openFor($offer, $ivan);
        $this->assertTrue($ask->isCorrection());
        $this->assertSame(1200000, $ask->base_price);

        // «Согласен» — его сумма и есть цена админа; решать нечего.
        app(GiveEstimate::class)($ask, 1200000, $ivan);
        $this->assertTrue($ask->fresh()->agreed());
        $this->assertSame(0, Estimate::needsDecision()->count());

        // Своя цена — решать админу; «Взять» ставит её ценой продажи и закрывает остальные.
        $own = Estimate::openFor($offer, $petr);
        app(GiveEstimate::class)($own, 1150000, $petr);
        $this->assertSame(1, Estimate::needsDecision()->count());
        app(TakeEstimate::class)($own->fresh(), $admin);
        $this->assertSame(1150000, $offer->fresh()->asking_price);
        $this->assertSame(0, $offer->estimates()->open()->count());

        // «Оставить свою» — цена прежняя, просьбы закрыты.
        app(AskEstimates::class)($offer->fresh(), [$ivan], $admin);
        app(GiveEstimate::class)(Estimate::openFor($offer, $ivan), 1100000, $ivan);
        app(\App\Offers\Actions\CloseEstimates::class)($offer->fresh(), $admin, keep: true);
        $this->assertSame(1150000, $offer->fresh()->asking_price);
        $this->assertSame(0, $offer->estimates()->open()->count());

        // Отправили в продажу слотом, пока ждём, — просьбы снимаются сами.
        \Spatie\MediaLibrary\MediaCollections\Models\Media::create(['model_type' => Offer::class, 'model_id' => $offer->id, 'collection_name' => 'photos',
            'name' => 'кадр', 'file_name' => 'kadr.jpg', 'mime_type' => 'image/jpeg', 'disk' => 'media', 'size' => 10,
            'manipulations' => [], 'custom_properties' => [], 'generated_conversions' => [], 'responsive_images' => []]);
        app(AskEstimates::class)($offer->fresh(), [$petr], $admin);
        $this->assertSame(1, $offer->estimates()->open()->count());
        app(\App\Offers\Actions\ScheduleOffer::class)->__invoke($offer->fresh(), 'next', $admin);
        $this->assertSame(0, $offer->estimates()->open()->count());
    }
}
