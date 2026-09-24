<?php

namespace App\Support;

use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Payment;
use App\Billing\PaymentState;
use App\Chats\Chat;
use App\Http\Middleware\MarkInstalled;
use App\Mail\Boxes;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Favorite;
use App\Offers\Interest;
use App\Offers\InterestState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Purchases\Purchase;
use App\Purchases\PurchaseState;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Position;
use App\Workflow\Requirement;
use App\Workflow\WaitsFor;
use Illuminate\Support\Facades\Cache;

/**
 * Навигация одним списком на роль и поверхность: из него строятся капсулы
 * второго ряда шапки (десктоп) и таб-бар (телефон, пункты с tab=true плюс
 * «Кабинет»). Счётчики — отдельно, их перечитывает live.
 */
final class Nav
{
    /** @return list<array{label: string, href: string, match: string|list<string>, tab: bool, capsule: bool}> */
    public static function sections(?User $user, ?Surface $surface = null): array
    {
        $surface ??= Surface::current();

        if ($surface === Surface::Park) {
            return array_values(array_filter([
                self::item('Заявки', '/', ['/', '/requests']),
                self::item('Наличие', '/cars'),
                $user?->canManagePark() ? self::item('Деньги', '/money') : null,
                self::item('Почта', '/mail'),
                self::item('Парковки', '/yards', tab: false),
                self::item('Вендоры', '/vendors', tab: false),
            ]));
        }

        if ($surface === Surface::Crm) {
            return [
                self::item('Предложения', '/', ['/', '/offers']),
                self::item('Галерея', '/gallery'),
                self::item('Работа', '/work'),
                self::item('Закупки', '/purchases'),
                // Кабинет CRM — это «Настройки»: профиль первой пилюлей, уведомления — под /account. В шапке ПК его
                // ведёт аватар, на телефоне — последний таб: отдельной капсулой он был бы вторым входом рядом.
                self::item('Настройки', '/settings', ['/settings', '/account'], tab: false, capsule: false),
            ];
        }

        if ($user?->isStaff()) {
            return [
                self::item('Предложения', '/', ['/', '/offers']),
                self::item('Галерея', '/gallery'),
                self::item('Закупки', '/purchases'),
                self::item('Уведомления', '/account/notifications', capsule: false),
            ];
        }

        // Менеджеру предложения пока закрыты (временно, по просьбе руководства): главная уводит в закупки.
        if ($user?->role === Role::Manager) {
            return [
                self::item('Галерея', '/gallery', tab: false),
                self::item('Закупки', '/purchases'),
                self::item('Покупатели', '/account/buyers', ['/account/buyers', '/account/interest', '/account/invites']),
                self::item('Сделки', '/account/deals', tab: false),
                self::item('Уведомления', '/account/notifications', capsule: false),
            ];
        }

        // Покупатель: только то, что открыл менеджер, его интерес и уведомления.
        if ($user?->isBuyer()) {
            return [
                self::item('Предложения', '/', ['/', '/offers']),
                self::item('Интерес', '/account/interests'),
                self::item('Уведомления', '/account/notifications', capsule: false),
            ];
        }

        if ($user?->isApproved()) {
            return [
                self::item('Предложения', '/', ['/', '/offers']),
                self::item('Галерея', '/gallery'),
                self::item('Избранное', '/account/favorites', capsule: false),
                self::item('Уведомления', '/account/notifications', capsule: false),
            ];
        }

        // Сайт закрыт: гостю и ждущему решения — только обращение.
        return [];
    }

