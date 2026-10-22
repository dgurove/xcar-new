<?php

namespace App\Logistics;

use App\Offers\DealState;
use App\Offers\Offer;
use App\Park\City;
use App\Park\Yard;
use App\Users\Role;
use App\Users\User;

/**
 * Списки окна «Кто вывозит» и «Забрал»: логисты, наши люди, менеджер сделки, исполнители логиста, парковки; регион
 * машины подписью (правила по региону нет — решает админ). Раз на запрос (`once`, Octane сбрасывает между запросами).
 */
final class Choices
{
    /** @return array<int, string> */
    public static function logists(): array
    {
        return once(fn () => User::withRole(Role::Logist)->whereNull('rejected_at')->orderBy('name')->pluck('name', 'id')->all());
    }

    /** Наши люди на вывозе (ООО «ПРАЙМ»). @return array<int, string> */
    public static function staff(): array
    {
        return once(fn () => User::withRole(Role::Driver)->whereNull('rejected_at')->orderBy('name')->pluck('name', 'id')->all());
    }

    /**
     * Свои исполнители (`Channel::Partner`): менеджеры (17.10.2026, владелец) и прямые исполнители XCar — `Role::Contractor`
     * без логиста (21.10.2026: покупатель с гаражом, что возит и чужие машины); все, кроме того, чья это машина (он забирает
     * сам). @return array<int, string>
     */
    public static function partners(Transport|Offer|null $of = null): array
    {
        $own = $of ? self::managerOf($of)?->id : null;

        return array_diff_key(once(fn () => User::query()->whereNull('rejected_at')
            ->where(fn ($q) => $q->withRole(Role::Manager)->orWhere(fn ($c) => $c->withRole(Role::Contractor)->whereNull('logist_id')))
            ->orderBy('name')->pluck('name', 'id')->all()), [$own => true]);
    }

    /** Может ли человек быть своим исполнителем (`Channel::Partner`): менеджер или исполнитель без логиста. */
    public static function partnerable(User $user): bool
    {
        return $user->isManager() || ($user->isContractor() && ! $user->logist_id);
    }

    /**
     * Менеджер, которому мы подтвердили сделку по машине (в гараж или его покупателю): держатель гаража, иначе покупатель
     * идущей сделки. Только он может вывозить из менеджеров (владелец 17.10.2026).
     */
    public static function managerOf(Transport|Offer $of): ?User
    {
        $offer = $of instanceof Offer ? $of : $of->saleOffer();
        if (! $offer) {
            return null;
        }
        // Подгруженное списком (`SaleState::WITH`) — без запросов на строку.
        $garage = $offer->relationLoaded('garageCar') ? $offer->garageCar : $offer->garageCar()->with('manager')->first();
        if ($garage?->manager) {
            return $garage->manager;
        }
        $deal = $offer->relationLoaded('deal') ? $offer->deal : $offer->deal()->where('state', DealState::Active)->with('buyer')->first();

        return $deal?->buyer && ($deal->buyer->isManager() || $deal->buyer->isSuperBuyer()) ? $deal->buyer : null;
    }

    /** Регион машины подписью окна: «Самарская область», «Москва» (колонка задачи, `Transport::regionFor`). */
    public static function regionOf(Transport $t): ?string
    {
        return $t->region?->name;
    }

    /** Парковки — точкой «Куда везёт». @return array<int, string> */
    /** @return array<int, ?int> парковка → её город (`settlements.id`) — «Куда везти» показывает парковки выбранного города */
    public static function yardCities(): array
    {
        return once(fn () => Yard::withoutGlobalScope(City::SCOPE)->pluck('settlement_id', 'id')->all());
    }

    public static function yards(): array
    {
        return once(fn () => Yard::withoutGlobalScope(City::SCOPE)->orderBy('name')->pluck('name', 'id')->all());
    }
}
