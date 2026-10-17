<?php

namespace App\Support;

use App\Billing\ChargeKind;
use App\Billing\Closing;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\ManagerLedger;
use App\Chats\Chat;
use App\Garage\Car as GarageCar;
use App\Garage\CarState;
use App\Http\Admin\MoneyController;
use App\Http\Admin\OfferController;
use App\Logistics\Transport;
use App\Mail\Boxes;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\CatalogQuery;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Interest;
use App\Offers\InterestState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\City;
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
            // Гость видит только лендинг: разделов у него нет.
            if (! $user) {
                return [];
            }

            return array_values(array_filter([
                self::item('Заявки', '/requests'),
                self::item('Наличие', '/cars'),
                $user?->canManagePark() ? self::item('Оплаты', '/money') : null,
                $user?->canManagePark() ? self::item('Почта', '/mail') : null,
                $user->seesYards() ? self::item('Парковки', '/yards', tab: false) : null,
                // Вендоры управляющему — карточка и контакты; помощнику их нет.
                $user?->canManagePark() ? self::item('Вендоры', '/vendors', tab: false) : null,
            ]));
        }

        // Логистика (17.10.2026): вывозы (что видно — `Transport::visibleTo`), документы СДЭКом — модератору и админу,
        // исполнители и «Оплаты» (долг логисту, 19.01.2027) — логисту и админу. Модераторы ведут вывоз, как админ (18.10.2026).
        if ($surface === Surface::Logistics) {
            if (! $user) {
                return [];
            }
            $tasks = $user->leadsLogistics() || $user->isLogist() || $user->isDriver() || $user->isContractor() || $user->carriesForLogistics();

            return array_values(array_filter([
                $tasks ? self::item('Вывоз', '/transports') : null,
                $user->sendsLogisticsDocs() ? self::item('Документы', '/documents') : null,
                $user->isAdmin() || $user->isLogist() ? self::item('Исполнители', '/contractors') : null,
                $user->isAdmin() || $user->isLogist() ? self::item('Оплаты', '/money') : null,
            ]));
        }

        // Модератор: черновики, почта по галке и профиль — остальное в CRM админу.
        if ($surface === Surface::Crm && ! $user?->canManageCrm()) {
            return array_values(array_filter([
                self::item('Предложения', '/', ['/', '/offers']),
                $user?->canCrmMail() ? self::item('Почта', '/work/mail') : null,
                self::item('Настройки', '/settings', ['/settings', '/account'], tab: false, capsule: false),
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

        // Сделки менеджера — раздел таб-бара; покупатели (интерес, приглашения, группы) — его вторая пилюля.
        $deals = self::item('Сделки', '/deals', ['/deals', '/buyers']);

        // Демо-кабинет менеджера: каталог вместо закупок — закупки настоящие и ему закрыты; гаража у демо нет.
        if ($user?->hasRole(Role::Manager) && $user->is_demo) {
            return [self::item('Предложения', '/offers'), self::item('Галерея', '/gallery'), $deals];
        }

        if ($user?->hasRole(Role::Manager)) {
            return [
                self::item('Предложения', '/offers'),
                self::item('Галерея', '/gallery'),
                $deals,
                self::item('Гараж', '/garage'),
            ];
        }

        // Уведомлений в разделах нет: колокольчик в шапке, лента и настройки — строкой кабинета.
        // Закупки на сайте скрыты (владелец 03.10.2026); галерея — свой раздел у всех авторизованных (08.10.2026).
        // На сайте «сотрудник» — админ (модератору сайт не нужен); админ, который ещё и менеджер, — менеджер (ветка выше).
        // Админ — те же четыре раздела, что у менеджера: в «Сделках» и «Гараже» у него все, ведёт он их в CRM.
        if ($user?->isAdmin()) {
            return [
                self::item('Предложения', '/offers'),
                self::item('Галерея', '/gallery'),
                self::item('Сделки', '/deals'),
                self::item('Гараж', '/garage'),
            ];
        }

        // Супер-покупатель: предложения, галерея и свои сделки — покупателей и гаража у него нет.
        if ($user?->isSuperBuyer()) {
            return [self::item('Предложения', '/offers'), self::item('Галерея', '/gallery'), self::item('Сделки', '/deals')];
        }

        // Проверяющий: только посмотреть предложения.
        if ($user?->hasRole(Role::Reviewer)) {
            return [self::item('Предложения', '/offers'), self::item('Галерея', '/gallery')];
        }

        // Покупатель: только то, что открыл менеджер, и его интерес.
        if ($user?->isBuyer()) {
            return [
                self::item('Предложения', '/offers'),
                self::item('Галерея', '/gallery'),
                self::item('Интерес', '/account/interests'),
            ];
        }

        if ($user?->isApproved()) {
            return [
                self::item('Предложения', '/offers'),
                self::item('Галерея', '/gallery'),
                self::item('Избранное', '/account/favorites', capsule: false),
            ];
        }

        // Сайт закрыт: гостю и ждущему решения — только обращение.
        return [];
    }

    /**
     * Пункты таб-бара: до четырёх разделов и «Кабинет». Кабинет горит и на экранах своего меню
     * (Вендоры, Деньги, Тарифы — на телефоне они живут в кабинете, а не в таб-баре).
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

        // С CRM и парковки — переходы на соседние приложения, только открытые человеку (ролей бывает несколько).
        if ($surface !== Surface::Site) {
            return self::switchers($user, $surface, fn ($label, $url, $icon) => self::item($label, $url));
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
     * Соседние приложения, открытые человеку: сайт (если он ему нужен — `Surface::onlyFor`), CRM, парковка; кроме
     * текущего. Ролей бывает несколько: модератор и менеджер ходит между CRM и сайтом, «Парковка» и модератор — между
     * парковкой и CRM.
     */
    private static function switchers(?User $user, Surface $surface, \Closure $make): array
    {
        if (! $user) {
            return [];
        }

        return array_values(array_filter([
            $surface !== Surface::Site && ! Surface::onlyFor($user) ? $make('На сайт', Surface::Site->url(), 'cat-car') : null,
            $surface !== Surface::Crm && Surface::Crm->opensFor($user) ? $make('CRM', Surface::Crm->url(), 'deal') : null,
            $surface !== Surface::Park && Surface::Park->opensFor($user) ? $make('Парковка', Surface::Park->url(), 'park') : null,
            $surface !== Surface::Logistics && Surface::Logistics->opensFor($user) ? $make('Логистика', Surface::Logistics->url(), 'cat-car') : null,
        ]));
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
            return array_filter(['' => array_values(array_filter([
                self::link('Профиль', '/account', exact: true),
                $user->canManagePark() ? self::link('Вендоры', '/vendors', icon: 'deal') : null,
                $user->isParkAdmin() ? self::link('Тарифы', '/tariffs', icon: 'wallet') : null,
                $user->isParkAdmin() ? self::link('Пользователи', '/users', icon: 'user') : null,
                $user->seesYards() ? self::link('Парковки', '/yards', icon: 'park') : null,
                self::link('Уведомления', '/account/notifications', icon: 'bell'),
                // В CRM бывают не все: у сотрудника только стоянки чужой хост — стена.
                $user->isStaff() ? self::link('Шаблоны', Surface::Crm->url('/settings/templates'), icon: 'link') : null,
            ])), 'Переходы' => self::switchers($user, $surface, fn ($label, $url, $icon) => self::link($label, $url, icon: $icon))]);
        }

        if ($surface === Surface::Logistics) {
            return array_filter([
                '' => [self::link('Профиль', '/account', exact: true), self::link('Уведомления', '/account/notifications', icon: 'bell')],
                'Переходы' => self::switchers($user, $surface, fn ($label, $url, $icon) => self::link($label, $url, icon: $icon)),
            ]);
        }

        if ($surface === Surface::Crm && ! $user->canManageCrm()) {
            return array_filter([
                '' => [self::link('Профиль', '/settings', exact: true), self::link('Уведомления', '/account/notifications', icon: 'bell')],
                'Переходы' => self::switchers($user, $surface, fn ($label, $url, $icon) => self::link($label, $url, icon: $icon)),
            ]);
        }
        if ($surface === Surface::Crm) {
            // Один раздел «Настройки»: профиль — его первый пункт, как /account на сайте.
            return [
                '' => [
                    self::link('Профиль', '/settings', exact: true),
                    ...($user->isAdmin() ? [self::link('Пользователи', '/settings/users', icon: 'users')] : []),
                    self::link('Вендоры', '/settings/vendors', icon: 'deal'),
                    ...($user->isAdmin() ? [self::link('Таймеры', '/settings/timers', icon: 'timer')] : []),
                    ...($user->isAdmin() ? [self::link('Банк', '/settings/bank', icon: 'bank')] : []),
                    ...($user->isAdmin() ? [self::link('Бот Telegram', '/settings/telegram', icon: 'send')] : []),
                    self::link('Ящики', '/settings/mailboxes', icon: 'mail'),
                    self::link('Шаблоны', '/settings/templates', icon: 'link'),
                    self::link('Метки', '/settings/tags', icon: 'flag'),
                    self::link('Водяные знаки', '/settings/watermarks', icon: 'photo'),
                    self::link('Уведомления', '/account/notifications', icon: 'bell'),
                ],
                'Переходы' => self::switchers($user, $surface, fn ($label, $url, $icon) => self::link($label, $url, icon: $icon)),
            ];
        }

        $links = [self::link('Профиль', '/account', exact: true)];
        // Покупатели и ссылки для них — и в кабинете, не только в «Сделках» (12.10.2026, владелец: «не могут найти»).
        if ($user->isManager() && ! $user->isAdmin()) {
            $links[] = self::link('Покупатели', '/account/buyers', icon: 'users');
            $links[] = self::link('Ссылки', '/account/invites', icon: 'link');
        } elseif ($user->isManager()) {
            $links[] = self::link('Покупатели', '/account/buyers', icon: 'users');
        }
        if ($user->hasDeals()) {
            // Сделки, покупатели и гараж — разделы таб-бара; в кабинете остаются деньги.
            $links[] = self::link('Оплаты', '/account/money', also: ['/account/money/details'], icon: 'wallet');
            // Супер-покупатель оставляет интерес в галерее — его список здесь же (у менеджера интерес — в «Покупателях»).
            if ($user->isSuperBuyer()) {
                $links[] = self::link('Интерес', '/account/interests', icon: 'flag');
            }
        } elseif ($user->isAdmin()) {
            $links[] = self::link('Пользователи', '/account/users', icon: 'users');
            $links[] = self::link('Приглашения', '/account/invites', icon: 'link');
        } elseif (! $user->isAdmin()) {
            $links[] = self::link('Интерес', '/account/interests', icon: 'flag');
        }
        // Сотруднику — чаты площадки здесь же, а не переадресацией в CRM: другой хост в приложении — встроенный браузер.
        if ($user->canChat() || $user->isAdmin()) {
            $links[] = self::link('Чаты', '/account/chats', icon: 'chat');
        }
        $links[] = self::link('Избранное', '/account/favorites', icon: 'bookmark');
        $links[] = self::link('Уведомления', '/account/notifications', icon: 'bell');

        // У вошедшего подвала нет (приложение, а не сайт) — о компании и документы живут здесь.
        return array_filter(['' => $links, 'Переходы' => self::switchers($user, $surface, fn ($label, $url, $icon) => self::link($label, $url, icon: $icon)), 'Документы' => [self::link('О компании', '/company'), self::link('Обработка данных', '/privacy'), self::link('Соглашение', '/terms')]]);
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
     * «Назад» в шапке телефона: как на ПК, а корень пункта из меню кабинета (Вендоры, Деньги) — назад в кабинет:
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

        return self::perRequest("nav.badges:{$user->id}:{$surface->value}",
            function () use ($user, $surface) {
                // Бейдж раздела — сумма: у «Предложений» менеджера и «Нужно оценить», и новые за 12 часов.
                $badges = self::count($user, $surface);
                foreach (self::counts($user, $surface)['fresh'] as $path => $n) {
                    $badges[$path] = ($badges[$path] ?? 0) + $n;
                }

                return array_filter($badges);
            });
    }

    /**
     * Посчитать раз на веб-запрос: шапка, таб-бар, пилюли и кнопки читают одно и то же. Память — на самом запросе, а
     * не в процессе (воркер живёт долго); в консоли и очереди — каждый раз.
     */
    private static function perRequest(string $key, \Closure $compute): mixed
    {
        $memo = app()->runningInConsole() ? null : request()->attributes;
        if ($memo?->has($key)) {
            return $memo->get($key);
        }
        $value = $compute();
        $memo?->set($key, $value);

        return $value;
    }

    /**
     * Сколько всего в разделе — серое число в таб-баре: путь → число; рядом «свежее» — предложения за 12 ч, галерея
     * за сутки (лаймовый бейдж там, где нет своего счётчика непрочитанного).
     * Полминуты в кэше: сотрудникам общий, менеджеру и покупателю — свой (у них своя выдача).
     */
    public static function totals(?User $user, ?Surface $surface = null): array
    {
        return $user ? self::counts($user, $surface ?? Surface::current())['totals'] : [];
    }

    private static function counts(User $user, Surface $surface): array
    {
        // Счётчики админа общие на всех админов; админ, который ещё и менеджер, на сайте видит свои — ключ его.
        $key = "nav.counts:{$surface->value}:".($user->isAdmin() && ! $user->isManager() ? 'staff' : $user->id);

        // Кэш в базе — за страницу его читают по два-три раза: из него один раз на запрос.
        return self::perRequest($key, fn () => self::countsCached($user, $surface, $key));
    }

    private static function countsCached(User $user, Surface $surface, string $key): array
    {
        return Cache::remember($key, 30, function () use ($user, $surface) {
            $day = now()->subDay();
            if ($surface === Surface::Park) {
                return ['totals' => [
                    '/requests' => Request::whereIn('state', RequestState::open())->count(),
                    '/cars' => Vehicle::where('state', VehicleState::Stored)->count(),
                    '/yards' => Yard::when(City::of($user), fn ($q, $city) => $q->where('settlement_id', $city))->count(),
                    '/money' => Invoice::ofPark()->where('state', InvoiceState::Issued)->count(),
                    '/mail' => Boxes::allCount(Scope::Park),
                ], 'fresh' => []];
            }
            if ($surface === Surface::Logistics) {
                return ['totals' => [
                    '/transports' => Transport::visibleTo($user)->open()->count(),
                    '/documents' => $user->sendsLogisticsDocs() ? Transport::awaitingDocs()->count() : 0,
                    '/contractors' => $user->isAdmin() || $user->isLogist() ? User::contractorsFor($user)->count() : 0,
                    // Ждут выплаты логисту: свои — логисту, все — админу.
                    '/money' => $user->isAdmin() || $user->isLogist()
                        ? Invoice::where('direction', 'owed')->where('kind', ChargeKind::Tow->value)->where('state', InvoiceState::Issued)
                            ->when($user->isAdmin(),
                                fn ($q) => $q->whereIn('party_id', User::withRole(Role::Logist)->whereNotNull('party_id')->select('party_id')),
                                fn ($q) => $q->where('party_id', $user->party_id ?? 0))->count()
                        : 0,
                ], 'fresh' => []];
            }
            if ($surface === Surface::Crm && ! $user->canManageCrm()) {
                // Таб — как список без вкладки: «Без закупочной цены» его группы.
                return ['totals' => ['/' => OfferController::scopeFor(OfferController::home($user), Offer::inCrm($user)->whereNot(fn ($o) => $o->emptyDraft()))->count()], 'fresh' => []];
            }
            if ($surface === Surface::Crm) {
                return ['totals' => [
                    // Как список без вкладки — «Без продажной цены», вместе с черновиками из закупок.
                    '/' => OfferController::scopeFor(OfferController::home($user), Offer::whereNot(fn ($o) => $o->emptyDraft()))->count(),
                    '/gallery' => Offer::inGallery()->count(),
                    '/work' => Deal::where('state', DealState::Active)->count(),
                    '/purchases' => Purchase::where('state', PurchaseState::Open)->count(),
                ], 'fresh' => []];
            }
            // Таб «Предложения» — то же, что в каталоге (`Offer::siteList`: открытое без сделки плюс
            // сделка, открытая менеджеру): срок вышел или идёт сделка — с сайта ушло.
            $open = Offer::siteList($user);
            $gallery = Offer::visibleTo($user)->inGallery();

            // Ноль — тоже число («Покупатели 0»), нет только разделов, которых у роли нет.
            return ['totals' => array_filter([
                '/offers' => (clone $open)->count(),
                '/gallery' => (clone $gallery)->count(),
                '/purchases' => $user->canSeePurchases() ? Purchase::cardCount($user) : null,
                // Сделки в работе — то, что стоит строками на экране «Сделки».
                '/deals' => match (true) {
                    // Только сделки в работе, как в списке (11.10.2026): подтверждения и закончившееся — строками в конце.
                    $user->hasDeals() => Deal::where('buyer_id', $user->id)->whereNull('garage_payer')->where('state', DealState::Active)->count(),
                    $user->isAdmin() => Deal::whereNull('garage_payer')->where('state', DealState::Active)->count(),
                    default => null,
                },
                '/buyers' => $user->isManager() ? User::where('manager_id', $user->id)->count() : null,
                // Как в заголовке «Гаража»: машины в работе, рассчитанные не считаются.
                // И вывозы, порученные менеджеру (группа «Вывоз»): ТС у него, хоть и не его гаража.
                '/garage' => $user->canGarage() ? GarageCar::of($user)->where('state', '!=', CarState::Settled)->count() : null,
                '/account/interests' => $user->isBuyer() || $user->isSuperBuyer() ? Interest::where('user_id', $user->id)->count() : null,
                // Как список «Избранного»: только то, что человеку сейчас видно.
                '/account/favorites' => Offer::visibleTo($user)->whereHas('favorites', fn ($f) => $f->where('user_id', $user->id))->count(),
            ], fn ($v) => $v !== null), 'fresh' => [
                // Как пилюля «Новые»: открылось этому человеку (волна, показ) за сутки.
                '/offers' => CatalogQuery::fresh(clone $open, $user)->count(),
                '/gallery' => (clone $gallery)->where('gallery_from', '>', $day)->count(),
            ]];
        });
    }

    /**
     * Открытые просьбы шага оплаты, когда счета сделки уже оплачены целиком: их не показывает строка «Сделок» и шаг —
     * не считает и бейдж. Таких единицы, проверка в памяти.
     *
     * @return array{deals: int, garage: int}
     */
    private static function paidPayAsks(User $user): array
    {
        $paid = Requirement::where('user_id', $user->id)->whereNull('done_at')->with(['stage', 'deal'])->get()
            ->filter(fn (Requirement $r) => $r->deal?->isActive() && $r->stage?->isPayStep() && $r->deal->fullyPaid());

        return ['deals' => $paid->filter(fn ($r) => $r->deal->garage_payer === null)->count(), 'garage' => $paid->filter(fn ($r) => $r->deal->garage_payer !== null)->count()];
    }

    private static function count(User $user, Surface $surface): array
    {
        $badges = ['/account/notifications' => $user->unreadCount()];

        if ($surface === Surface::Crm && ! $user->canManageCrm()) {
            // Модератору из общих бейджей — только почта, и то по галке.
            $mail = $user->canCrmMail() ? array_intersect_key(self::staffCounts($surface), array_flip(['/offers/from-mail', '/work/mail'])) : [];

            return array_filter($badges + $mail);
        }
        if ($surface === Surface::Park || $surface === Surface::Crm) {
            return array_filter($badges + self::staffCounts($surface));
        }
        // Бейдж — что ждёт этого человека: админу — без исполнителя, логисту — у него, исполнителю — его ход; Лизе — отправить
        // документы.
        if ($surface === Surface::Logistics) {
            return array_filter($badges + [
                '/transports' => Transport::visibleTo($user)->turnOf($user)->count(),
                '/documents' => $user->sendsLogisticsDocs() ? Transport::awaitingDocs()->count() : 0,
            ]);
        }

        if ($user->isManager()) {
            // «Нужно оценить» (11.10.2026): черновики, отданные ему на оценку и ещё не оценённые, — на табе «Предложения».
            $badges['/offers'] = Offer::askedOf($user)->whereHas('estimates', fn ($e) => $e->waiting()->where('user_id', $user->id))->count();
            // Новый интерес — на пилюле «Покупатели» и строке «Интерес»; таб «Сделки» горит и тем, что ждут от него, и им.
            $badges['/buyers/interest'] = Interest::where('state', InterestState::New)->whereHas('user', fn ($u) => $u->where('manager_id', $user->id))->count();
            $badges['/buyers'] = $badges['/buyers/interest'];
            $badges['/account/buyers'] = $badges['/buyers'];
            // Просьбы по гаражной сделке (страховая ещё идёт) — на табе «Гараж»: там её и ведут.
            $asks = Requirement::where('requirements.user_id', $user->id)->whereNull('requirements.done_at')->join('deals', 'deals.id', '=', 'requirements.deal_id')
                ->selectRaw('count(*) filter (where deals.garage_payer is null) as deals, count(*) filter (where deals.garage_payer is not null) as garage')->first();
            // Просьба «Оплатите счёт», а счета сделки уже оплачены (маршрут не догнал) — строка «Сделок» её не показывает
            // («Оплата получена»), бейдж тоже не считает: иначе на пилюле 5, а оранжевых строк 4 (11.10.2026).
            $paid = self::paidPayAsks($user);
            $badges['/deals/asks'] = max(0, (int) $asks->deals - $paid['deals']);
            $badges['/deals'] = $badges['/deals/asks'] + $badges['/buyers'];
            // Вывоз, порученный ему, горит в логистике (17.10.2026), не здесь.
            $badges['/garage'] = max(0, (int) $asks->garage - $paid['garage']);
        }
        if ($user->hasDeals()) {
            // «Оплаты» в кабинете горят тем, что ждёт его: оплатить, просрочено, указать покупателя (11.10.2026: в «Оплатах»
            // красное «Оплатить», а строка кабинета молчала).
            // Число — строки самих «Оплат», где нужен он (`DealMoney::needsAction`), иначе бейдж и список расходятся; расчёт
            // тяжёлый — полминуты в кэше, как итоги таб-бара.
            $badges['/account/money'] = Cache::remember("nav.money:{$user->id}", 30,
                fn () => (new ManagerLedger($user))->rows()->filter(fn ($r) => $r->money->needsAction())->count());
        }
        if ($user->isSuperBuyer()) {
            // Что ждут от него по сделкам — на табе «Сделки», как у менеджера.
            $badges['/deals/asks'] = max(0, Requirement::where('requirements.user_id', $user->id)->whereNull('requirements.done_at')->count() - array_sum(self::paidPayAsks($user)));
            $badges['/deals'] = $badges['/deals/asks'];
        }
        if ($user->isAdmin() && ! $user->isManager()) {
            // Админу «Сделки» горят тем же, что «Работа → Сделки» в CRM: просрочено или ход наш.
            $badges['/deals'] = self::staffCounts(Surface::Crm)['/work/deals'];
        }
        if ($user->canChat() || $user->isAdmin()) {
            // Свои чаты плюс чаты покупателей, где менеджер — вторая сторона; сотруднику — и площадки.
            $badges['/account/chats'] = $user->unreadChats(platform: $surface === Surface::Crm);
        }

        return array_filter($badges);
    }

    /** Сделки, где просрочено или ход наш: обычные — у «Сделок», гаражные — у «Гаража». */
    private static function hotDeals(bool $garage): int
    {
        return Position::where('track', 'sale')
            ->whereHas('offer.deal', fn ($d) => $d->where('state', DealState::Active)->when($garage, fn ($g) => $g->whereNotNull('garage_payer'), fn ($g) => $g->whereNull('garage_payer')))
            ->where(fn ($w) => $w->where('deadline_at', '<', now())->orWhere(fn ($x) => $x->waiting(WaitsFor::Us)))
            ->count();
    }

    /**
     * Общие для всех сотрудников счётчики — до шести запросов на каждую страницу CRM,
     * поэтому полминуты в кэше; события, которые их меняют (подтверждение, письмо,
     * сообщение, прочитано), сбрасывают кэш через forgetStaffCounts.
     */
    public static function staffCounts(Surface $surface): array
    {
        // У управляющего с городом свои числа (`Park\City`): области моделей сами сужают выборку.
        $city = $surface === Surface::Park ? City::viewing() : null;

        return Cache::remember("nav.staff:{$surface->value}".($city ? ":{$city}" : ''), 30, function () use ($surface) {
            if ($surface === Surface::Park) {
                return [
                    // Бейдж «Из писем» — число дел, которые надо завести: у одинокого письма-заявки цепочки нет.
                    '/requests/from-mail' => Boxes::registerCount(Scope::Park),
                    // Бейдж «Оплаты» — работа управляющего: выставить за прошлый месяц, отправить выставленное, просрочено.
                    '/money' => Closing::pendingCount() + Invoice::ofPark()->where('state', InvoiceState::Issued)
                        ->where(fn ($w) => $w->whereDate('due_at', '<', now()->toDateString())->orWhereIn('id', Invoice::toSend()->select('id')))->count(),
                    '/mail' => Thread::where('unread_count', '>', 0)->whereNull('archived_at')->whereHas('account', fn ($a) => $a->where('scope', Scope::Park))->count(),
                ];
            }
            $badges = [
                // Раздел «Предложения» горит и подтверждениями, и оценками менеджеров, ждущими решения (11.10.2026).
                '/' => Bid::where('state', BidState::Active)->count()
                    + Offer::where('state', OfferState::Draft)->whereHas('estimates', fn ($e) => $e->needsDecision())->count(),
                '/offers/from-mail' => Boxes::registerCount(Scope::Offers),
                '/work/mail' => Thread::where('unread_count', '>', 0)->whereNull('archived_at')->whereHas('account', fn ($a) => $a->where('scope', Scope::Offers))->count(),
                '/work/chats' => Chat::whereNull('manager_id')->where('unread_for_staff', '>', 0)->count(),
                // Горит то же, что «Горит» в списке; гаражные сделки — у «Гаража», не у «Сделок» (13.10.2026).
                '/work/deals' => self::hotDeals(garage: false),
                '/work/garage' => self::hotDeals(garage: true),
                // Деньги горят делом в «Оплатах»: менеджер пишет, что оплатил; не прошла по ссылке; выплатить. Выписки нет.
                '/work/money' => MoneyController::todo(),
            ];
            // На табе «Работа» — только непрочитанные чаты, числом чатов, а не сообщений (владелец, 11.10.2026: «сделок у нас
            // всегда будет дофига, этот счётчик не имеет смысла»); сделки, почта и деньги горят у своих разделов.
            $badges['/work'] = $badges['/work/chats'];

            return $badges;
        });
    }

    /** Сброс общих счётчиков сотрудников — бейджей и итогов таб-бара; личные живут свои полминуты. */
    public static function forgetStaffCounts(): void
    {
        foreach (Surface::cases() as $surface) {
            Cache::forget("nav.staff:{$surface->value}");
            Cache::forget("nav.counts:{$surface->value}:staff");
        }
        foreach (array_keys(City::options()) as $city) {
            Cache::forget('nav.staff:'.Surface::Park->value.":{$city}");
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
            $paths[] = '/account/money';
            $paths[] = '/buyers';
            $paths[] = '/account/buyers';
            $paths[] = '/buyers/interest';
            $paths[] = '/deals/asks';
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
        // Пилюли кабинета, заголовки «Работы» и пилюля «Покупатели» у «Сделок» — корни своих экранов, а не глубина.
        if ($user) {
            $cabinet = self::cabinet($user, $surface);
            if ($surface === Surface::Site && $user->isManager()) {
                $cabinet[] = [self::link('Покупатели', '/buyers')];
            }
            foreach ($cabinet as $links) {
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
            array_push($items, self::link('Сделки', '/work/deals'), self::link('Гараж', '/work/garage'), self::link('Почта', '/work/mail'), self::link('Чаты', '/work/chats'), self::link('Оплаты', '/work/money'));
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

    /**
     * @param  list<string>  $also  чужие адреса, при которых пилюля активна и служит «назад»
     * @param  string  $icon  значок строки в меню кабинета на телефоне (профиль)
     */
    private static function link(string $label, string $href, bool $exact = false, array $also = [], string $icon = 'file'): array
    {
        return ['label' => $label, 'href' => $href, 'match' => $exact ? '='.$href : $href, 'also' => $also, 'icon' => $icon];
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
