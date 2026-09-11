<?php

namespace App\Support;

use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\DealState;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Position;
use App\Workflow\Requirement;
use App\Workflow\WaitsFor;

/**
 * Навигация одним списком на роль и поверхность: из него строятся капсулы
 * второго ряда шапки (десктоп) и таб-бар (телефон, пункты с tab=true плюс
 * «Кабинет»). Счётчики — отдельно, их перечитывает live.
 */
final class Nav
{
    /** @return list<array{label: string, icon: string, href: string, match: string, tab: bool}> */
    public static function sections(?User $user, string $surface = 'site'): array
    {
        if ($surface === 'park') {
            return [
                self::item('Заявки', 'flag', '/zayavki'),
                self::item('Машины', 'car', '/mashiny'),
                self::item('Стоянки', 'park', '/stoyanki'),
                self::item('Почта', 'mail', '/pochta'),
                self::item('Клиенты', 'user', '/klienty', tab: false),
                self::item('Из писем', 'mail', '/kandidaty', tab: false),
            ];
        }

        if ($user?->isStaff()) {
            return [
                self::item('Офферы', 'car', '/admin/offers'),
                self::item('Почта', 'mail', '/admin/pochta'),
                self::item('Чаты', 'chat', '/admin/chaty', tab: false),
                self::item('Сделки', 'deal', '/admin/sdelki'),
                self::item('Закупки', 'cart', '/admin/zakupki'),
                self::item('Кандидаты', 'mail', '/admin/kandidaty', tab: false),
                self::item('Страховые', 'shield', '/admin/strahovye', tab: false),
                self::item('Ящики', 'mail', '/admin/yashchiki', tab: false),
                self::item('Шаблоны', 'file', '/admin/shablony', tab: false),
            ];
        }

        if ($user?->role === Role::Manager) {
            return [
                self::item('Предложения', 'car', '/', '/'),
                self::item('Галерея', 'photo', '/galereya', tab: false),
                self::item('Закупки', 'cart', '/zakupki'),
                self::item('Сделки', 'deal', '/lk/sdelki'),
                self::item('Уведомления', 'bell', '/lk/uvedomleniya', capsule: false),
            ];
        }

        if ($user) {
            return [
                self::item('Предложения', 'car', '/', '/'),
                self::item('Галерея', 'photo', '/galereya'),
                self::item('Закупки', 'cart', '/zakupki', tab: false),
                self::item('Избранное', 'bookmark', '/lk/izbrannoe', capsule: false),
                self::item('Уведомления', 'bell', '/lk/uvedomleniya', capsule: false),
            ];
        }

        return [
            self::item('Предложения', 'car', '/', '/'),
            self::item('Галерея', 'photo', '/galereya'),
            self::item('Закупки', 'cart', '/zakupki'),
        ];
    }

    /** Пункты таб-бара: до четырёх разделов и «Кабинет». */
    public static function tabs(?User $user, string $surface = 'site'): array
    {
        $tabs = array_values(array_filter(self::sections($user, $surface), fn ($i) => $i['tab']));
        $tabs = array_slice($tabs, 0, 4);
        $tabs[] = $user
            ? self::item('Кабинет', 'user', $surface === 'park' ? '/kabinet' : '/lk')
            : self::item('Войти', 'login', '/vhod');

        return $tabs;
    }

    /** Капсулы второго ряда шапки. */
    public static function capsules(?User $user, string $surface = 'site'): array
    {
        return array_values(array_filter(self::sections($user, $surface), fn ($i) => $i['capsule']));
    }

    /** Верхний ряд капсул: справочные страницы витрины или ссылка на сайт со стоянки. */
    public static function top(?User $user, string $surface = 'site'): array
    {
        if ($surface === 'park') {
            return [self::item('На сайт', 'car', rtrim(config('app.url'), '/').'/')];
        }

        return [
            self::item('Вопросы', 'file', '/voprosy'),
            self::item('Контакты', 'mail', '/kontakty'),
        ];
    }

