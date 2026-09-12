<?php

namespace App\Support;

use App\Chats\Chat;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Scope;
use App\Mail\Thread;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\DealState;
use App\Offers\Favorite;
use App\Park\Request;
use App\Park\RequestState;
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
    /** @return list<array{label: string, icon: string, href: string, match: string|list<string>, tab: bool, capsule: bool}> */
    public static function sections(?User $user, ?Surface $surface = null): array
    {
        $surface ??= Surface::current();

        if ($surface === Surface::Park) {
            return [
                self::item('Заявки', 'flag', '/zayavki'),
                self::item('Машины', 'car', '/mashiny'),
                self::item('Стоянки', 'park', '/stoyanki'),
                self::item('Почта', 'mail', '/pochta'),
                self::item('Клиенты', 'user', '/klienty', tab: false),
            ];
        }

        if ($surface === Surface::Crm) {
            return [
                self::item('Предложения', 'car', '/', ['/', '/predlozheniya']),
                self::item('Галерея', 'photo', '/galereya'),
                self::item('Работа', 'deal', '/rabota'),
                self::item('Закупки', 'cart', '/zakupki'),
                self::item('Настройки', 'settings', '/nastroyki', tab: false),
            ];
        }

        if ($user?->isStaff()) {
            return [
                self::item('Предложения', 'car', '/', '/'),
                self::item('Галерея', 'photo', '/galereya'),
                self::item('Закупки', 'cart', '/zakupki'),
                self::item('Уведомления', 'bell', '/lk/uvedomleniya', capsule: false),
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
                self::item('Избранное', 'bookmark', '/lk/izbrannoe', capsule: false),
                self::item('Уведомления', 'bell', '/lk/uvedomleniya', capsule: false),
            ];
        }

        return [
            self::item('Предложения', 'car', '/', '/'),
            self::item('Галерея', 'photo', '/galereya'),
        ];
    }

    /** Пункты таб-бара: до четырёх разделов и «Кабинет». */
    public static function tabs(?User $user, ?Surface $surface = null): array
    {
        $tabs = array_values(array_filter(self::sections($user, $surface), fn ($i) => $i['tab']));
        $tabs = array_slice($tabs, 0, 4);
        $tabs[] = $user
            ? self::item('Кабинет', 'user', '/lk')
            : self::item('Войти', 'login', '/vhod');

        return $tabs;
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
            return [self::item('На сайт', 'car', Surface::Site->url())];
        }

        if ($surface === Surface::Crm) {
            return [
                self::item('На сайт', 'car', Surface::Site->url()),
                self::item('Стоянка', 'park', Surface::Park->url()),
            ];
        }

        $top = [
            self::item('Вопросы', 'file', '/voprosy'),
            self::item('Контакты', 'mail', '/kontakty'),
        ];
        if ($user?->isStaff()) {
            $top[] = self::item('CRM', 'settings', Surface::Crm->url());
        }

        return $top;
    }

    /**
     * Пилюли кабинета группами.
     *
     * @return array<string, list<array{label: string, href: string, match: string}>>
     */
    public static function cabinet(User $user, ?Surface $surface = null): array
    {
        $surface ??= Surface::current();

        if ($surface === Surface::Park) {
            return ['' => [
                self::link('Сводка', '/lk', exact: true),
                self::link('Клиенты', '/klienty'),
                self::link('Уведомления', '/lk/uvedomleniya'),
                self::link('Профиль', '/lk/profil'),
                self::link('Шаблоны', Surface::Crm->url('/nastroyki/shablony')),
            ]];
        }

        if ($surface === Surface::Crm) {
            return [
                '' => [self::link('Сводка', '/lk', exact: true)],
                'Настройки' => [
                    self::link('Страховые', '/nastroyki/strahovye'),
                    self::link('Ящики', '/nastroyki/yashchiki'),
                    self::link('Шаблоны', '/nastroyki/shablony'),
                    self::link('Метки', '/nastroyki/tegi'),
                    ...($user->isAdmin() ? [self::link('Пользователи', '/nastroyki/polzovateli')] : []),
                ],
                'Личное' => [
                    self::link('Уведомления', '/lk/uvedomleniya'),
                    self::link('Профиль', '/lk/profil'),
                ],
                'Переходы' => [
                    self::link('На сайт', Surface::Site->url()),
                    self::link('Стоянка', Surface::Park->url()),
                ],
            ];
        }

        $links = [self::link('Сводка', '/lk', exact: true)];
        if ($user->role === Role::Manager) {
            $links[] = self::link('Подтверждения', '/lk/stavki');
            $links[] = self::link('Сделки', '/lk/sdelki');
        } elseif (! $user->isStaff()) {
            $links[] = self::link('Интерес', '/lk/interesy');
        }
        if (! $user->isStaff()) {
            $links[] = self::link('Чаты', '/lk/chaty');
        }
        $links[] = self::link('Избранное', '/lk/izbrannoe');
        $links[] = self::link('Уведомления', '/lk/uvedomleniya');
        $links[] = self::link('Профиль', '/lk/profil');
        if ($user->isStaff()) {
            $links[] = self::link('CRM', Surface::Crm->url());
        }

        return ['' => $links];
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
        $badges = self::count($user, $surface);
        $memo?->set($key, $badges);

        return $badges;
    }

    private static function count(User $user, Surface $surface): array
    {
        $badges = ['/lk/uvedomleniya' => $user->unreadCount()];

        if ($surface === Surface::Park) {
            $badges['/zayavki'] = Request::where('state', RequestState::New)->count();
            $badges['/zayavki/iz-pisem'] = Candidate::where('scope', Scope::Park)->where('state', CandidateState::New)->count();
            $badges['/pochta'] = Thread::where('unread_count', '>', 0)->whereHas('account', fn ($a) => $a->where('scope', Scope::Park))->count();

            return array_filter($badges);
        }

        if ($surface === Surface::Crm) {
            $badges['/'] = Bid::where('state', BidState::Active)->count();
            $badges['/predlozheniya/iz-pisem'] = Candidate::where('scope', Scope::Offers)->where('state', CandidateState::New)->count();
            $badges['/rabota/pochta'] = Thread::where('unread_count', '>', 0)->whereHas('account', fn ($a) => $a->where('scope', Scope::Offers))->count();
            $badges['/rabota/chaty'] = Chat::where('unread_for_staff', '>', 0)->count();
            $badges['/rabota/sdelki'] = Position::where('track', 'sale')
                ->whereHas('offer.deal', fn ($d) => $d->where('state', DealState::Active))
                ->where(fn ($w) => $w->where('deadline_at', '<', now())->orWhereHas('stage', fn ($s) => $s->where('waits_for', WaitsFor::Us)))
                ->count();
            $badges['/rabota'] = $badges['/rabota/sdelki'] + $badges['/rabota/pochta'] + $badges['/rabota/chaty'];

            return array_filter($badges);
        }

        if (! $user->isStaff()) {
            $badges['/lk/sdelki'] = Requirement::where('user_id', $user->id)->whereNull('done_at')->count();
            $badges['/lk/chaty'] = (int) Chat::where('user_id', $user->id)->sum('unread_for_user');
        }
        $badges['/lk/izbrannoe'] = Favorite::where('user_id', $user->id)->count();

        return array_filter($badges);
    }

    /** Все пути, у которых бывает счётчик — для стрима бейджей. */
    public static function badgePaths(?User $user, ?Surface $surface = null): array
    {
        $surface ??= Surface::current();
        $paths = array_column(self::sections($user, $surface), 'href');
        $paths[] = '/lk/uvedomleniya';
        if ($surface === Surface::Site) {
            $paths[] = '/lk/izbrannoe';
            $paths[] = '/lk/chaty';
            $paths[] = '/lk/sdelki';
        }
        if ($surface === Surface::Crm) {
            $paths[] = '/rabota/sdelki';
            $paths[] = '/rabota/pochta';
            $paths[] = '/rabota/chaty';
            $paths[] = '/predlozheniya/iz-pisem';
        }
        if ($surface === Surface::Park) {
            $paths[] = '/zayavki/iz-pisem';
        }

        return array_values(array_unique($paths));
    }

    public static function badgeId(string $href): string
    {
        return 'badge-'.trim(str_replace('/', '-', $href), '-');
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

    private static function item(string $label, string $icon, string $href, string|array|null $match = null, bool $tab = true, bool $capsule = true): array
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
