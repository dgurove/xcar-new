<?php

namespace App\Support;

use App\Garage\Car as GarageCar;
use App\Logistics\Transport;
use App\Offers\Offer;
use App\Park\Vehicle;
use App\Users\User;

/**
 * Где ещё эта машина — меню значка связи `x-ui.links` (только админу): дело на парковке, предложение в CRM, сделка,
 * гараж, закупка Carcade, вывоз в логистике (18.10.2026, владелец: «всё единая система» — в логистике вместо кнопки
 * «Предложение в CRM» тот же значок). Машина одна (`Cars\Car`), а экранов у неё несколько; меню ведёт на каждый, кроме того, где
 * человек сейчас. Ссылки — с хостом: парковка, CRM и сайт — разные хосты.
 */
final class CarLinks
{
    /**
     * Значок есть, когда машина физически есть ещё где-то: на парковке, в идущей сделке, в гараже. Только админу. ТС
     * парковки считается, как только заявку на приём завели, даже до приёма (владелец 06.10.2026). Закупка значок не
     * зажигает — у Каркаде она у всех (владелец 07.10.2026), её пункт в меню есть, когда значок горит по другой причине.
     * Связь проверяется, загружена она или нет: раньше значок зависел от того, что подгрузил экран.
     */
    public static function shows(Offer|Vehicle|Transport $from, ?User $user): bool
    {
        if (! $user?->isAdmin()) {
            return false;
        }
        // Задача вывоза — машина всегда где-то ещё: в продаже или на парковке (заявка логиста от своего заказчика — нет).
        if ($from instanceof Transport) {
            return ! $from->isOrder() && ($from->offer !== null || $from->vehicle !== null);
        }
        if ($from instanceof Vehicle) {
            return $from->relationLoaded('offer') ? (bool) $from->offer : $from->offer()->exists();
        }
        $has = fn (string $rel) => $from->relationLoaded($rel) ? (bool) $from->getRelation($rel) : $from->{$rel}()->exists();

        return $has('parkVehicle') || $has('deal') || $has('garageCar');
    }

    /** @return list<array{title: string, state: string, href: string}> */
    public static function for(Offer|Vehicle|Transport $from): array
    {
        $offer = match (true) {
            $from instanceof Offer => $from,
            $from instanceof Vehicle => $from->offer,
            default => $from->offer,
        };
        // Списки CRM грузят ТС парковки урезанной (id, категория, даты) — для меню она нужна целиком.
        $vehicle = match (true) {
            $from instanceof Vehicle => $from,
            $from instanceof Transport => $from->vehicle,
            default => $offer?->parkVehicle ? Vehicle::find($offer->parkVehicle->id) : null,
        };
        $links = [];
        if ($vehicle && ! $from instanceof Vehicle) {
            $day = $vehicle->accepted_at ?? $vehicle->created_at;
            $links[] = ['title' => 'Парковка', 'state' => mb_strtolower($vehicle->state->label()).($vehicle->accepted_at ? ' с '.$day->translatedFormat('j M') : ''),
                'href' => Surface::Park->url('/cars/'.$vehicle->id)];
        }
        if (! $offer) {
            return [...$links, ...self::pickup($from)];
        }
        if (! $from instanceof Offer) {
            $links[] = ['title' => 'Продажа', 'state' => $offer->crmTab().', № '.$offer->number, 'href' => Surface::Crm->url('/offers/'.$offer->number)];
        }
        if ($deal = $offer->deal) {
            $links[] = ['title' => 'Сделка', 'state' => $deal->buyer?->name ?? 'идёт', 'href' => Surface::Crm->url('/work/deals/'.$deal->id)];
        }
        if ($car = GarageCar::where('offer_id', $offer->id)->latest('id')->first()) {
            $links[] = ['title' => 'Гараж', 'state' => mb_strtolower($car->state->label()), 'href' => $car->crmUrl()];
        }
        // Связь — загружена или нет, как в `shows`: строки списков её не грузят, меню собирается на строку.
        $purchase = $offer->relationLoaded('purchaseCar') ? $offer->purchaseCar?->loadMissing('purchase') : $offer->purchaseCar()->with('purchase')->first();
        if ($purchase) {
            $links[] = ['title' => 'Закупка', 'state' => '№ '.$purchase->purchase->number.', ДЛ '.$purchase->dl,
                'href' => Surface::Crm->url('/purchases/'.$purchase->purchase->number.'/'.$purchase->ref)];
        }

        return [...$links, ...self::pickup($from)];
    }

    /** Вывоз машины в логистике — последняя задача (у отрезков — текущий); менеджер, что забирает сам, — не логистика. */
    private static function pickup(Offer|Vehicle|Transport $from): array
    {
        if ($from instanceof Transport || ! $from->car_id) {
            return [];
        }
        $t = Transport::query()->inLogistics()->where('car_id', $from->car_id)->where('origin', '<>', 'order')->latest('id')->first();

        return $t ? [['title' => 'Вывоз', 'state' => $t->isOpen() ? $t->next() : mb_strtolower($t->state->label()), 'href' => Surface::Logistics->url('/transports/'.$t->id)]] : [];
    }
}
