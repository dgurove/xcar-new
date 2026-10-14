<?php

namespace App\Live;

use App\Cars\Car;
use App\Cars\Events\CarChanged;
use App\Logistics\Listeners\RefreshLogistics;
use App\Logistics\Transport;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Showing;
use App\Park\City;
use App\Park\Vehicle;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Машина поменялась — перечитываются все экраны, где она видна: продажа в CRM (список, редактор, галерея, «Сделки»,
 * «Гараж»), на сайте (плитка на экране, страница, гараж и сделка менеджера), хранение на парковке («Заявки», «Наличие»,
 * дело ТС), перевозка в логистике. Одна машина у всех дорожек (`Cars\Car`, 14.10.2026), поэтому правка на парковке —
 * это правка и в CRM, и наоборот. Пачкой (OCR, импорт кадров, команда) экраны не дёргаются на каждую машину —
 * `Publisher::refreshSoon`.
 */
final class RefreshCar
{
    public function __construct(private Publisher $publish) {}

    public function __invoke(CarChanged $e): void
    {
        $offer = Offer::withoutGlobalScopes()->with(['deal', 'garageCar'])->where('car_id', $e->carId)->first();
        $vehicle = Vehicle::withoutGlobalScope(City::SCOPE)->where('car_id', $e->carId)->first(['id', 'car_id']);
        if ($offer) {
            $this->offer($offer);
        }
        if ($vehicle) {
            $this->publish->refreshSoon(Topics::PARK, ["/cars/{$vehicle->id}"]);
            $this->publish->refreshSoon(Topics::PARK, ['/', '/requests', '/cars']);
        }
        Transport::open()->where('car_id', $e->carId)->get()->each(fn (Transport $t) => $this->publish->refreshSoon(RefreshLogistics::topics($t), ['/transports', "/transports/{$t->id}"]));
    }

    private function offer(Offer $offer): void
    {
        $n = $offer->number;
        $this->publish->refreshSoon(Topics::STAFF, ["/offers/{$n}"]);
        $this->publish->refreshSoon(Topics::STAFF, ['/', '/gallery', '/work/deals', '/work/garage']);
        // На сайте — где её видят: в продаже, в галерее, в сделке (белый список); плитка — только та, что на экране.
        if (in_array($offer->state, [OfferState::Open, OfferState::Sold], true) || $offer->isGallery()) {
            $seers = [Topics::CATALOG, ...Showing::seersOf($offer)->map(fn ($id) => Topics::user($id))->all()];
            $this->publish->card($n, $seers, only: true);
            $this->publish->refreshSoon($seers, ["/offers/{$n}"]);
        }
        // С машиной работает менеджер: его сделка, гараж, вывоз.
        if ($buyer = $offer->deal?->buyer_id) {
            $this->publish->refreshSoon(Topics::user($buyer), ['/deals', "/deals/{$offer->deal->id}"]);
        }
        if ($manager = $offer->garageCar?->manager_id) {
            $this->publish->refreshSoon(Topics::user($manager), ['/garage', "/garage/cars/{$n}"]);
        }
        if ($offer->evacuator_id && $offer->evacuator_id !== $manager) {
            $this->publish->refreshSoon(Topics::user($offer->evacuator_id), ['/garage', "/garage/pickups/{$n}"]);
        }
    }

    /**
     * Файлы машины: добавили (`added` — когда файл уже лёг на диск, а не когда завели строку), удалили, скрыли из
     * продажи, переставили, сменили стадию. Сжатые копии (`generated_conversions`) — не правка: их дописывает очередь
     * после каждого кадра.
     */
    public static function media(Media $media, bool $added = false, bool $gone = false): void
    {
        if ($media->model_type !== Car::class || ! $media->model_id) {
            return;
        }
        if (! $added && ! $gone && ($media->wasRecentlyCreated || ! $media->wasChanged(['custom_properties', 'order_column', 'model_id', 'collection_name']))) {
            return;
        }
        $id = (int) $media->model_id;
        DB::afterCommit(fn () => CarChanged::dispatch($id, ['photos']));
    }
}
