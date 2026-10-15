<?php

namespace App\Live;

use App\Cars\Car;
use App\Cars\Events\CarChanged;
use App\Logistics\Listeners\RefreshLogistics;
use App\Logistics\Transport;
use App\Offers\Deal;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Showing;
use App\Park\City;
use App\Park\Request as ParkRequest;
use App\Park\Vehicle;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Машина поменялась — перечитываются все экраны, где она видна: продажа в CRM (список, редактор, галерея, «Сделки»,
 * «Гараж»), на сайте (плитка на экране, страница, гараж и сделка менеджера), хранение на парковке («Заявки», «Наличие»,
 * дело ТС), перевозка в логистике. Одна машина у всех дорожек (`Cars\Car`, 14.10.2026), поэтому правка на парковке —
 * это правка и в CRM, и наоборот. Списки (CRM, «Работа», «Заявки», «Наличие») — строкой этой машины (`Publisher::rows`),
 * страница машины — целиком. Пачкой (OCR, импорт кадров, команда) экраны не дёргаются на каждую машину —
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
            // Списки — строкой ТС, а не морфом всего «Наличия»; в «Заявках» ключ строки — заявка, закрытые тоже: ушедшая
            // с экрана уходит.
            $requests = ParkRequest::withoutGlobalScope(City::SCOPE)->where('vehicle_id', $vehicle->id)->pluck('id')->all();
            $this->publish->rowsSoon(Topics::PARK, ['/requests' => $requests, '/cars' => [$vehicle->id]]);
        }
        Transport::open()->where('car_id', $e->carId)->get()->each(fn (Transport $t) => $this->publish->refreshSoon(RefreshLogistics::topics($t), ['/transports', "/transports/{$t->id}"]));
    }

    private function offer(Offer $offer): void
    {
        $n = $offer->number;
        $this->publish->refreshSoon(Topics::STAFF, ["/offers/{$n}"]);
        // Списки CRM — строкой машины (`Rows`): в «Сделках» ключ — сделка, у машины их бывает несколько.
        $this->publish->rowsSoon(Topics::STAFF, ['/' => [$n], '/gallery' => [$n], '/work/deals' => self::deals($offer), '/work/garage' => $offer->garageCar ? [$n] : []]);
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

    /** @return list<int> сделки машины — строки «Работы → Сделки» */
    public static function deals(Offer $offer): array
    {
        return Deal::where('offer_id', $offer->id)->pluck('id')->all();
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
