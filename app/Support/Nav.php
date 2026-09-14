<?php

namespace App\Support;

use App\Chats\Chat;
use App\Http\Middleware\MarkInstalled;
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
use Illuminate\Support\Facades\Cache;

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
                self::item('Заявки', 'flag', '/', ['/', '/zayavki']),
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
                self::item('Предложения', 'car', '/', ['/', '/offers']),
                self::item('Галерея', 'photo', '/galereya'),
                self::item('Закупки', 'cart', '/zakupki'),
                self::item('Уведомления', 'bell', '/lk/uvedomleniya', capsule: false),
            ];
        }

        if ($user?->role === Role::Manager) {
            return [
                self::item('Предложения', 'car', '/', ['/', '/offers']),
                self::item('Галерея', 'photo', '/galereya', tab: false),
                self::item('Закупки', 'cart', '/zakupki'),
                self::item('Сделки', 'deal', '/lk/sdelki'),
                self::item('Уведомления', 'bell', '/lk/uvedomleniya', capsule: false),
            ];
        }

        if ($user?->isApproved()) {
            return [
                self::item('Предложения', 'car', '/', ['/', '/offers']),
                self::item('Галерея', 'photo', '/galereya'),
                self::item('Избранное', 'bookmark', '/lk/izbrannoe', capsule: false),
                self::item('Уведомления', 'bell', '/lk/uvedomleniya', capsule: false),
            ];
        }

        // Сайт закрыт: гостю и ждущему решения — только обращение.
        return [];
    }

    /** Пункты таб-бара: до четырёх разделов и «Кабинет». */
    public static function tabs(?User $user, ?Surface $surface = null): array
    {
        $surface ??= Surface::current();
        $tabs = array_values(array_filter(self::sections($user, $surface), fn ($i) => $i['tab']));
        $tabs = array_slice($tabs, 0, 4);
        if ($surface === Surface::Site && $user && ! $user->isApproved()) {
            return [self::item('Контакты', 'mail', '/kontakty'), self::item('Выйти', 'exit', '/vyhod', capsule: false) + ['logout' => true]];
        }
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

        if (! $user?->isApproved()) {
            return [self::item('Контакты', 'mail', '/kontakty')];
        }
        $top = [
            self::item('Вопросы', 'file', '/voprosy'),
            self::item('Контакты', 'mail', '/kontakty'),
        ];

        // Ссылок в CRM с сайта нет — владелец: сайт для покупателей, сотрудники ходят по своему адресу.
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
        // В установленном приложении подвала нет — документы живут здесь.
        if (MarkInstalled::installed(request())) {
            return ['' => $links, 'Документы' => [self::link('Обработка данных', '/obrabotka-dannyh'), self::link('Соглашение', '/soglashenie')]];
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

        if ($surface === Surface::Park || $surface === Surface::Crm) {
            return array_filter($badges + self::staffCounts($surface));
        }

        if (! $user->isStaff()) {
            $badges['/lk/sdelki'] = Requirement::where('user_id', $user->id)->whereNull('done_at')->count();
            $badges['/lk/chaty'] = (int) Chat::where('user_id', $user->id)->sum('unread_for_user');
        }
        $badges['/lk/izbrannoe'] = Favorite::where('user_id', $user->id)->count();

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
                    '/' => Request::where('state', RequestState::New)->count(),
                    '/zayavki/iz-pisem' => Candidate::where('scope', Scope::Park)->where('state', CandidateState::New)->count(),
                    '/pochta' => Thread::where('unread_count', '>', 0)->whereHas('account', fn ($a) => $a->where('scope', Scope::Park))->count(),
                ];
            }
            $badges = [
                '/' => Bid::where('state', BidState::Active)->count(),
                '/predlozheniya/iz-pisem' => Candidate::where('scope', Scope::Offers)->where('state', CandidateState::New)->count(),
                '/rabota/pochta' => Thread::where('unread_count', '>', 0)->whereHas('account', fn ($a) => $a->where('scope', Scope::Offers))->count(),
                '/rabota/chaty' => Chat::where('unread_for_staff', '>', 0)->count(),
                '/rabota/sdelki' => Position::where('track', 'sale')
                    ->whereHas('offer.deal', fn ($d) => $d->where('state', DealState::Active))
                    ->where(fn ($w) => $w->where('deadline_at', '<', now())->orWhereHas('stage', fn ($s) => $s->where('waits_for', WaitsFor::Us)))
                    ->count(),
            ];
            $badges['/rabota'] = $badges['/rabota/sdelki'] + $badges['/rabota/pochta'] + $badges['/rabota/chaty'];

            return $badges;
        });
    }

    public static function forgetStaffCounts(): void
    {
        Cache::forget('nav.staff:crm');
        Cache::forget('nav.staff:park');
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
                array_push($items, ...array_filter($links, fn ($l) => ! str_starts_with($l['match'], '=')));
            }
        }
        if ($surface === Surface::Crm) {
            array_push($items, self::link('Сделки', '/rabota/sdelki'), self::link('Почта', '/rabota/pochta'), self::link('Чаты', '/rabota/chaty'));
        }

        // Сам корень раздела или экран с пилюлями кабинета — «назад» не нужен;
        // страницы настроек CRM — обычные экраны в глубине «Настроек».
        $roots = array_filter(array_column($items, 'href'), fn ($h) => ! str_starts_with($h, '/nastroyki/'));
        if (in_array($path, $roots, true)) {
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

        // Путь под псевдонимом раздела (/predlozheniya/123 → «Предложения», /).
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
