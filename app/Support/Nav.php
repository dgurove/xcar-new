<?php

namespace App\Support;

use App\Billing\ChargeKind;
use App\Billing\Closing;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\MoneyBook;
use App\Billing\PayView;
use App\Chats\Chat;
use App\Garage\Car as GarageCar;
use App\Garage\CarState;
use App\Http\Admin\MoneyController;
use App\Http\Admin\OfferController;
use App\Http\Cabinet\ContractDataController;
use App\Http\Cabinet\DealController;
use App\Logistics\Client;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Mail\Boxes;
use App\Mail\Scope;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\CatalogQuery;
use App\Offers\Deal;
use App\Offers\DealTabs;
use App\Offers\Interest;
use App\Offers\InterestState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\OfferTabs;
use App\Offers\Partner\PartnerOffers;
use App\Park\City;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Position;
use App\Workflow\Requirement;
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
                $user?->parkMoney() ? self::item('Оплаты', '/money') : null,
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
                // Свои заказчики логиста (18.10.2026): заявки не только от XCAR.
                $user->isLogist() ? self::item('Заказчики', '/clients') : null,
                // Свои стоянки логиста (18.10.2026): где машины ждут между отрезками.
                $user->isLogist() ? self::item('Стоянки', '/depots') : null,
                $user->isAdmin() || $user->isLogist() ? self::item('Исполнители', '/contractors') : null,
                $user->isAdmin() || $user->isLogist() ? self::item('Оплаты', '/money') : null,
            ]));
        }

        // Партнёр (07.11.2026): поставщику — его черновики без цены и профиль, больше ничего.
        if ($surface === Surface::Partner) {
            return $user ? [self::item('Предложения', '/offers')] : [];
        }

        // XCAR Chat (20.11.2026): только чаты и профиль; что откуда, решает человек, а не хост (`Chat::staffView`).
        if ($surface === Surface::Chat) {
            // Капсулой в шапке ПК не рисуем: одна капсула на всю ширину — пустая полоса, раздел и так один.
            return $user ? [self::item('Чаты', Chat::ROOT, icon: 'chats', capsule: false) + ['own' => true]] : [];
        }
        // XCAR Mail (20.11.2026): только почта и профиль; «Из писем» — кнопкой в строке заголовка.
        if ($surface === Surface::Mail) {
            return $user ? [self::item('Почта', '/work/mail', icon: 'mail', capsule: false) + ['own' => true]] : [];
        }
        // XCAR Pay (22.11.2026): только «Оплаты» и профиль — сотруднику CRM, менеджеру свои (`Billing\PayView`).
        if ($surface === Surface::Pay) {
            return $user ? [self::item('Оплаты', PayView::home($user), icon: 'wallet', capsule: false) + ['own' => true]] : [];
        }

        // Модератор: черновики, сделки и гараж вкладками «Предложений» (17.11.2026 — вся работа со сделками), почта по галке, чаты
        // с менеджерами, «Оплаты» (22.11.2026, начальник: «чтобы и модераторы видели, что по оплатам менеджеров») и профиль;
        // остальное CRM — админу. Значки — как у админа.
        if ($surface === Surface::Crm && ! $user?->canManageCrm()) {
            return array_values(array_filter([
                self::item('Предложения', '/offers', ['/offers', '/gallery'], icon: 'feed') + ['go' => self::offersHome($user)],
                $user?->canCrmMail() ? self::item('Почта', '/work/mail', icon: 'mail') : null,
                $user?->canEditDeals() ? self::item('Чаты', '/work/chats', icon: 'chats') : null,
                $user?->canEditDeals() ? self::item('Оплаты', '/work/money', icon: 'wallet') : null,
                self::item('Настройки', '/settings', ['/settings', '/account'], tab: false, capsule: false),
            ]));
        }

        // 16.11.2026, владелец: галерея и закупки — разделы колеса «Предложений» (это тоже этапы), а в таб-баре вместо «Галерея ·
        // Работа · Закупки» — Почта, Чаты, Оплаты. Страница закупки и прежний адрес галереи — под «Предложениями».
        if ($surface === Surface::Crm) {
            return [
                // Значки разделов (16.11.2026, владелец: «красивые иконки, заполненные»): лента, письмо, пузыри, монета.
                self::item('Предложения', '/offers', ['/offers', '/purchases', '/gallery'], icon: 'feed') + ['go' => self::offersHome($user)],
                self::item('Почта', '/work/mail', icon: 'mail'),
                self::item('Чаты', '/work/chats', icon: 'chats'),
                self::item('Оплаты', '/work/money', icon: 'wallet'),
                // Кабинет CRM — это «Настройки»: профиль первой пилюлей, уведомления — под /account. В шапке ПК его
                // ведёт аватар, на телефоне — последний таб: отдельной капсулой он был бы вторым входом рядом.
                self::item('Настройки', '/settings', ['/settings', '/account'], tab: false, capsule: false),
            ];
        }

        // Чаты — свой раздел по центру таб-бара (18.11.2026, владелец: «пользователи очень давно просили»), со значком, как
        // разделы CRM; у остальных разделов сайта — капсула с числом. Всем, кто пишет (`canChat`), и админу — чаты площадки.
        // `own` — экран чата всегда раздел «Чаты», откуда бы его ни открыли (`lib/sections.js` не дописывает ссылке `in=`).
        $chats = $user && ($user->canChat() || $user->isAdmin()) ? self::item('Чаты', Chat::ROOT, icon: 'chats') + ['own' => true] : null;
        // «Сделки» — один раздел на три экрана (18.11.2026, владелец: «гараж в сделки переместил бы, вторым заголовком»):
        // сделки, гараж и покупатели — большими заголовками в ряд (`x-deal.heads`), у каждого свой адрес. «Гараж» остаётся
        // пунктом без таба и капсулы: «назад» с машины гаража и его счётчик считаются по нему.
        $deals = fn (bool $garage, bool $buyers) => [
            self::item('Сделки', '/deals', array_values(array_filter(['/deals', $buyers ? '/buyers' : null, $garage ? '/garage' : null]))),
            ...($garage ? [self::item('Гараж', '/garage', tab: false, capsule: false)] : []),
        ];
        $list = fn (array $items) => array_values(array_filter($items));

        // Демо-кабинет менеджера: каталог вместо закупок — закупки настоящие и ему закрыты; гаража у демо нет.
        if ($user?->hasRole(Role::Manager) && $user->is_demo) {
            return $list([self::item('Предложения', '/offers'), self::item('Галерея', '/gallery'), $chats, ...$deals(false, true)]);
        }

        if ($user?->hasRole(Role::Manager)) {
            return $list([self::item('Предложения', '/offers'), self::item('Галерея', '/gallery'), $chats, ...$deals(true, true)]);
        }

        // Уведомлений в разделах нет: колокольчик в шапке, лента и настройки — строкой кабинета.
        // Закупки на сайте скрыты (владелец 03.10.2026); галерея — свой раздел у всех авторизованных (08.10.2026).
        // На сайте «сотрудник» — админ (модератору сайт не нужен); админ, который ещё и менеджер, — менеджер (ветка выше).
        // Админ — те же разделы, что у менеджера: в «Сделках» и «Гараже» у него все, ведёт он их в CRM.
        if ($user?->isAdmin()) {
            return $list([self::item('Предложения', '/offers'), self::item('Галерея', '/gallery'), $chats, ...$deals(true, false)]);
        }

        // Супер-покупатель: предложения, галерея и свои сделки — покупателей нет, гараж — только по тумблеру (20.10.2026).
        if ($user?->isSuperBuyer()) {
            return $list([self::item('Предложения', '/offers'), self::item('Галерея', '/gallery'), $chats, ...$deals($user->canGarage(), false)]);
        }

        // Проверяющий: только посмотреть предложения.
        if ($user?->hasRole(Role::Reviewer)) {
            return [self::item('Предложения', '/offers'), self::item('Галерея', '/gallery')];
        }

        // Покупатель: только то, что открыл менеджер, и его интерес.
        if ($user?->onlyGarage()) {
            return $list([self::item('Гараж', '/garage'), $chats]);
        }
        if ($user?->isBuyer()) {
            // Четвёртый таб — «Гараж», кому его включили (тумблер в CRM, 20.10.2026: машины, что ему отдали), остальным —
            // «Интерес»; с гаражом интерес — строкой кабинета (18.11.2026, владелец).
            return $list([
                self::item('Предложения', '/offers'),
                self::item('Галерея', '/gallery'),
                $chats,
                $user->canGarage() ? self::item('Гараж', '/garage') : self::item('Интерес', '/account/interests'),
            ]);
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
        $tabs[] = self::item(match ($surface) {
            Surface::Crm => 'Настройки', Surface::Partner => 'Профиль', default => 'Кабинет'
        }, $root, array_values(array_unique($match)));

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

        // Из XCAR Chat и XCAR Mail переходов нет: установленное приложение другое не открывает, сделка — шторкой поверх.
        if ($surface->single()) {
            return [];
        }
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

        // Связанный аккаунт (`UseLinkedAccount`) — его хосты тоже переход: на своём хосте работает он.
        $linked = $user->linked;
        $opens = fn (Surface $s) => $s->opensFor($user) || ($linked && Surface::homeFor($linked) === $s);

        return array_values(array_filter([
            $surface !== Surface::Site && (! Surface::onlyFor($user) || ($linked && Surface::homeFor($linked) === Surface::Site)) ? $make('На сайт', Surface::Site->url(), 'cat-car') : null,
            $surface !== Surface::Crm && $opens(Surface::Crm) ? $make('CRM', Surface::Crm->url(Surface::Crm->home()), 'deal') : null,
            $surface !== Surface::Park && $opens(Surface::Park) ? $make('Парковка', Surface::Park->url(), 'park') : null,
            $surface !== Surface::Logistics && $opens(Surface::Logistics) ? $make('Логистика', Surface::Logistics->url(), 'cat-car') : null,
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
                // Управляющему одной парковки раздела нет — схема его (открытой) парковки прямо отсюда.
                ! $user->seesYards() && ($yard = Yard::where('is_active', true)->orderBy('id')->value('id')) ? self::link('Схема парковки', "/yards/{$yard}/plan", icon: 'park') : null,
                self::link('Уведомления', '/account/notifications', icon: 'bell'),
                // Шаблоны писем парковки — свои, на парковке (02.11.2026: управляющий правит готовые ответы сам).
                $user->canManagePark() ? self::link('Шаблоны', '/templates', icon: 'link') : null,
            ])), 'Переходы' => self::switchers($user, $surface, fn ($label, $url, $icon) => self::link($label, $url, icon: $icon))]);
        }

        // Чату и почте — профиль и уведомления (там темы и каналы), переходов нет: из установленного приложения в другое не уйти.
        if ($surface->single()) {
            return ['' => [self::link('Профиль', '/account', exact: true), self::link('Уведомления', '/account/notifications', icon: 'bell')]];
        }

        // Партнёру — только профиль: ни уведомлений, ни переходов.
        if ($surface === Surface::Partner) {
            return ['' => [self::link('Профиль', '/account', exact: true)]];
        }

        if ($surface === Surface::Logistics) {
            return array_filter([
                '' => [self::link('Профиль', '/account', exact: true), self::link('Уведомления', '/account/notifications', icon: 'bell')],
                'Переходы' => self::switchers($user, $surface, fn ($label, $url, $icon) => self::link($label, $url, icon: $icon)),
            ]);
        }

        if ($surface === Surface::Crm && ! $user->canManageCrm()) {
            return array_filter([
                // «Выписка» — и модератору (23.11.2026, владелец: «привязать руками — модераторы и администраторы»).
                '' => [self::link('Профиль', '/settings', exact: true), ...($user->canEditDeals() ? [self::link('Выписка', '/settings/bank/statement', icon: 'bank')] : []),
                    self::link('Уведомления', '/account/notifications', icon: 'bell')],
                'Переходы' => self::switchers($user, $surface, fn ($label, $url, $icon) => self::link($label, $url, icon: $icon)),
            ]);
        }
        if ($surface === Surface::Crm) {
            // Один раздел «Настройки»: профиль — его первый пункт, как /account на сайте.
            return [
                '' => [
                    self::link('Профиль', '/settings', exact: true),
                    ...($user->isAdmin() ? [self::link('Пользователи', '/settings/users', icon: 'users')] : []),
                    ...($user->isAdmin() ? [self::link('Ответственные', '/settings/responsibles', icon: 'user')] : []),
                    self::link('Вендоры', '/settings/vendors', icon: 'deal'),
                    ...($user->isAdmin() ? [self::link('Таймеры', '/settings/timers', icon: 'timer')] : []),
                    ...($user->isAdmin() ? [self::link('Банк', '/settings/bank', icon: 'bank')] : []),
                    ...($user->isAdmin() ? [self::link('Бот Telegram', '/settings/telegram', icon: 'send')] : []),
                    ...($user->isAdmin() ? [self::link('Доставка', '/settings/deliveries', icon: 'bell')] : []),
                    ...($user->isAdmin() ? [self::link('Парсинг', '/settings/parsing', icon: 'sparkles')] : []),
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
            // Сделки, покупатели и гараж — раздел таб-бара; в кабинете остаются деньги.
            $links[] = self::link('Оплаты', '/account/money', also: ['/account/money/details'], icon: 'wallet');
            // Супер-покупатель оставляет интерес в галерее — его список здесь же (у менеджера интерес — в «Покупателях»).
            if ($user->isSuperBuyer()) {
                $links[] = self::link('Интерес', '/account/interests', icon: 'flag');
            }
            // Покупатель с гаражом (20.10.2026): «Оплаты» — его счета за машины гаража, остальное кабинета — как было.
            if ($user->isBuyer()) {
                $links[] = self::link('Интерес', '/account/interests', icon: 'flag');
                if (ContractDataController::opensFor($user)) {
                    $links[] = self::link('Данные для договора', '/account/contract', icon: 'file');
                }
            }
        } elseif ($user->isAdmin()) {
            $links[] = self::link('Пользователи', '/account/users', icon: 'users');
            $links[] = self::link('Приглашения', '/account/invites', icon: 'link');
        } elseif (! $user->isAdmin()) {
            $links[] = self::link('Интерес', '/account/interests', icon: 'flag');
            // Покупатель по договору идущей сделки — паспорт и скан вносит сам (19.10.2026).
            if (ContractDataController::opensFor($user)) {
                $links[] = self::link('Данные для договора', '/account/contract', icon: 'file');
            }
        }
        // Откуда показывать предложения (22.10.2026): менеджеру и супер-покупателю.
        if ($user->isManager() || $user->isSuperBuyer()) {
            $links[] = self::link('Регионы', '/account/regions', icon: 'map-pin');
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
                    return [match ($surface) {
                        Surface::Crm => 'Настройки', Surface::Partner => 'Профиль', default => 'Кабинет'
                    }, self::cabinetRoot($surface)];
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
                // Бейдж раздела — сумма: у «Предложений» менеджера и «Нужно оценить», и не просмотренные в продаже.
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
        return StaffCache::perRequest($key, $compute);
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

    /** Свои счётчики сайта — заново: человек открыл предложение, и «не просмотренных» стало меньше (`ReadOffer`). */
    /**
     * С человеком что-то случилось (событие в его тему `user/{id}`, его собственное действие): личные числа сайта и
     * «Оплаты» кабинета — заново. До 18.11.2026 они жили полминуты: у менеджера каждая страница и каждый `/live/badges`
     * раз в 30 секунд пересчитывали всё — около 60 запросов, из них 16 — расчёт денег целиком. Теперь — до события.
     */
    public static function touch(User|int $user): void
    {
        $id = $user instanceof User ? $user->id : $user;
        StaffCache::forget('nav.counts:'.Surface::Site->value.':'.$id);
        StaffCache::forget('nav.money:'.$id);
        if (! app()->runningInConsole()) {
            request()->attributes->remove('nav.counts:'.Surface::Site->value.':'.$id);
        }
    }

    /** Каталог сайта изменился (новое предложение, приём закрыт, показ): личные числа «Предложений» и «Галереи» — заново. */
    public static function forgetSiteCounts(): void
    {
        StaffCache::bump(StaffCache::SITE);
    }

    public static function forget(User $user): void
    {
        // Счётчики живут в `StaffCache` (17.11.2026) — забывать там же: прямой `Cache::forget` старого ключа ничего не снимал.
        StaffCache::forget('nav.counts:'.Surface::Site->value.':'.$user->id);
        if (! app()->runningInConsole()) {
            request()->attributes->remove('nav.counts:'.Surface::Site->value.':'.$user->id);
        }
    }

    private static function counts(User $user, Surface $surface): array
    {
        // Счётчики админа общие на всех админов; админ, который ещё и менеджер, на сайте видит свои — ключ его.
        // Админ-модератор (Катя) — своя вкладка по умолчанию, и число у таба своё.
        $key = "nav.counts:{$surface->value}:".($user->isAdmin() && ! $user->isManager() && ! $user->isModerator() ? 'staff' : $user->id);

        // Кэш в базе — за страницу его читают по два-три раза: из него один раз на запрос.
        $counts = self::perRequest($key, fn () => self::countsCached($user, $surface, $key));
        // Избранное у каждого своё, а кэш админов общий: без этого админ видел в шапке число закладок того, кто посчитал первым.
        if (str_ends_with($key, ':staff') && isset($counts['totals']['/account/favorites'])) {
            $counts['totals']['/account/favorites'] = self::perRequest('nav.favorites:'.$user->id,
                fn () => Offer::visibleTo($user)->whereHas('favorites', fn ($f) => $f->where('user_id', $user->id))->count());
        }

        return $counts;
    }

    private static function countsCached(User $user, Surface $surface, string $key): array
    {
        // Общие счётчики админов сбрасывает событие (`forgetStaffCounts`). Личные на сайте — тоже событие: каталога
        // (`forgetSiteCounts`) или адресное (`touch`); срок — запасной, на случай, если событие забыли (гаснет окно галереи).
        $own = ! str_ends_with($key, ':staff');
        $site = $own && $surface === Surface::Site;

        return StaffCache::remember($key, $site ? 300 : 30, function () use ($user, $surface) {
            $day = now()->subDay();
            if ($surface === Surface::Park) {
                return ['totals' => [
                    '/requests' => City::requestsList(Request::whereIn('state', RequestState::open()), $user)->count(),
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
                    '/clients' => $user->isLogist() ? Client::of($user)->whereNull('archived_at')->count() : 0,
                    // Стоянки — сколько машин на них стоит сейчас.
                    '/depots' => $user->isLogist() ? Transport::where('logist_id', $user->id)->whereNotNull('from_depot_id')->whereIn('state', [TransportState::New, TransportState::Logist, TransportState::Assigned, TransportState::Agreed])->count() : 0,
                    '/contractors' => $user->isAdmin() || $user->isLogist() ? User::contractorsFor($user)->count() : 0,
                    // Ждут выплаты логисту: свои — логисту, все — админу. Логисту ещё свои деньги (18.10.2026): привезённые заявки,
                    // за которые заказчик не заплатил, и вывозы, где он не заплатил исполнителю, — те же строки, что в «Оплатах».
                    '/money' => ($user->isAdmin() || $user->isLogist()
                        ? Invoice::where('direction', 'owed')->where('kind', ChargeKind::Tow->value)->where('state', InvoiceState::Issued)
                            ->when($user->isAdmin(),
                                fn ($q) => $q->whereIn('party_id', User::withRole(Role::Logist)->whereNotNull('party_id')->select('party_id')),
                                fn ($q) => $q->where('party_id', $user->party_id ?? 0))->count()
                        : 0) + ($user->isLogist() && ! $user->isAdmin()
                        ? Transport::where('logist_id', $user->id)->where('state', TransportState::Delivered)->where(fn ($q) => $q
                            ->where(fn ($c) => $c->where('origin', 'order')->whereNotNull('client_price')->whereNull('client_paid_at'))
                            ->orWhere(fn ($x) => $x->whereNotNull('executor_cost')->whereNull('executor_paid_at')->whereNotNull('executor_id')->where('executor_id', '!=', $user->id)))->count()
                        : 0),
                ], 'fresh' => []];
            }
            if ($surface->single()) {
                return ['totals' => [], 'fresh' => []];
            }
            if ($surface === Surface::Partner) {
                return ['totals' => ['/offers' => PartnerOffers::for($user)->notEmptyDraft()->count()], 'fresh' => []];
            }
            if ($surface === Surface::Crm && ! $user->canManageCrm()) {
                // Таб — как список без вкладки: «Без закупочной цены» его группы.
                return ['totals' => ['/offers' => OfferController::scopeFor(OfferController::home($user), Offer::inCrm($user)->notEmptyDraft(), admin: false)->count()], 'fresh' => []];
            }
            if ($surface === Surface::Crm) {
                return ['totals' => [
                    // Как список без вкладки — «Без продажной цены», вместе с черновиками из закупок.
                    '/offers' => OfferController::scopeFor(OfferController::home($user), Offer::notEmptyDraft())->count(),
                ], 'fresh' => []];
            }
            // Таб «Предложения» — то же, что в каталоге (`Offer::siteList`: открытое без сделки плюс
            // сделка, открытая менеджеру): срок вышел или идёт сделка — с сайта ушло.
            // Регионы человека (22.10.2026) — как каталог по умолчанию: число — то, что он увидит, открыв раздел.
            $open = Offer::siteList($user)->inRegionsOf($user);
            $gallery = Offer::visibleTo($user)->inGallery()->inRegionsOf($user);

            // Ноль — тоже число («Покупатели 0»), нет только разделов, которых у роли нет.
            return ['totals' => array_filter([
                '/offers' => (clone $open)->count(),
                '/gallery' => (clone $gallery)->count(),
                // Закупок в разделах сайта нет (число не показывалось нигде, а стоило запроса на каждую открытую закупку).
                // Сделки в работе — то, что стоит строками на экране «Сделки».
                '/deals' => match (true) {
                    // Только сделки в работе, как в списке (11.10.2026): подтверждения и закончившееся — строками в конце.
                    // Плюс «Ждут решения» (02.11.2026): срок приёма вышел, его подтверждение ждёт выбора — строки того же экрана.
                    $user->hasDeals() => Deal::where('buyer_id', $user->id)->live()->count()
                        + DealController::pending($user)->count(),
                    $user->isAdmin() => Deal::active()->count(),
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
                // Не просмотренные в продаже (21.10.2026, владелец: «менеджеры не видят, когда приходят новые машины») — как
                // чип каталога; гаснет, как только открыл (`ReadOffer`). У сотрудников счётчики общие — им новые за сутки.
                // Покупателю — по-прежнему новые за сутки: решали про менеджеров.
                '/offers' => $user->hasRole(Role::Manager, Role::SuperBuyer)
                    ? CatalogQuery::unread(Offer::siteList($user)->inRegionsOf($user), $user)->count()
                    : CatalogQuery::fresh(clone $open, $user)->count(),
                '/gallery' => (clone $gallery)->where('gallery_from', '>', $day)->count(),
            ]];
        }, match (true) {
            ! $own => StaffCache::COUNTS, $site => StaffCache::SITE, default => null
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

    /**
     * Сколько сделок ждут его (`Deal::MANAGER_TURN_SQL`, строки раздела «Ваш ход»): «Сделки» — все, с гаражными (02.11.2026), «Гараж» — гаражные. Просьба «Оплатите счёт», а счета сделки уже
     * оплачены (маршрут не догнал), — не считается: строка «Сделок» её не показывает (11.10.2026). Им же — значок приложения
     * (`User::badgeCount`), без остальных счётчиков таб-бара.
     *
     * @return array{deals: int, garage: int}
     */
    public static function asks(User $user): array
    {
        return self::perRequest("nav.asks:{$user->id}", function () use ($user) {
            // Гаражу — по-прежнему просьбы (и вопрос про новую закупочную — `Deal::managerTask` ставит его в «Ваш ход»): строка
            // гаража пишет «ваш ход» только по ним (цену продажи Совкомбанка называет «Продаю»).
            // Сделки, а не просьбы (27.10.2026: «Укажите покупателя» — ход без просьбы — в число не входил, бейдж 1 при двух делах).
            // Параллельная (07.11.2026) — его сделка, как идущая: ход по её просьбе (`MANAGER_TURN_SQL`).
            $asks = Deal::where('buyer_id', $user->id)->live()->whereRaw(Deal::MANAGER_TURN_SQL)
                ->selectRaw('count(*) filter (where garage_payer is null) as deals, count(*) filter (where garage_payer is not null and (exists (select 1 from requirements r
                    where r.deal_id = deals.id and r.done_at is null) or exists (select 1 from reprice_asks a where a.deal_id = deals.id and a.state in (\'pending\', \'countered\')))) as garage')->first();
            $paid = self::paidPayAsks($user);

            // «Сделки» — и гаражные (02.11.2026: они теперь и строками «Сделок», «Ваш ход»); «Гараж» — свои, как было.
            return ['deals' => max(0, (int) $asks->deals + (int) $asks->garage - $paid['deals'] - $paid['garage']), 'garage' => max(0, (int) $asks->garage - $paid['garage'])];
        });
    }

    private static function count(User $user, Surface $surface): array
    {
        // Партнёру бейджей нет: уведомлений у него нет, новое — само число таба.
        if ($surface === Surface::Partner) {
            return [];
        }
        // Чату — только непрочитанное в чатах: сотруднику — и площадки, как в CRM.
        if ($surface === Surface::Chat) {
            return array_filter([Chat::ROOT => $user->unreadChats(platform: Chat::staffView($user))]);
        }
        // Почте — непрочитанные «Входящие» и что ждёт в «Из писем», как у CRM.
        if ($surface === Surface::Mail) {
            return $user->canCrmMail() ? array_filter(['/work/mail' => Boxes::unreadCount(Scope::Offers), '/offers/from-mail' => Boxes::registerCount(Scope::Offers)]) : [];
        }
        // Оплатам — сотруднику оплаты «Пришли?», менеджеру — счета к оплате (то же число, что на значке, `User::badgeCount`).
        if ($surface === Surface::Pay) {
            return array_filter([PayView::home($user) => $user->badgeCount(Surface::Pay)]);
        }
        $badges = ['/account/notifications' => $user->unreadCount()];

        if ($surface === Surface::Crm && ! $user->canManageCrm()) {
            // Модератору из общих бейджей — почта по галке, чаты площадки и «Оплаты».
            $keys = [...($user->canCrmMail() ? ['/offers/from-mail', '/work/mail'] : []), ...($user->canEditDeals() ? ['/work/chats', '/work/money'] : [])];

            $staff = self::staffCounts($surface);
            $staff['/work/money'] = $staff['money:deals'] ?? 0;
            $staff['/work/chats'] = ($staff['/work/chats'] ?? 0) + $user->unreadDirectChats();

            return array_filter($badges + array_intersect_key($staff, array_flip($keys)));
        }
        if ($surface === Surface::Park || $surface === Surface::Crm) {
            $staff = self::staffCounts($surface);
            // Чаты площадки — общие на всех сотрудников (кэш), свои лички — каждому свои (22.11.2026).
            if ($surface === Surface::Crm && $user->canEditDeals()) {
                $staff['/work/chats'] = ($staff['/work/chats'] ?? 0) + $user->unreadDirectChats();
            }

            return array_filter($badges + $staff);
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
            // Просьбы по гаражной сделке (страховая ещё идёт) — на табе «Гараж», где её ведут, и в «Сделках» (02.11.2026).
            $asks = self::asks($user);
            $badges['/deals/asks'] = $asks['deals'];
            $badges['/deals'] = $badges['/deals/asks'] + $badges['/buyers'];
            // Вывоз, порученный ему, горит в логистике (17.10.2026), не здесь.
            $badges['/garage'] = $asks['garage'];
        }
        if ($user->hasDeals()) {
            // «Оплаты» в кабинете горят тем, что ждёт его: оплатить, просрочено, указать покупателя (11.10.2026: в «Оплатах»
            // красное «Оплатить», а строка кабинета молчала).
            // Число — строки самих «Оплат», где нужен он (`DealMoney::needsAction`), иначе бейдж и список расходятся; расчёт
            // тяжёлый — полминуты в кэше, как итоги таб-бара.
            // тяжёлый (все сделки человека со счетами) — до события о нём (`touch`), срок — запасной.
            // С 22.11.2026 «Оплаты» — экран «Комиссии · ДКП»: число — его «К оплате» (`MoneyBook::ownToPay`), как у значка XCAR Pay.
            $badges['/account/money'] = StaffCache::remember("nav.money:{$user->id}", 600,
                fn () => MoneyBook::ownToPay($user), null);
        }
        if ($user->holdsAsBuyer()) {
            // Что ждут от него по сделкам — на табе «Сделки», как у менеджера; по гаражной — на табе «Гараж» (20.10.2026).
            $asks = self::asks($user);
            if ($user->isSuperBuyer()) {
                $badges['/deals/asks'] = $asks['deals'];
                $badges['/deals'] = $badges['/deals/asks'];
            }
            if ($user->canGarage()) {
                $badges['/garage'] = $asks['garage'];
            }
        }
        if ($user->isAdmin() && ! $user->isManager()) {
            // Админу «Сделки» горят тем же, что сделки в CRM: ход наш.
            $badges['/deals'] = self::staffCounts(Surface::Crm)['deals'] ?? 0;
        }
        if ($user->canChat() || $user->isAdmin()) {
            // Свои чаты плюс чаты покупателей, где менеджер — вторая сторона; сотруднику — и площадки.
            $badges[Chat::ROOT] = $user->unreadChats(platform: $surface === Surface::Crm);
        }

        return array_filter($badges);
    }

    /** Наш ход по идущим сделкам, и гаражным тоже, — бейдж «Предложений» (сделки — их вкладками с 16.11.2026). */
    private static function hotDeals(): int
    {
        return Position::where('track', 'sale')
            ->whereHas('offer.deal', fn ($d) => $d->active())
            ->needsUs()
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

        return StaffCache::remember("nav.staff:{$surface->value}".($city ? ":{$city}" : ''), 30, function () use ($surface) {
            if ($surface === Surface::Park) {
                return [
                    // Бейдж «Из писем» — число дел, которые надо завести: у одинокого письма-заявки цепочки нет.
                    '/requests/from-mail' => Boxes::registerCount(Scope::Park),
                    // Бейдж «Оплаты» — работа управляющего: выставить за прошлый месяц, отправить выставленное, просрочено.
                    '/money' => Closing::pendingCount() + Invoice::ofPark()->where('state', InvoiceState::Issued)
                        ->where(fn ($w) => $w->whereDate('due_at', '<', now()->toDateString())->orWhereIn('id', Invoice::toSend()->select('id')))->count(),
                    // Бейдж «Почты» — дела «Требуют внимания», как у пилюли (02.11.2026, владелец: «счётчик нелогичный» — горели
                    // 111 непрочитанных веток: Сергей читает почту в Mail.ru, здесь они не гаснут).
                    '/mail' => Boxes::attentionCount(Scope::Park),
                ];
            }
            $hot = self::hotDeals();
            $badges = [
                // Раздел «Предложения» горит и подтверждениями, и оценками менеджеров, ждущими решения (11.10.2026).
                // С 16.11.2026 — и нашим ходом по сделкам: сделки живут вкладками «Предложений».
                '/offers' => Bid::where('state', BidState::Active)->count()
                    + Offer::where('state', OfferState::Draft)->whereHas('estimates', fn ($e) => $e->needsDecision())->count()
                    + $hot,
                // Наш ход по сделкам отдельно — бейдж «Сделок» админа на сайте (`badges`).
                'deals' => $hot,
                '/offers/from-mail' => Boxes::registerCount(Scope::Offers),
                // Непрочитанные письма «Входящих» (17.11.2026: письма, а не ветки — то же число, что у пилюли).
                '/work/mail' => Boxes::unreadCount(Scope::Offers),
                '/work/chats' => Chat::whereNull('manager_id')->where('unread_for_staff', '>', 0)->count(),
                // Деньги горят делом в «Оплатах»: менеджер пишет, что оплатил; не прошла по ссылке; выплатить. Выписки нет.
                '/work/money' => MoneyController::todo(),
                // Модератору — только его часть «Оплат» (без выплат и ссылок без сделки), `count` подменяет ею '/work/money'.
                'money:deals' => MoneyController::todo(dealsOnly: true),
            ];

            return $badges;
        }, StaffCache::COUNTS);
    }

    /** Только счётчики навигации (чат, почта, оплата, парковка): раскладку вкладок такое событие не меняет. */
    public static function forgetStaffBadges(): void
    {
        StaffCache::bump(StaffCache::COUNTS);
    }

    /** Сброс общих счётчиков сотрудников — бейджей и итогов таб-бара; личные живут свои полминуты. */
    public static function forgetStaffCounts(): void
    {
        // Раскладка вкладок и числа колеса — и счётчики навигации всех хостов и городов: две метки вместо дюжины удалений
        // (`StaffCache`); пересчитает первый, кому понадобится, остальные возьмут его результат.
        DealTabs::forget();
        OfferTabs::forget();
        self::forgetStaffBadges();
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
            $paths[] = Chat::ROOT;
            $paths[] = '/garage';
            $paths[] = '/account/money';
            $paths[] = '/buyers';
            $paths[] = '/account/buyers';
            $paths[] = '/buyers/interest';
            $paths[] = '/deals/asks';
        }
        if ($surface === Surface::Crm) {
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
            array_push($items, self::link('Почта', '/work/mail'), self::link('Чаты', '/work/chats'), self::link('Оплаты', '/work/money'));
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

        // Путь под псевдонимом раздела (`match` пункта шире его адреса).
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

    /**
     * Таб, которому принадлежит экран запроса: его адрес или null. Активен самый точный пункт («Деньги» в /account/money —
     * «Кабинет», «Покупатели» в /buyers — «Сделки»); экран может назвать свой таб сам (`nav.tab`): у дела ожидаемой ТС адрес
     * /cars, а таб — «Заявки». Кладётся в `<body data-nav-tab>`: таб-бар постоянный и текущий таб берёт из страницы, а не из
     * своей копии в снимке (`tabbar_controller`), память разделов — по нему (`lib/sections.js`).
     */
    public static function currentTab(?User $user): ?string
    {
        $request = request();
        if (! $request->attributes->has('nav.current')) {
            $path = $request->attributes->get('nav.tab') ?? self::origin($user)['href'] ?? '/'.ltrim($request->path(), '/');
            $request->attributes->set('nav.current', collect(self::tabs($user))->filter(fn ($t) => self::isCurrent($t, $path))
                ->sortByDesc(fn ($t) => self::matchLength($t, $path))->first()['href'] ?? null);
        }

        return $request->attributes->get('nav.current');
    }

    /**
     * Раздел, из которого открыли страницу объекта (18.11.2026: машина из «Галереи» или «Избранного» считалась
     * «Предложениями» — подсветка таб-бара прыгала, а таб «Предложения» потом открывал эту машину вместо своего списка).
     * Как в приложении: страница, открытая внутри раздела, остаётся в нём. Раздел едет в адресе — `in=/account` (дописывает
     * ссылке `lib/sections.js` в момент касания) или контекст списка `list=gallery`; принимается только свой таб человека.
     *
     * @return array{label: string, href: string}|null
     */
    public static function origin(?User $user): ?array
    {
        $request = request();
        $in = $request->query('in');
        if (! is_string($in) || $in === '') {
            $in = $request->query('list') === 'gallery' ? '/gallery' : null;
        }
        if ($in === null) {
            return null;
        }
        foreach (self::tabs($user) as $tab) {
            if ($tab['href'] === $in) {
                return ['label' => $tab['label'], 'href' => $tab['href']];
            }
        }

        return null;
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

    /** icon — значок в таб-баре (`x-ui.tab-icon`): у разделов CRM вместо капсулы с числом. */
    /**
     * Куда ведёт раздел «Предложения» CRM: сразу на вкладку человека по умолчанию (`go`), без круга через редирект
     * `/offers` → `/offers?preset=…` (17.11.2026: первый вход в раздел платил два запроса). Ключ раздела — по-прежнему `/offers`.
     */
    private static function offersHome(?User $user): ?string
    {
        return $user ? '/offers?preset='.OfferController::home($user) : null;
    }

    private static function item(string $label, string $href, string|array|null $match = null, bool $tab = true, bool $capsule = true, ?string $icon = null): array
    {
        return ['label' => $label, 'href' => $href, 'match' => $match ?? $href, 'tab' => $tab, 'capsule' => $capsule, 'icon' => $icon];
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
