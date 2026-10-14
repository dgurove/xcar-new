<?php

namespace App\Cars;

use App\Offers\Offer;
use App\Offers\OfferEvent;
use App\Offers\OfferEventType;
use App\Park\City;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Park\VehicleEvent;
use App\Support\Changes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Правки машины с другой дорожки — в «Истории» этой (15.10.2026). Машина одна (`Cars\Car`): VIN, поправленный в CRM, —
 * правка и дела ТС, цвет, вписанный на парковке, — и предложения. Своя запись у каждой правки одна — в истории той
 * дорожки, где правили (`UpdateOffer`, `UpdateVehicle`, «было → стало»), а вторая история её читает: только поля машины,
 * именами своей дорожки; цены, срок приёма и прочее продажи на парковку не попадают. До выкладки объединения
 * (`SINCE`) правку CRM в дело переносило зеркало `Sale::toVehicle` своей строкой — те записи не берём, иначе двойные.
 */
final class LaneHistory
{
    /** Выкладка объединения машины (`dg-20261014-224806`). */
    public const SINCE = '2026-10-14 22:48:00';

    /** Правки CRM для дела ТС. @return Collection<int, LaneEdit> */
    public static function forVehicle(Vehicle $vehicle): Collection
    {
        $offerIds = Offer::withoutGlobalScopes()->where('car_id', $vehicle->car_id)->pluck('id');
        if ($offerIds->isEmpty()) {
            return collect();
        }

        return OfferEvent::with('user')->whereIn('offer_id', $offerIds)->where('type', OfferEventType::Updated)
            ->where('created_at', '>=', Carbon::parse(self::SINCE))->whereNotNull('payload->changes')->latest()->latest('id')->get()
            ->map(function (OfferEvent $e) {
                $p = $e->payload ?? [];
                [$fields, $changes] = self::rename($p, Offer::class, Vehicle::class);

                return $fields ? new LaneEdit($e->created_at, $e->user, 'в CRM', self::prefix($p, 'Изменена').Changes::text($fields, $changes)) : null;
            })->filter()->values();
    }

    /** Правки парковки для предложения. @return Collection<int, LaneEdit> */
    public static function forOffer(Offer $offer): Collection
    {
        $vehicleIds = Vehicle::withoutGlobalScope(City::SCOPE)->where('car_id', $offer->car_id)->pluck('id');
        if ($vehicleIds->isEmpty()) {
            return collect();
        }

        return VehicleEvent::with('user')->whereIn('vehicle_id', $vehicleIds)->whereIn('type', [EventType::Updated, EventType::FilledFromDocs])
            ->where('created_at', '>=', Carbon::parse(self::SINCE))->latest()->latest('id')->get()
            ->map(function (VehicleEvent $e) {
                $p = $e->payload ?? [];
                [$fields, $changes] = self::rename($p, Vehicle::class, Offer::class);
                $prefix = $e->type === EventType::FilledFromDocs
                    ? (! empty($p['sources']) ? 'Из документов '.implode(', ', array_map(fn ($s) => '«'.$s.'»', $p['sources'])) : 'Из документов').': '
                    : self::prefix($p, 'Изменён');

                return $fields ? new LaneEdit($e->created_at, $e->user, 'на парковке', $prefix.Changes::text($fields, $changes)) : null;
            })->filter()->values();
    }

    /**
     * Своя лента и правки с другой дорожки — одной лентой, новые сверху.
     *
     * @return Collection<int, mixed>
     */
    public static function merge(Collection $own, Collection $foreign): Collection
    {
        return $foreign->isEmpty() ? $own : $own->concat($foreign)->sortByDesc(fn ($e) => $e->created_at->getTimestamp())->values();
    }

    /**
     * Поля машины из записи одной дорожки — именами другой: имя → колонка машины (`carMap`) → имя у другой. Поле дорожки
     * (цена, срок, парковка) и то, чего у другой нет (у парковки `fuel` — уровень топлива), отбрасываются.
     *
     * @param  class-string  $from
     * @param  class-string  $to
     * @return array{0: list<string>, 1: array<string, array{0: mixed, 1: mixed}>}
     */
    private static function rename(array $payload, string $from, string $to): array
    {
        $columns = $from::carMap();
        $names = array_flip($to::carMap());
        $map = [];
        foreach ($payload['fields'] ?? array_keys($payload['changes'] ?? []) as $field) {
            $column = $columns[$field] ?? null;
            if ($column && ! in_array($column, Car::DERIVED, true) && isset($names[$column])) {
                $map[$field] = $names[$column];
            }
        }
        $changes = [];
        foreach ($map as $field => $name) {
            if (array_key_exists($field, $payload['changes'] ?? [])) {
                $changes[$name] = $payload['changes'][$field];
            }
        }

        return [array_values($map), $changes];
    }

    /** Откуда правка — тем же языком, что своя строка: «По СМС …:», «С Мигторга:», иначе «Изменена:». */
    private static function prefix(array $p, string $changed): string
    {
        return match (true) {
            ! empty($p['why']) => $p['why'].': ',
            ($p['source'] ?? null) === 'migtorg' => 'С Мигторга: ',
            ($p['source'] ?? null) === 'valuation' => 'Оценка из текста: ',
            ($p['source'] ?? null) === 'file' => 'По файлу'.(! empty($p['file']) ? ' «'.$p['file'].'»' : '').': ',
            default => $changed.': ',
        };
    }
}
