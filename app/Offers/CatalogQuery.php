<?php

namespace App\Offers;

use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Каталог: что видит человек и в каком порядке. Всё состояние — в строке
 * запроса: фильтры, пилюля `view`, сортировка `sort` (минус — по убыванию).
 */
final class CatalogQuery
{
    /** ключ → [подпись, есть ли направление] */
    /** Исходы словами, по ключу на исход: минус — по убыванию. Стрелки-переключателя направления нет. */
    public const SORTS = [
        '-published' => 'Сначала новые',
        'published' => 'Сначала старые',
        'price' => 'Сначала дешёвые',
        '-price' => 'Сначала дорогие',
        '-year' => 'Сначала свежий год',
        'year' => 'Сначала старый год',
        'closing' => 'Скоро закрытие',
    ];

    public const VIEWS = ['' => 'Все', 'recommended' => 'Рекомендуем', 'fresh' => 'Новые', 'ending' => 'Горящие', 'favorite' => 'Избранное'];

    /** city и category — чипы (`CatalogQuery::facets`), значения через запятую; марки, года и цены нет (03.10.2026). */
    public const FILTERS = ['city', 'category', 'q', 'view', 'sort'];

    public const DEFAULT_SORT = '-published';

    public static function for(?User $user, array $filters = [], bool $gallery = false): Builder
    {
        $prices = ! $gallery && ($user?->canSeePrices() ?? false);

        $q = Offer::query()
            // Карточкам нужны только фото и своя закладка: все медиа (с документами) и чужое избранное по
            // каждому предложению поднимались зря; таблице фото не нужны вовсе — их снимает контроллер (`without`).
            ->with(['brand', 'model', 'settlement',
                'media' => fn ($m) => $m->where('collection_name', 'photos'),
                'favorites' => fn ($f) => $f->where('user_id', $user?->id ?? 0)])
            ->visibleTo($user);
        // Витрина — только то, что в продаже: срок вышел или идёт сделка — с сайта ушло (`Offer::scopeOnSale`).
        $gallery ? $q->where('state', OfferState::Gallery) : $q->onSale();
        if ($user?->isBuyer()) {
            // Покупателю на карточке нужен его собственный интерес — грузим одним запросом на список.
            $q->with(['interests' => fn ($i) => $i->where('user_id', $user->id)]);
        }

        // Чипы — тем же правилом, что в списке (`facets`): стрелки на странице предложения идут по тому же ряду.
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search === '') {
            foreach (self::facets() as $facet) {
                $facet->constrain($q, array_values(array_filter(explode(',', (string) ($filters[$facet->key] ?? '')))));
            }
        }
        // Лупа — по всему разделу, мимо пилюли и чипов.
        if ($search !== '') {
            $q->search($search);
        }

        if ($search === '') {
            match ($filters['view'] ?? '') {
                'recommended' => $q->where('recommended', true),
                'fresh' => $q->where('published_at', '>=', now()->subDay()),
                'ending' => $q->where('state', OfferState::Open)->whereBetween('bids_close_at', [now(), now()->addDay()]),
                'favorite' => $user ? $q->whereHas('favorites', fn ($f) => $f->where('user_id', $user->id)) : $q->whereRaw('false'),
                default => null,
            };
        }

        $sort = self::sort($filters, $gallery, $prices, $user);
        $desc = str_starts_with($sort, '-');
        $dir = $desc ? 'desc' : 'asc';
        match (ltrim($sort, '-')) {
            'closing' => $q->orderByRaw('bids_close_at asc nulls last'),
            'price' => $q->orderByRaw("asking_price {$dir} nulls last"),
            'year' => $q->orderByRaw("year {$dir} nulls last")->orderByDesc('published_at'),
            default => $q->orderByDesc('sort_weight')->orderByRaw("published_at {$dir} nulls last"),
        };

        return $q->orderByDesc('id');
    }

    /** @return list<Facet> чипы каталога и галереи: город и тип ТС */
    public static function facets(): array
    {
        return [Common::city('offers.settlement_id'), Common::category('offers.vehicle_category')];
    }

    /** Действующая сортировка: то, что в адресе, если она разрешена, иначе по умолчанию. */
    public static function sort(array $filters, bool $gallery = false, bool $prices = true, ?User $user = null): string
    {
        $sort = $filters['sort'] ?? self::DEFAULT_SORT;

        return isset(self::allowedSorts($gallery, $prices, $user)[$sort]) ? $sort : self::DEFAULT_SORT;
    }

    /** Сортировки для тулбара: в галерее ни цены, ни срока; покупатель не торгуется — срока у него нет. */
    public static function allowedSorts(bool $gallery, bool $prices, ?User $user = null): array
    {
        return array_filter(self::SORTS, fn ($_, $key) => match (ltrim($key, '-')) {
            'price' => $prices,
            'closing' => ! $gallery && ! $user?->isBuyer(),
            default => true,
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** Пилюли для тулбара: «Рекомендуем» пока есть отмеченные, «Горящие» только в каталоге и не покупателю, «Избранное» только вошедшему. */
    public static function allowedViews(?User $user, bool $gallery, int $recommended = 0): array
    {
        return array_filter(self::VIEWS, fn ($_, $key) => match ($key) {
            'recommended' => $recommended > 0,
            'ending' => ! $gallery && ! $user?->isBuyer(),
            'favorite' => $user !== null,
            default => true,
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Место оффера в выдаче с теми же фильтрами: номера соседей, позиция и
     * размер списка — для стрелок на странице оффера.
     *
     * @return array{prev: ?int, next: ?int, index: ?int, total: int}
     */
    public static function position(?User $user, array $filters, bool $gallery, Offer $offer): array
    {
        $numbers = self::for($user, $filters, $gallery)->pluck('number')->all();
        $i = array_search($offer->number, $numbers, true);
        if ($i === false) {
            return ['prev' => null, 'next' => null, 'index' => null, 'total' => count($numbers)];
        }

        return [
            'prev' => $numbers[$i - 1] ?? null,
            'next' => $numbers[$i + 1] ?? null,
            'index' => $i + 1,
            'total' => count($numbers),
        ];
    }
}
