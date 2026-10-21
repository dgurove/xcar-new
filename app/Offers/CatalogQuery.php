<?php

namespace App\Offers;

use App\Cars\Car;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Sort;
use App\Users\Role;
use App\Users\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

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

    /** «Новые» за 24 ч и «Горящие» за 12 ч (`Offer::NEW_HOURS`, `Offer::HOT_HOURS`); подписи без часов — владелец 12.10.2026. */
    public const VIEWS = ['' => 'Все', 'urgent' => 'Срочные', 'recommended' => 'Рекомендуем', 'fresh' => 'Новые', 'ending' => 'Горящие', 'favorite' => 'Избранное'];

    /**
     * city и category — чипы (`CatalogQuery::facets`), значения через запятую; марки, года и цены нет (03.10.2026).
     * unread — «Не просмотренные» (`unreadFacet`): в адресе только выключенный `0`, в ссылках на предложение — и `1`.
     */
    public const FILTERS = ['city', 'category', 'unread', 'q', 'view', 'sort'];

    /** Заход в каталог кончается после получаса без него: открытое тогда уже прячется. */
    private const VISIT_MINUTES = 30;

    /**
     * Когда предложение открылось этому человеку — SQL-выражение над `offers`. Менеджеру и супер-покупателю — его волна
     * показа (`offer_viewers`), покупателю — волна его менеджера, а в режиме «выбранные» ещё и первый показ (лично или группе),
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
            if ($user->buyerSeesAll()) {
                return "greatest(offers.published_at, (select v.opens_at from offer_viewers v where v.offer_id = offers.id and v.user_id = {$manager}))";
            }
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

    /** Предложению, взятому не из каталога (карточка рядом со списком), — когда оно открылось этому человеку: метка «новый» та же, что в ленте. */
    public static function withOpenedAt(Offer $offer, ?User $user): Offer
    {
        $offer->setRawAttributes($offer->getAttributes() + ['opened_at' => Offer::whereKey($offer->id)->selectRaw(self::openedAtSql($user).' as o')->value('o')], true);

        return $offer;
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

    /**
     * Что нужно карточке витрины: фото, своя закладка, когда открылось этому человеку (`opened_at`), открывал ли он его
     * (`viewed`), своё подтверждение
     * и свой интерес — одним запросом на список. Каталог и живое обновление карточки (`Live\FragmentController`)
     * берут одно и то же: иначе после обновления пропадали полоса «новое» и «Ваша цена».
     */
    public static function cards(?User $user, bool $gallery = false): Builder
    {
        $q = Offer::query()
            // Карточкам нужны только фото и своя закладка: все медиа (с документами) и чужое избранное по
            // каждому предложению поднимались зря; таблице фото не нужны вовсе — их снимает контроллер (`without`).
            ->with(['settlement',
                'media' => fn ($m) => $m->where('collection_name', 'photos'),
                'favorites' => fn ($f) => $f->where('user_id', $user?->id ?? 0)])
            ->select('offers.*')->selectRaw(self::openedAtSql($user).' as opened_at');
        if ($user) {
            // «Просмотрено» — открывал ли он предложение (`ReadOffer`): подзапрос по ключу пары, без чтения таблицы.
            // Вернулось в продажу после просмотра — снова не просмотрено (`unread`).
            $q->selectRaw("exists(select 1 from offer_reads r where r.user_id = ? and r.offer_id = offers.id and r.read_at >= coalesce(offers.published_at, '-infinity')) as viewed", [$user->id]);
        }
        if (! $gallery && ($user?->bidsOnSite() ?? false)) {
            // «Ваша цена» на карточке — своё действующее подтверждение, тем же правилом, что на странице предложения.
            $q->with(['bids' => fn ($b) => $b->where('user_id', $user->id)->whereIn('state', [BidState::Active, BidState::Accepted])]);
        }
        if ($user && ($user->isBuyer() || $gallery)) {
            // На карточке нужен свой интерес (покупателю, а в галерее и менеджеру).
            $q->with(['interests' => fn ($i) => $i->where('user_id', $user->id)]);
        }

        return $q;
    }

    /** Пачки по сроку (`CloseGroups`): в каталоге — тому, кто видит срок приёма; в галерее — всем (окно показа). */
    public static function grouped(?User $user, bool $gallery): bool
    {
        return $gallery || ($user?->bidsOnSite() ?? false);
    }

    /** @param  ?CarbonInterface  $since  начало захода в каталог — для «Не просмотренных» (`since`) */
    public static function for(?User $user, array $filters = [], bool $gallery = false, ?CarbonInterface $since = null): Builder
    {
        $prices = ! $gallery && ($user?->canSeePrices() ?? false);
        $q = self::cards($user, $gallery);
        $opened = self::openedAtSql($user);
        // Витрина — только то, что в продаже (`Offer::siteList`: открытое без сделки плюс сделка,
        // открытая менеджеру белым списком — с меткой «Идёт сделка»): срок вышел или идёт сделка —
        // с сайта ушло. Галерея — окно показа черновика (`Offer::inGallery`).
        $gallery ? $q->visibleTo($user)->inGallery() : $q->siteList($user);

        // Чипы — тем же правилом, что в списке (`facets`): стрелки на странице предложения идут по тому же ряду.
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search === '') {
            foreach (self::facets() as $facet) {
                $facet->constrain($q, array_values(array_filter(explode(',', (string) ($filters[$facet->key] ?? '')))));
            }
            // Список каталога кладёт его чипом (`unreadFacet`), стрелкам страницы предложения он приходит в адресе.
            if (! $gallery && $user && ($filters['unread'] ?? null) === '1') {
                self::unread($q, $user, $since);
            }
        }
        // Лупа — по всему разделу, мимо пилюли и чипов.
        if ($search !== '') {
            $q->search($search);
        }

        if ($search === '') {
            match ($filters['view'] ?? '') {
                'urgent' => $q->urgent(),
                'recommended' => $q->where('recommended', true),
                'fresh' => self::fresh($q, $user, $gallery),
                'ending' => self::ending($q),
                'favorite' => $user ? $q->whereHas('favorites', fn ($f) => $f->where('user_id', $user->id)) : $q->whereRaw('false'),
                default => null,
            };
        }

        $sort = self::sort($filters, $gallery, $prices, $user);
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        // Пачки по сроку при любой сортировке (11.10.2026, владелец): сначала порядок пачек — каталог: открытый приём по
        // сроку, закрытый и «Идёт сделка» после; галерея — по концу окна показа. Выбранная сортировка — внутри пачки;
        // «по сроку» по убыванию переворачивает и пачки.
        if (self::grouped($user, $gallery)) {
            $packs = ltrim($sort, '-') === 'closing' ? $dir : 'asc';
            $gallery
                ? $q->orderByRaw("offers.gallery_until {$packs} nulls last")
                : $q->orderByRaw('case when offers.state = ? and offers.bids_close_at > now() then 0 else 1 end', [OfferState::Open->value])
                    ->orderByRaw("offers.bids_close_at {$packs} nulls last");
        }
        match (ltrim($sort, '-')) {
            // Внутри пачки слота (или окна галереи) — рекомендованные, потом новые показы.
            'closing' => $gallery
                ? $q->orderByRaw("gallery_until {$dir} nulls last")->orderByDesc('sort_weight')->orderByRaw("{$opened} desc nulls last")
                : $q->orderByRaw('case when offers.state = ? and offers.bids_close_at > now() then 0 else 1 end', [OfferState::Open->value])
                    ->orderByRaw("offers.bids_close_at {$dir} nulls last")->orderByDesc('sort_weight')->orderByRaw("{$opened} desc nulls last"),
            'opened' => $q->orderByRaw("{$opened} {$dir} nulls last"),
            'price' => $q->orderByRaw("asking_price {$dir} nulls last"),
            'year' => $q->orderByRaw(Car::sql('offers', 'year')." {$dir} nulls last")->orderByDesc('published_at'),
            default => $q->orderByDesc('sort_weight')->orderByRaw(($gallery ? 'gallery_from' : 'published_at')." {$dir} nulls last"),
        };

        return $q->orderByDesc('id');
    }

    /** Новые за 24 ч: открылось этому человеку недавно; в галерее — окно показа открылось за 24 ч (пилюля та же). */
    public static function fresh(Builder $q, ?User $user, bool $gallery = false): Builder
    {
        $since = now()->subHours(Offer::NEW_HOURS);

        return $gallery ? $q->where('offers.gallery_from', '>=', $since) : $q->whereRaw(self::openedAtSql($user).' >= ?', [$since]);
    }

    /**
     * Не просмотренные (21.10.2026, владелец: «менеджеры не видят, когда приходят новые машины»): человек не открывал
     * предложение с последней публикации. Открытое за этот заход (`$since`) остаётся с меткой «Просмотрено» — под пальцем
     * ничего не пропадает, и стрелки страницы предложения идут тем же рядом; в следующий заход его уже нет.
     */
    public static function unread(Builder $q, User $user, ?CarbonInterface $since = null): Builder
    {
        return $q->whereNotExists(fn (QueryBuilder $r) => $r->selectRaw('1')->from('offer_reads as r')
            ->whereColumn('r.offer_id', 'offers.id')->where('r.user_id', $user->id)
            ->whereRaw("r.read_at >= coalesce(offers.published_at, '-infinity')")
            ->when($since, fn (QueryBuilder $w) => $w->where('r.read_at', '<', $since)));
    }

    /** Чип «Не просмотренные»: включён каждый заход, выключают на этот раз (`?unread=0`), не запоминается. */
    public static function unreadFacet(User $user, CarbonInterface $since): Facet
    {
        return Facet::toggle('unread', 'Не просмотренные', fn (Builder $q) => self::unread($q, $user, $since))->byDefault()->countSelf();
    }

    /**
     * Начало захода в каталог — в сессии. Новый заход (`$enter`, сам список) — пришли не из каталога и не со страницы
     * предложения (Referer) или полчаса не заходили; страницы списка, живые перечитывания и шторки — тот же заход.
     */
    public static function since(Request $request, bool $enter = false): CarbonInterface
    {
        $session = $request->hasSession() ? $request->session() : null;
        $at = $session?->get('catalog.since');
        $seen = $session?->get('catalog.seen');
        $from = (string) parse_url((string) $request->header('Referer'), PHP_URL_PATH);
        $inside = $from === '/offers' || str_starts_with($from, '/offers/');
        $stale = ! $at || ! $seen || now()->subMinutes(self::VISIT_MINUTES)->getTimestamp() > $seen;
        if ($stale || ($enter && ! $inside && ! $request->has('page'))) {
            $at = now()->getTimestamp();
        }
        $session?->put(['catalog.since' => $at, 'catalog.seen' => now()->getTimestamp()]);

        return now()->setTimestamp($at)->startOfSecond();
    }

    /** Горящие: приём закрывается в ближайшие 12 ч. */
    public static function ending(Builder $q): Builder
    {
        return $q->where('offers.state', OfferState::Open)->whereBetween('offers.bids_close_at', [now(), now()->addHours(Offer::HOT_HOURS)]);
    }

    /** @return list<Facet> чипы каталога и галереи: город и тип ТС */
    public static function facets(): array
    {
        return [Common::city('offers.settlement_id'), Common::category(Car::sql('offers', 'category'))];
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
    public static function allowedViews(?User $user, bool $gallery, int $recommended = 0, int $urgent = 0): array
    {
        return array_filter(self::VIEWS, fn ($_, $key) => match ($key) {
            // «Срочные» (12.10.2026) — пока такие есть.
            'urgent' => ! $gallery && $urgent > 0,
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
    public static function position(?User $user, array $filters, bool $gallery, Offer $offer, ?CarbonInterface $since = null): array
    {
        $numbers = self::for($user, $filters, $gallery, $since)->pluck('number')->all();
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
