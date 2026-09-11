<?php

namespace App\Offers;

use App\Users\User;
use Illuminate\Database\Eloquent\Builder;

/** Каталог: что видит человек и в каком порядке. Фильтры — из строки запроса. */
final class CatalogQuery
{
    public const SORTS = ['fresh' => 'Сначала новые', 'closing' => 'Скоро закроются', 'price_asc' => 'Дешевле', 'price_desc' => 'Дороже'];

    public static function for(?User $user, array $filters = [], bool $gallery = false): Builder
    {
        $q = Offer::query()
            ->with(['brand', 'model', 'settlement', 'media', 'favorites'])
            ->whereIn('state', $gallery ? [OfferState::Gallery] : [OfferState::Open, OfferState::Closed]);

        if (! empty($filters['brand'])) {
            $q->whereHas('brand', fn ($b) => $b->where('slug', $filters['brand']));
        }
        if (! empty($filters['q'])) {
            $term = '%'.mb_strtolower(trim($filters['q'])).'%';
            $q->where(fn ($w) => $w
                ->whereHas('brand', fn ($b) => $b->whereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(name_ru) like ?', [$term]))
                ->orWhereHas('model', fn ($m) => $m->whereRaw('lower(name) like ?', [$term]))
                ->orWhereRaw('cast(number as text) like ?', [$term])
                ->orWhereRaw('lower(vin) like ?', [$term]));
        }
        if (($filters['open'] ?? null) === '1') {
            $q->where('state', OfferState::Open);
        }

        match ($filters['sort'] ?? 'fresh') {
            'closing' => $q->orderByRaw('bids_close_at asc nulls last'),
            'price_asc' => $q->orderByRaw('asking_price asc nulls last'),
            'price_desc' => $q->orderByRaw('asking_price desc nulls last'),
            default => $q->orderByDesc('sort_weight')->orderByDesc('published_at'),
        };

        return $q->orderByDesc('id');
    }
}