    /**
     * Пункты таб-бара: до четырёх разделов и «Кабинет». Кабинет горит и на экранах своего меню
     * (Вендоры, Сделки, Тарифы — на телефоне они живут в кабинете, а не в таб-баре).
     */
    public static function tabs(?User $user, ?Surface $surface = null): array
    {
        $surface ??= Surface::current();
        $tabs = self::sectionTabs($user, $surface);
        if ($surface === Surface::Site && $user && ! $user->isApproved()) {
            return [self::item('Контакты', '/contacts'), self::item('Выйти', '/logout', capsule: false) + ['logout' => true]];
        }
        if (! $user) {
            return [...$tabs, self::item('Войти', '/login')];
        }
        $root = self::cabinetRoot($surface);
        $match = [$root, ...($surface === Surface::Crm ? ['/account'] : [])];
        foreach (self::cabinetFor($user, 'phone', $surface) as $links) {
            foreach ($links as $l) {
                if (! str_starts_with($l['href'], 'http')) {
                    array_push($match, $l['href'], ...$l['also']);
                }
            }
        }
        $tabs[] = self::item($surface === Surface::Crm ? 'Настройки' : 'Кабинет', $root, array_values(array_unique($match)));

        return $tabs;
    }

    /** Разделы таб-бара без последнего пункта — первые четыре с tab=true. */
    private static function sectionTabs(?User $user, Surface $surface): array
    {
        return array_slice(array_values(array_filter(self::sections($user, $surface), fn ($i) => $i['tab'])), 0, 4);
    }

    /** Корень кабинета: в CRM это «Настройки». */
    public static function cabinetRoot(?Surface $surface = null): string
    {
        return ($surface ?? Surface::current()) === Surface::Crm ? '/settings' : '/account';
    }

    /** Капсулы второго ряда шапки. */
    public static function capsules(?User $user, ?Surface $surface = null): array
    {
        return array_values(array_filter(self::sections($user, $surface), fn ($i) => $i['capsule']));
    }

    /** Верхний ряд капсул: справочные страницы сайта; с CRM и стоянки — переходы на соседние приложения. */
    public static function top(?User $user, ?Surface $surface = null): array
    {
        $surface ??= Surface::current();

        if ($surface === Surface::Park) {
            return [self::item('На сайт', Surface::Site->url())];
        }

        if ($surface === Surface::Crm) {
            return [
                self::item('На сайт', Surface::Site->url()),
                self::item('Парковка', Surface::Park->url()),
            ];
        }

        if (! $user?->isApproved()) {
            return [self::item('Контакты', '/contacts')];
        }
        $top = [
            self::item('Вопросы', '/faq'),
            self::item('Контакты', '/contacts'),
        ];

        // Ссылок в CRM с сайта нет — владелец: сайт для покупателей, сотрудники ходят по своему адресу.
        return $top;
    }

    /**
     * Пилюли кабинета группами.
     *
     * @return array<string, list<array{label: string, href: string, match: string, also: list<string>}>>
     */
    public static function cabinet(User $user, ?Surface $surface = null): array
    {
        $surface ??= Surface::current();

        if ($surface === Surface::Park) {
            return ['' => array_values(array_filter([
                self::link('Профиль', '/account', exact: true),
                self::link('Вендоры', '/vendors'),
                self::link('Тарифы', '/tariffs'),
                self::link('Парковки', '/yards'),
                self::link('Уведомления', '/account/notifications'),
                // В CRM бывают не все: у сотрудника только стоянки чужой хост — стена.
                $user->isStaff() ? self::link('Шаблоны', Surface::Crm->url('/settings/templates')) : null,
            ]))];
        }

        if ($surface === Surface::Crm) {
            // Один раздел «Настройки»: профиль — его первый пункт, как /account на сайте.
            return [
                '' => [
                    self::link('Профиль', '/settings', exact: true),
                    ...($user->isAdmin() ? [self::link('Пользователи', '/settings/users')] : []),
                    self::link('Вендоры', '/settings/vendors'),
                    self::link('Ящики', '/settings/mailboxes'),
                    self::link('Шаблоны', '/settings/templates'),
                    self::link('Метки', '/settings/tags'),
                    self::link('Уведомления', '/account/notifications'),
                ],
                'Переходы' => [
                    self::link('На сайт', Surface::Site->url()),
                    self::link('Парковка', Surface::Park->url()),
                ],
            ];
        }

        $links = [self::link('Профиль', '/account', exact: true)];
        if ($user->role === Role::Manager) {
            // Интерес и приглашения — про людей: живут внутри «Покупателей»; подтверждения — начало сделки.
            $links[] = self::link('Покупатели', '/account/buyers', also: ['/account/interest', '/account/invites']);
            $links[] = self::link('Сделки', '/account/deals');
            $links[] = self::link('Деньги', '/account/money', also: ['/account/money/details']);
        } elseif ($user->isAdmin()) {
            $links[] = self::link('Пользователи', '/account/users');
            $links[] = self::link('Приглашения', '/account/invites');
        } elseif (! $user->isStaff()) {
            $links[] = self::link('Интерес', '/account/interests');
        }
        // Сотруднику — чаты площадки здесь же, а не переадресацией в CRM: другой хост в приложении — встроенный браузер.
        if ($user->canChat() || $user->isStaff()) {
            $links[] = self::link('Чаты', '/account/chats');
        }
        $links[] = self::link('Избранное', '/account/favorites');
        $links[] = self::link('Уведомления', '/account/notifications');
        // В установленном приложении подвала нет — документы живут здесь.
        if (MarkInstalled::installed(request())) {
            return ['' => $links, 'Документы' => [self::link('Обработка данных', '/privacy'), self::link('Соглашение', '/terms')]];
        }

        return ['' => $links];
    }

