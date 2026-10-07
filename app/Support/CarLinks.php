<?php

namespace App\Support;

use App\Garage\Car as GarageCar;
use App\Offers\Offer;
use App\Park\Vehicle;
use App\Users\User;

/**
 * Где ещё эта машина — меню значка связи `x-ui.links` (только админу): дело на парковке, предложение в CRM, сделка,
 * гараж, закупка Carcade. Машина одна (`Park\Sale`), а экранов у неё несколько; меню ведёт на каждый, кроме того, где
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
    public static function shows(Offer|Vehicle $from, ?User $user): bool
    {
        if (! $user?->isAdmin()) {
            return false;
        }
        if ($from instanceof Vehicle) {
            return (bool) $from->offer_id;
        }
        $has = fn (string $rel) => $from->relationLoaded($rel) ? (bool) $from->getRelation($rel) : $from->{$rel}()->exists();

        return $has('parkVehicle') || $has('deal') || $has('garageCar');
    }

    /** @return list<array{title: string, state: string, href: string}> */
    public static function for(Offer|Vehicle $from): array
    {
        $offer = $from instanceof Offer ? $from : $from->offer;
        // Списки CRM грузят ТС парковки урезанной (id, категория, даты) — для меню она нужна целиком.
        $vehicle = $from instanceof Vehicle ? $from : ($offer?->parkVehicle ? Vehicle::find($offer->parkVehicle->id) : null);
        $links = [];
        if ($vehicle && $from instanceof Offer) {
            $day = $vehicle->accepted_at ?? $vehicle->created_at;
            $links[] = ['title' => 'Парковка', 'state' => mb_strtolower($vehicle->state->label()).($vehicle->accepted_at ? ' с '.$day->translatedFormat('j M') : ''),
                'href' => Surface::Park->url('/cars/'.$vehicle->id)];
        }
        if (! $offer) {
            return $links;
        }
        if ($from instanceof Vehicle) {
            $links[] = ['title' => 'Продажа', 'state' => mb_strtolower($offer->state->label()).', № '.$offer->number, 'href' => Surface::Crm->url('/offers/'.$offer->number)];
        }
        if ($deal = $offer->deal) {
            $links[] = ['title' => 'Сделка', 'state' => $deal->buyer?->name ?? 'идёт', 'href' => Surface::Crm->url('/work/deals/'.$deal->id)];
        }
        if ($car = GarageCar::where('offer_id', $offer->id)->latest('id')->first()) {
            $links[] = ['title' => 'Гараж', 'state' => mb_strtolower($car->state->label()), 'href' => $car->crmUrl()];
        }
        if ($purchase = $offer->purchaseCar?->load('purchase')) {
            $links[] = ['title' => 'Закупка', 'state' => '№ '.$purchase->purchase->number.', ДЛ '.$purchase->dl,
                'href' => Surface::Crm->url('/purchases/'.$purchase->purchase->number.'/'.$purchase->ref)];
        }

        return $links;
    }
}
