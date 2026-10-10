<?php

namespace App\Offers;

use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Sort;
use App\Users\Role;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Каталог: что видит человек и в каком порядке. Всё состояние — в строке
 * запроса: фильтры, пилюля `view`, сортировка `sort` (минус — по убыванию).
 */
final class CatalogQuery
{
    /**
     * Поля сортировки (App\Support\Sort): в адресе минус — по убыванию. «Новые показы» — когда предложение открылось
     * этому человеку (`openedAtSql`): волна менеджера, показ покупателю.
     */
    public const SORTS = [
        'closing' => ['Закрытие приёма', 'asc'],
        'opened' => ['Новые показы', 'desc'],
        'published' => ['Дата публикации', 'desc'],
        'price' => ['Цена', 'asc'],
        'year' => ['Год', 'desc'],
    ];

    /** «Новые» и «Горящие» — окна по 12 ч (`Offer::NEW_HOURS`, `Offer::HOT_HOURS`), подписи владельца 10.10.2026. */
    public const VIEWS = ['' => 'Все', 'recommended' => 'Рекомендуем', 'fresh' => 'Новые за 12ч', 'ending' => 'Горящие (12ч)', 'favorite' => 'Избранное'];

    /** city и category — чипы (`CatalogQuery::facets`), значения через запятую; марки, года и цены нет (03.10.2026). */
    public const FILTERS = ['city', 'category', 'q', 'view', 'sort'];

    /**
     * Когда предложение открылось этому человеку — SQL-выражение над `offers`. Менеджеру и супер-покупателю — его волна
     * показа (`offer_viewers`), покупателю — первый показ его менеджера (лично или группе) и волна этого менеджера,
     * остальным — публикация. Раньше публикации не бывает. Одно выражение на «Новые», метку, сортировку и бейдж таба.
     */
    public static function openedAtSql(?User $user): string
    {
        if (! $user || $user->isAdmin()) {
            return 'offers.published_at';
        }
        $id = (int) $user->id;
        if ($user->isBuyer() && $user->manager_id) {
            $manager = (int) $user->manager_id;
            $groups = implode(',', array_map('intval', $user->groupIds()));
            $to = $groups === '' ? "s.user_id = {$id}" : "(s.user_id = {$id} or s.group_id in ({$groups}))";

            return "greatest(offers.published_at, (select min(s.created_at) from showings s where s.offer_id = offers.id and s.manager_id = {$manager} and {$to}),"
                ." (select v.opens_at from offer_viewers v where v.offer_id = offers.id and v.user_id = {$manager}))";
        }
        if ($user->hasRole(Role::Manager, Role::SuperBuyer)) {
            return "greatest(offers.published_at, (select v.opens_at from offer_viewers v where v.offer_id = offers.id and v.user_id = {$id}))";
        }

        return 'offers.published_at';
    }

    /** Сортировка по умолчанию (10.10.2026): кто видит срок — сверху закрывающиеся раньше, покупатель — новые показы сверху. */
    public static function defaultSort(?User $user, bool $gallery = false): string
    {
        return match (true) {
            $gallery => '-published',
            $user?->bidsOnSite() ?? false => 'closing',
            default => '-opened',
        };
    }

    public static function for(?User $user, array $filters = [], bool $gallery = false): Builder
    {
        $prices = ! $gallery && ($user?->canSeePrices() ?? false);

        $q = Offer::query()
            // Карточкам нужны только фото и своя закладка: все медиа (с документами) и чужое избранное по
            // каждому предложению поднимались зря; таблице фото не нужны вовсе — их снимает контроллер (`without`).
            ->with(['brand', 'model', 'settlement',
                'media' => fn ($m) => $m->where('collection_name', 'photos'),
                'favorites' => fn ($f) => $f->where('user_id', $user?->id ?? 0)]);
        $opened = self::openedAtSql($user);
        // Когда открылось этому человеку — на метку «новое» (`Offer::openedAt`).
        $q->select('offers.*')->selectRaw("{$opened} as opened_at");
        if (! $gallery && ($user?->bidsOnSite() ?? false)) {
            // «Ваша цена» на карточке — своё действующее подтверждение, тем же правилом, что на странице предложения.
            $q->with(['bids' => fn ($b) => $b->where('user_id', $user->id)->whereIn('state', [BidState::Active, BidState::Accepted])]);
        }
        // Витрина — только то, что в продаже (`Offer::siteList`: открытое без сделки плюс сделка,
        // открытая менеджеру белым списком — с меткой «Идёт сделка»): срок вышел или идёт сделка —
        // с сайта ушло. Галерея — окно показа черновика (`Offer::inGallery`).
        $gallery ? $q->visibleTo($user)->inGallery() : $q->siteList($user);
        if ($user?->isBuyer() || $gallery) {
            // На карточке нужен свой интерес (покупателю, а в галерее и менеджеру) — одним запросом на список.
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
                'fresh' => self::fresh($q, $user, $gallery),
                'ending' => self::ending($q),
                'favorite' => $user ? $q->whereHas('favorites', fn ($f) => $f->where('user_id', $user->id)) : $q->whereRaw('false'),
                default => null,
            };
        }