    /**
     * Кабинет для одного вида: на ПК без пунктов, что уже стоят в шапке (капсулы и верхний ряд), на телефоне —
     * без пунктов таб-бара и без «Профиля» (корень кабинета — сам профиль). Пустые группы выпадают.
     *
     * @param  'desktop'|'phone'  $mode
     */
    public static function cabinetFor(User $user, string $mode, ?Surface $surface = null): array
    {
        $surface ??= Surface::current();
        // Корень кабинета на ПК остаётся первой пилюлей, даже если он и капсула шапки («Настройки» в CRM).
        $taken = $mode === 'desktop'
            ? array_diff(array_column([...self::capsules($user, $surface), ...self::top($user, $surface)], 'href'), [self::cabinetRoot($surface)])
            : [...array_column(self::sectionTabs($user, $surface), 'href'), self::cabinetRoot($surface)];

        return array_filter(array_map(
            fn (array $links) => array_values(array_filter($links, fn (array $l) => ! in_array($l['href'], $taken, true))),
            self::cabinet($user, $surface),
        ));
    }

    /** Экран пункта кабинета этого вида (для подсветки аватара на ПК и пилюль). */
    public static function inCabinet(string $path, ?User $user, string $mode, ?Surface $surface = null): bool
    {
        if (! $user) {
            return false;
        }
        foreach (self::cabinetFor($user, $mode, $surface) as $links) {
            foreach ($links as $l) {
                if (self::isCurrentLink($l, $path)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * «Назад» в шапке телефона: как на ПК, а корень пункта из меню кабинета (Вендоры, Сделки) — назад в кабинет:
     * на ПК это раздел шапки или пилюля, на телефоне — экран, куда пришли из кабинета.
     */
    public static function phoneBack(string $path, ?User $user, ?Surface $surface = null): ?array
    {
        $surface ??= Surface::current();
        if ($back = self::backFor($path, $user, $surface)) {
            return $back;
        }
        $path = rtrim($path, '/') ?: '/';
        if (! $user || $path === self::cabinetRoot($surface)) {
            return null;
        }
        foreach (self::cabinetFor($user, 'phone', $surface) as $links) {
            foreach ($links as $l) {
                if ($l['href'] === $path) {
                    return [$surface === Surface::Crm ? 'Настройки' : 'Кабинет', self::cabinetRoot($surface)];
                }
            }
        }

        return null;
    }

    /**
     * Счётчики на пунктах: путь → число. Ноль не отдаётся. В запросе считается
     * один раз — шапка, заголовки разделов и кнопки списков берут одно и то же;
     * память на самом запросе, а не в процессе: воркер живёт долго.
     */
    public static function badges(?User $user, ?Surface $surface = null): array
    {
        $surface ??= Surface::current();
        if (! $user) {
            return [];
        }
        $memo = app()->runningInConsole() ? null : request()->attributes;
        $key = "nav.badges:{$user->id}:{$surface->value}";
        if ($memo?->has($key)) {
            return $memo->get($key);
        }
        $badges = array_filter(self::count($user, $surface) + self::counts($user, $surface)['fresh']);
        $memo?->set($key, $badges);

        return $badges;
    }

    /**
     * Сколько всего в разделе — серое число в таб-баре: путь → число; рядом «свежее» — что
     * появилось за сутки (лаймовый бейдж там, где нет своего счётчика непрочитанного).
     * Полминуты в кэше: сотрудникам общий, менеджеру и покупателю — свой (у них своя выдача).
     */
    public static function totals(?User $user, ?Surface $surface = null): array
    {
        return $user ? self::counts($user, $surface ?? Surface::current())['totals'] : [];
    }

    private static function counts(User $user, Surface $surface): array
    {
        $scope = $user->isStaff() ? 'staff' : $user->id;

        return Cache::remember("nav.counts:{$surface->value}:{$scope}", 30, function () use ($user, $surface) {
            $day = now()->subDay();
            if ($surface === Surface::Park) {
                return ['totals' => [
                    '/' => Request::whereIn('state', RequestState::open())->count(),
                    '/cars' => Vehicle::where('state', VehicleState::Stored)->count(),
                    '/yards' => Yard::count(),
                    '/money' => Invoice::where('state', InvoiceState::Issued)->count(),
                    '/mail' => Thread::whereHas('account', fn ($a) => $a->where('scope', Scope::Park))->count(),
                ], 'fresh' => []];
            }
            if ($surface === Surface::Crm) {
                return ['totals' => [
                    '/' => Offer::whereNotIn('state', [OfferState::Archived, OfferState::Gallery])->count(),
                    '/gallery' => Offer::where('state', OfferState::Gallery)->count(),
                    '/work' => Deal::where('state', DealState::Active)->count(),
                    '/purchases' => Purchase::where('state', PurchaseState::Open)->count(),
                ], 'fresh' => []];
            }
            $open = Offer::visibleTo($user)->where('state', OfferState::Open);
            $gallery = Offer::visibleTo($user)->where('state', OfferState::Gallery);

            // Ноль — тоже число («Покупатели 0»), нет только разделов, которых у роли нет.
            return ['totals' => array_filter([
                '/' => (clone $open)->count(),
                '/gallery' => $user->role->canSeeGallery() ? (clone $gallery)->count() : null,
                '/purchases' => $user->role->canSeePurchases() ? count(Purchase::showcase($user)) : null,
                '/account/buyers' => $user->isManager() ? User::where('manager_id', $user->id)->count() : null,
                '/account/interests' => $user->isBuyer() ? Interest::where('user_id', $user->id)->count() : null,
                '/account/favorites' => Favorite::where('user_id', $user->id)->count(),
                '/account/notifications' => $user->notifications()->count(),
            ], fn ($v) => $v !== null), 'fresh' => [
                '/' => (clone $open)->where('published_at', '>', $day)->count(),
                '/gallery' => $user->role->canSeeGallery() ? (clone $gallery)->where('published_at', '>', $day)->count() : 0,
            ]];
        });
    }

    private static function count(User $user, Surface $surface): array
    {
        $badges = ['/account/notifications' => $user->unreadCount()];

        if ($surface === Surface::Park || $surface === Surface::Crm) {
            return array_filter($badges + self::staffCounts($surface));
        }

        if ($user->isManager()) {
            $badges['/account/deals'] = Requirement::where('user_id', $user->id)->whereNull('done_at')->count();
            // Новый интерес — на табе «Покупатели»: свой экран интереса вложен туда.
            $badges['/account/interest'] = Interest::where('state', InterestState::New)->whereHas('user', fn ($u) => $u->where('manager_id', $user->id))->count();
            $badges['/account/buyers'] = $badges['/account/interest'];
        }
        if ($user->canChat() || $user->isStaff()) {
            // Свои чаты плюс чаты покупателей, где менеджер — вторая сторона; сотруднику — и площадки.
            $badges['/account/chats'] = $user->unreadChats();
        }

        return array_filter($badges);
    }

    /**
     * Общие для всех сотрудников счётчики — до шести запросов на каждую страницу CRM,
     * поэтому полминуты в кэше; события, которые их меняют (подтверждение, письмо,
     * сообщение, прочитано), сбрасывают кэш через forgetStaffCounts.
     */
    public static function staffCounts(Surface $surface): array
    {
        return Cache::remember("nav.staff:{$surface->value}", 30, function () use ($surface) {
            if ($surface === Surface::Park) {
                return [
                    // Бейдж «Заявки» — просроченные: то, что горит.
                    '/' => Request::whereIn('state', RequestState::open())->where('planned_at', '<', now())->count(),
                    // Бейдж «Из писем» — число дел, которые надо завести: у одинокого письма-заявки цепочки нет.
                    '/requests/from-mail' => Boxes::registerCount(Scope::Park),
                    '/money' => Invoice::where('state', InvoiceState::Issued)->whereDate('due_at', '<', now()->toDateString())->count(),
                    '/mail' => Thread::where('unread_count', '>', 0)->whereNull('archived_at')->whereHas('account', fn ($a) => $a->where('scope', Scope::Park))->count(),
                ];
            }
            $badges = [
                '/' => Bid::where('state', BidState::Active)->count(),
                '/offers/from-mail' => Boxes::registerCount(Scope::Offers),
                '/work/mail' => Thread::where('unread_count', '>', 0)->whereNull('archived_at')->whereHas('account', fn ($a) => $a->where('scope', Scope::Offers))->count(),
                '/work/chats' => Chat::whereNull('manager_id')->where('unread_for_staff', '>', 0)->count(),
                '/work/deals' => Position::where('track', 'sale')
                    ->whereHas('offer.deal', fn ($d) => $d->where('state', DealState::Active))
                    ->where(fn ($w) => $w->where('deadline_at', '<', now())->orWhereHas('stage', fn ($s) => $s->where('waits_for', WaitsFor::Us)))
                    ->count(),
                // Деньги горят, когда менеджер сообщил об оплате, а мы ещё не подтвердили.
                '/work/money' => Payment::where('state', PaymentState::Claimed)->whereHas('invoice', fn ($i) => $i->whereNotNull('deal_id'))->count(),
            ];
            $badges['/work'] = $badges['/work/deals'] + $badges['/work/mail'] + $badges['/work/chats'] + $badges['/work/money'];

            return $badges;
        });
    }

    /** Сброс общих счётчиков сотрудников — бейджей и итогов таб-бара; личные живут свои полминуты. */
    public static function forgetStaffCounts(): void
    {
        Cache::forget('nav.staff:crm');
        Cache::forget('nav.staff:park');
        foreach (Surface::cases() as $surface) {
            Cache::forget("nav.counts:{$surface->value}:staff");
        }
    }

    /** Число в капсуле таб-бара: до трёх цифр, дальше «999+». */
    public static function short(int $n): string
    {
        return $n > 999 ? '999+' : (string) $n;
    }

    /** Все пути, у которых бывает счётчик — для стрима бейджей. */
    public static function badgePaths(?User $user, ?Surface $surface = null): array
    {
        $surface ??= Surface::current();
        $paths = array_column(self::sections($user, $surface), 'href');
        $paths[] = '/account/notifications';
        if ($surface === Surface::Site) {
            $paths[] = '/account/chats';
            $paths[] = '/account/deals';
            $paths[] = '/account/money';
        }
        if ($surface === Surface::Crm) {
            $paths[] = '/work/deals';
            $paths[] = '/work/mail';
            $paths[] = '/work/chats';
            $paths[] = '/work/money';
            $paths[] = '/offers/from-mail';
        }
        if ($surface === Surface::Park) {
            $paths[] = '/requests/from-mail';
        }

        return array_values(array_unique($paths));
    }

    /**
     * «‹ Раздел» для вложенного экрана: самый длинный раздел или пункт кабинета,
     * чей путь — собственный префикс текущего; корни разделов — без «назад».
     */
    public static function backFor(string $path, ?User $user, ?Surface $surface = null): ?array
    {
        $surface ??= Surface::current();
        $path = rtrim($path, '/') ?: '/';
        if ($path === '/') {
            return null;
        }

        $items = self::sections($user, $surface);
        // Пилюли кабинета и заголовки «Работы» — корни своих экранов, а не глубина.
        if ($user) {
            foreach (self::cabinet($user, $surface) as $links) {
                foreach ($links as $l) {
                    if (str_starts_with($l['match'], '=')) {
                        continue;
                    }
                    $items[] = $l;
                    // Вложенный экран под чужим адресом (интерес, приглашения) — «назад» на свою пилюлю.
                    foreach ($l['also'] as $also) {
                        if ($path === $also || str_starts_with($path, $also.'/')) {
                            return [$l['label'], $l['href']];
                        }
                    }
                }
            }
        }
        if ($surface === Surface::Crm) {
            array_push($items, self::link('Сделки', '/work/deals'), self::link('Почта', '/work/mail'), self::link('Чаты', '/work/chats'), self::link('Деньги', '/work/money'));
        }

        // Сам корень раздела или экран с пилюлями кабинета — «назад» не нужен.
        if (in_array($path, array_column($items, 'href'), true)) {
            return null;
        }

        $best = null;
        foreach ($items as $item) {
            $href = $item['href'];
            if ($href === '/' || str_starts_with($href, 'http')) {
                continue;
            }
            if (str_starts_with($path, $href.'/') && strlen($href) > strlen($best['href'] ?? '')) {
                $best = $item;
            }
        }
        if ($best) {
            return [$best['label'], $best['href']];
        }

        // Путь под псевдонимом раздела (/offers/123 → «Предложения», /).
        foreach (self::sections($user, $surface) as $item) {
            if (self::isCurrent($item, $path) && $item['href'] !== $path) {
                return [$item['label'], $item['href']];
            }
        }

        return null;
    }

    public static function isCurrent(array $item, string $path): bool
    {
        foreach ((array) $item['match'] as $match) {
            if (str_starts_with($match, 'http')) {
                continue;
            }
            if ($match === '/' ? $path === '/' : str_starts_with($path, $match)) {
                return true;
            }
        }

        return false;
    }

    /** Длина самого точного совпадения — чтобы в таб-баре активен был один пункт. */
    public static function matchLength(array $item, string $path): int
    {
        $best = 0;
        foreach ((array) $item['match'] as $match) {
            if (! str_starts_with($match, 'http') && ($match === '/' ? $path === '/' : str_starts_with($path, $match))) {
                $best = max($best, strlen($match));
            }
        }

        return $best;
    }

    private static function item(string $label, string $href, string|array|null $match = null, bool $tab = true, bool $capsule = true): array
    {
        return ['label' => $label, 'href' => $href, 'match' => $match ?? $href, 'tab' => $tab, 'capsule' => $capsule];
    }

    /** @param list<string> $also  чужие адреса, при которых пилюля активна и служит «назад» */
    private static function link(string $label, string $href, bool $exact = false, array $also = []): array
    {
        return ['label' => $label, 'href' => $href, 'match' => $exact ? '='.$href : $href, 'also' => $also];
    }

    /** Активная пилюля кабинета: точное совпадение для сводки, префикс для остальных. */
    public static function isCurrentLink(array $link, string $path): bool
    {
        if (str_starts_with($link['match'], '=')) {
            return $path === substr($link['match'], 1);
        }
        foreach ($link['also'] ?? [] as $also) {
            if (str_starts_with($path, $also)) {
                return true;
            }
        }

        return self::isCurrent($link, $path);
    }
}
