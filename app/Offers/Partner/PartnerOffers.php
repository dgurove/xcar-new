<?php

namespace App\Offers\Partner;

use App\Http\Admin\OfferController;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Что поставщику открыто на partner.xcar.ru (07.11.2026) — одна дверь: список, лупа, число таба, редактор, фото и
 * документы. Черновики его вендора без закупочной (условие вкладки CRM «Без закупочной цены», `OfferController::scopeFor`),
 * не из закупки и ни разу не бывшие в сделке или гараже. Вписал цену — предложение ушло к нам, и ему оно больше не открыто.
 */
final class PartnerOffers
{
    public static function for(User $user): Builder
    {
        return self::scope(Offer::query(), $user);
    }

    public static function scope(Builder $q, User $user): Builder
    {
        if (! $user->isSupplier() || ! $user->vendor_id) {
            return $q->whereRaw('false');
        }
        self::eligible($q->where('offers.vendor_id', $user->vendor_id));

        return OfferController::scopeFor('nofloor', $q, admin: false);
    }

    /**
     * Что вообще может дойти до поставщика, в любом состоянии: не из закупки, ни разу не бывшее в сделке или гараже, не на
     * аукционе. Им же проверяется возврат из архива (`FindClaim`): иначе вернули бы в черновики то, что ему всё равно не открыть.
     */
    public static function eligible(Builder $q): Builder
    {
        return $q->whereDoesntHave('purchaseCar')->whereNull('offers.auction_at')
            ->whereNotExists(fn ($e) => $e->from('deals')->whereColumn('deals.offer_id', 'offers.id'))
            ->whereNotExists(fn ($e) => $e->from('garage_cars')->whereColumn('garage_cars.offer_id', 'offers.id'));
    }

    /** Вендоры, у которых есть поставщики (живые обновления их списков): пять минут в кэше. @return list<int> */
    public static function vendorIds(): array
    {
        return once(fn () => Cache::remember('partner:vendors', 300, fn () => User::withRole(Role::Supplier)->whereNotNull('vendor_id')
            ->distinct()->pluck('vendor_id')->map(fn ($id) => (int) $id)->all()));
    }

    /** Открыто ли это предложение поставщику. */
    public static function opens(User $user, Offer $offer): bool
    {
        return self::for($user)->whereKey($offer->id)->exists();
    }

    /**
     * Черновик у поставщика: завёл он и цену ещё не отдал. Пока так, автоматика его не трогает — ни поля, ни кадры сами не
     * появляются (`Offer::heldByPartner`); отдал — черновик наш, и всё работает как обычно.
     */
    public static function held(Offer $offer): bool
    {
        return $offer->created_via === 'partner' && $offer->state === OfferState::Draft && $offer->slot_at === null
            && ! ($offer->floor_price > 0) && ! ($offer->asking_price > 0);
    }
}
