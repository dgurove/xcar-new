<?php

namespace App\Offers;

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

    public const FILTERS = ['brand', 'q', 'year_from', 'year_to', 'price_from', 'price_to', 'view', 'sort'];

    public const DEFAULT_SORT = '-published';

    public static function for(?User $user, array $filters = [], bool $gallery = false): Builder
    {
        $prices = ! $gallery && ($user?->role->canSeePrices() ?? false);

        $q = Offer::query()
            ->with(['brand', 'model', 'settlement', 'media', 'favorites'])
            ->visibleTo($user)
            ->whereIn('state', $gallery ? [OfferState::Gallery] : [OfferState::Open]);
        if ($user?->isBuyer()) {
            // Покупателю на карточке нужен его собственный интерес — грузим одним запросом на список.
            $q->with(['interests' => fn ($i) => $i->where('user_id', $user->id)]);
        }

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
        if (! empty($filters['year_from'])) {
            $q->where('year', '>=', (int) $filters['year_from']);
        }
        if (! empty($filters['year_to'])) {
            $q->where('year', '<=', (int) $filters['year_to']);
        }
        if ($prices && ! empty($filters['price_from'])) {
            $q->where('asking_price', '>=', (int) preg_replace('/\D/', '', $filters['price_from']));
        }
        if ($prices && ! empty($filters['price_to'])) {
            $q->where('asking_price', '<=', (int) preg_replace('/\D/', '', $filters['price_to']));
        }

        match ($filters['view'] ?? '') {
            'recommended' => $q->where('recommended', true),
            'fresh' => $q->where('published_at', '>=', now()->subDay()),
            'ending' => $q->where('state', OfferState::Open)->whereBetween('bids_close_at', [now(), now()->addDay()]),
            'favorite' => $user ? $q->whereHas('favorites', fn ($f) => $f->where('user_id', $user->id)) : $q->whereRaw('false'),
            default => null,
        };

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