    /**
     * Пилюли кабинета группами: у всех «Личное», у сотрудника перед ним «Работа».
     *
     * @return array<string, list<array{label: string, href: string, match: string}>>
     */
    public static function cabinet(User $user, string $surface = 'site'): array
    {
        if ($surface === 'park') {
            return ['' => [
                self::link('Сводка', '/kabinet', exact: true),
                self::link('Клиенты', '/klienty'),
                self::link('Из писем', '/kandidaty'),
                self::link('Шаблоны', rtrim(config('app.url'), '/').'/admin/shablony'),
                self::link('Профиль', rtrim(config('app.url'), '/').'/lk/profil'),
            ]];
        }

        $personal = [self::link('Сводка', '/lk', exact: true)];
        if ($user->isStaff() || $user->role === Role::Manager) {
            $personal[] = self::link('Заявки', '/lk/stavki');
            $personal[] = self::link('Сделки', '/lk/sdelki');
        } else {
            $personal[] = self::link('Интерес', '/lk/interesy');
        }
        $personal[] = self::link('Избранное', '/lk/izbrannoe');
        $personal[] = self::link('Уведомления', '/lk/uvedomleniya');
        $personal[] = self::link('Профиль', '/lk/profil');

        if (! $user->isStaff()) {
            return ['' => $personal];
        }

        return [
            'Работа' => [
                self::link('Кандидаты', '/admin/kandidaty'),
                self::link('Страховые', '/admin/strahovye'),
                self::link('Ящики', '/admin/yashchiki'),
                self::link('Шаблоны', '/admin/shablony'),
                self::link('Чаты', '/admin/chaty'),
                self::link('Стоянка', 'http://'.config('xcar.park_host').'/'),
            ],
            'Личное' => $personal,
        ];
    }

    /** Счётчики на пунктах: путь → число. Ноль не отдаётся. */
    public static function badges(?User $user, string $surface = 'site'): array
    {
        if (! $user) {
            return [];
        }
        $badges = [];
        if ($surface === 'park') {
            $badges['/zayavki'] = \App\Park\Request::where('state', \App\Park\RequestState::New)->count();
            $badges['/pochta'] = \App\Mail\Thread::where('unread_count', '>', 0)->whereHas('account', fn ($a) => $a->where('scope', \App\Mail\Scope::Park))->count();

            return array_filter($badges);
        }
        if ($user->isStaff()) {
            $badges['/admin/offers'] = Bid::where('state', BidState::Active)->count();
            $badges['/admin/pochta'] = \App\Mail\Thread::where('unread_count', '>', 0)->whereHas('account', fn ($a) => $a->where('scope', \App\Mail\Scope::Offers))->count();
            $badges['/admin/chaty'] = \App\Chats\Chat::where('unread_for_staff', '>', 0)->count();
            $badges['/admin/sdelki'] = Position::where('track', 'sale')
                ->whereHas('offer.deal', fn ($d) => $d->where('state', DealState::Active))
                ->where(fn ($w) => $w->where('deadline_at', '<', now())->orWhereHas('stage', fn ($s) => $s->where('waits_for', WaitsFor::Us)))
                ->count();
        } else {
            $badges['/lk/sdelki'] = Requirement::where('user_id', $user->id)->whereNull('done_at')->count();
        }
        $badges['/lk/uvedomleniya'] = $user->unreadCount();
        $badges['/lk/izbrannoe'] = \App\Offers\Favorite::where('user_id', $user->id)->count();

        return array_filter($badges);
    }

    /** Все пути, у которых бывает счётчик — для стрима бейджей. */
    public static function badgePaths(?User $user, string $surface = 'site'): array
    {
        $paths = array_column(self::sections($user, $surface), 'href');
        if ($surface !== 'park') {
            $paths[] = '/lk/uvedomleniya';
            $paths[] = '/lk/izbrannoe';
        }

        return array_values(array_unique($paths));
    }

    public static function badgeId(string $href): string
    {
        return 'badge-'.trim(str_replace('/', '-', $href), '-');
    }

    public static function isCurrent(array $item, string $path): bool
    {
        $match = $item['match'];
        if (str_starts_with($match, 'http')) {
            return false;
        }

        return $match === '/' ? $path === '/' : str_starts_with($path, $match);
    }

    private static function item(string $label, string $icon, string $href, ?string $match = null, bool $tab = true, bool $capsule = true): array
    {
        return ['label' => $label, 'icon' => $icon, 'href' => $href, 'match' => $match ?? $href, 'tab' => $tab, 'capsule' => $capsule];
    }

    private static function link(string $label, string $href, bool $exact = false): array
    {
        return ['label' => $label, 'href' => $href, 'match' => $exact ? '='.$href : $href];
    }

    /** Активная пилюля кабинета: точное совпадение для сводки, префикс для остальных. */
    public static function isCurrentLink(array $link, string $path): bool
    {
        if (str_starts_with($link['match'], '=')) {
            return $path === substr($link['match'], 1);
        }

        return self::isCurrent($link, $path);
    }
}
