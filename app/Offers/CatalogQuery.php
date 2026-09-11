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
    public const PER_PAGE = 24;

    /** ключ → [подпись, есть ли направление] */
    public const SORTS = [
        'published' => ['Дата публикации', true],
        'price' => ['Стоимость', true],
        'year' => ['Год выпуска', true],
        'closing' => ['Скоро закрытие', false],
    ];

    public const VIEWS = ['' => 'Все', 'fresh' => 'Новые', 'ending' => 'Горящие', 'favorite' => 'Избранное'];

    public const FILTERS = ['brand', 'q', 'year_from', 'year_to', 'price_from', 'price_to', 'view', 'sort'];

    public const DEFAULT_SORT = '-published';

    public static function for(?User $user, array $filters = [], bool $gallery = false): Builder
    {
        $prices = ! $gallery && ($user?->role->canSeePrices() ?? false);

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
            'fresh' => $q->where('published_at', '>=', now()->subDay()),
            'ending' => $q->where('state', OfferState::Open)->whereBetween('bids_close_at', [now(), now()->addDay()]),
            'favorite' => $user ? $q->whereHas('favorites', fn ($f) => $f->where('user_id', $user->id)) : $q->whereRaw('false'),
            default => null,
        };

        $sort = self::sort($filters, $gallery, $prices);
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
    public static function sort(array $filters, bool $gallery = false, bool $prices = true): string
    {
        $sort = $filters['sort'] ?? self::DEFAULT_SORT;
        $key = ltrim($sort, '-');

        return isset(self::allowedSorts($gallery, $prices)[$key]) ? $sort : self::DEFAULT_SORT;
    }

    /** Сортировки для тулбара: в галерее ни цены, ни срока. */
    public static function allowedSorts(bool $gallery, bool $prices): array
    {
        return array_filter(self::SORTS, fn ($_, $key) => match ($key) {
            'price' => $prices,
            'closing' => ! $gallery,
            default => true,
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** Пилюли для тулбара: «Горящие» только в каталоге, «Избранное» только вошедшему. */
    public static function allowedViews(?User $user, bool $gallery): array
    {
        return array_filter(self::VIEWS, fn ($_, $key) => match ($key) {
            'ending' => ! $gallery,
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