        $sort = self::sort($filters, $gallery, $prices, $user);
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        match (ltrim($sort, '-')) {
            // В галерее «скоро закроется» — конец окна показа, «свежие» — когда появилось.
            'closing' => $gallery
                ? $q->orderByRaw("gallery_until {$dir} nulls last")
                // Открытый приём — по сроку, закрытый и «Идёт сделка» — после него. Слот закрывает пачку в один час:
                // внутри неё рекомендованные, потом новые показы.
                : $q->orderByRaw("case when offers.state = ? and offers.bids_close_at > now() then 0 else 1 end", [OfferState::Open->value])
                    ->orderByRaw("offers.bids_close_at {$dir} nulls last")->orderByDesc('sort_weight')->orderByRaw("{$opened} desc nulls last"),
            'opened' => $q->orderByRaw("{$opened} {$dir} nulls last"),
            'price' => $q->orderByRaw("asking_price {$dir} nulls last"),
            'year' => $q->orderByRaw("year {$dir} nulls last")->orderByDesc('published_at'),
            default => $q->orderByDesc('sort_weight')->orderByRaw(($gallery ? 'gallery_from' : 'published_at')." {$dir} nulls last"),
        };

        return $q->orderByDesc('id');
    }

    /** Новые за 12 ч: открылось этому человеку недавно; в галерее — появилось в окне показа. */
    public static function fresh(Builder $q, ?User $user, bool $gallery = false): Builder
    {
        $since = now()->subHours(Offer::NEW_HOURS);

        return $gallery ? $q->where('gallery_from', '>=', now()->subDay()) : $q->whereRaw(self::openedAtSql($user).' >= ?', [$since]);
    }

    /** Горящие: приём закрывается в ближайшие 12 ч. */
    public static function ending(Builder $q): Builder
    {
        return $q->where('offers.state', OfferState::Open)->whereBetween('offers.bids_close_at', [now(), now()->addHours(Offer::HOT_HOURS)]);
    }

    /** @return list<Facet> чипы каталога и галереи: город и тип ТС */
    public static function facets(): array
    {
        return [Common::city('offers.settlement_id'), Common::category('offers.vehicle_category')];
    }

    /** Действующая сортировка: то, что в адресе, если она разрешена, иначе по умолчанию. */
    public static function sort(array $filters, bool $gallery = false, bool $prices = true, ?User $user = null): string
    {
        return Sort::from($filters['sort'] ?? null, self::allowedSorts($gallery, $prices, $user), self::defaultSort($user, $gallery))->value();
    }

    /** Сортировки для тулбара: в галерее ни цены, ни срока; покупатель не торгуется — срока у него нет. */
    public static function allowedSorts(bool $gallery, bool $prices, ?User $user = null): array
    {
        // В галерее даты — окна показа: когда появилось и когда закроется.
        $sorts = $gallery ? ['published' => ['Появилось', 'desc'], 'closing' => ['Скоро закроется', 'asc']] + self::SORTS : self::SORTS;

        return array_filter($sorts, fn ($_, $key) => match ($key) {
            'price' => $prices,
            'closing' => $gallery || ! $user?->isBuyer(),
            // Новые показы — у того, кому предложения открываются волной или показом; в галерее своё «Появилось».
            'opened' => ! $gallery && $user !== null && ! $user->isAdmin(),
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
