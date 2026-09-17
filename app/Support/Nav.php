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
use App\Offers\Interest;
use App\Offers\InterestState;
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
    /** @return list<array{label: string, href: string, match: string|list<string>, tab: bool, capsule: bool}> */
    public static function sections(?User $user, ?Surface $surface = null): array
    {
        $surface ??= Surface::current();

        if ($surface === Surface::Park) {
            return [
                self::item('Заявки', '/', ['/', '/requests']),
                self::item('ТС', '/cars'),
                self::item('Стоянки', '/yards'),
                self::item('Почта', '/mail'),
                self::item('Клиенты', '/clients', tab: false),
            ];
        }

        if ($surface === Surface::Crm) {
            return [
                self::item('Предложения', '/', ['/', '/offers']),
                self::item('Галерея', '/gallery'),
                self::item('Работа', '/work'),
                self::item('Закупки', '/purchases'),
                // Кабинет CRM — это «Настройки»: профиль первой пилюлей, уведомления — под /account.
                self::item('Настройки', '/settings', ['/settings', '/account'], tab: false),
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

        if ($user?->role === Role::Manager) {
            return [
                self::item('Предложения', '/', ['/', '/offers']),
                self::item('Галерея', '/gallery', tab: false),
                self::item('Закупки', '/purchases'),
                self::item('Покупатели', '/account/buyers'),
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

    /** Пункты таб-бара: до четырёх разделов и «Кабинет». */
    public static function tabs(?User $user, ?Surface $surface = null): array
    {
        $surface ??= Surface::current();
        $tabs = array_values(array_filter(self::sections($user, $surface), fn ($i) => $i['tab']));
        $tabs = array_slice($tabs, 0, 4);
        if ($surface === Surface::Site && $user && ! $user->isApproved()) {
            return [self::item('Контакты', '/contacts'), self::item('Выйти', '/logout', capsule: false) + ['logout' => true]];
        }
        $tabs[] = match (true) {
            ! $user => self::item('Войти', '/login'),
            $surface === Surface::Crm => self::item('Настройки', '/settings', ['/settings', '/account']),
            default => self::item('Кабинет', '/account'),
        };

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
            return [self::item('На сайт', Surface::Site->url())];
        }

        if ($surface === Surface::Crm) {
            return [
                self::item('На сайт', Surface::Site->url()),
                self::item('Стоянка', Surface::Park->url()),
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
            return ['' => [
                self::link('Профиль', '/account', exact: true),
                self::link('Клиенты', '/clients'),
                self::link('Уведомления', '/account/notifications'),
                self::link('Шаблоны', Surface::Crm->url('/settings/templates')),
            ]];
        }

        if ($surface === Surface::Crm) {
            // Один раздел «Настройки»: профиль — его первый пункт, как /account на сайте.
            return [
                '' => [
                    self::link('Профиль', '/settings', exact: true),
                    ...($user->isAdmin() ? [self::link('Пользователи', '/settings/users')] : []),
                    self::link('Страховые', '/settings/insurers'),
                    self::link('Ящики', '/settings/mailboxes'),
                    self::link('Шаблоны', '/settings/templates'),
                    self::link('Метки', '/settings/tags'),
                    self::link('Уведомления', '/account/notifications'),
                ],
                'Переходы' => [
                    self::link('На сайт', Surface::Site->url()),
                    self::link('Стоянка', Surface::Park->url()),
                ],
            ];
        }

        $links = [self::link('Профиль', '/account', exact: true)];
        if ($user->role === Role::Manager) {
            // Интерес и приглашения — про людей: живут внутри «Покупателей»; подтверждения — начало сделки.
            $links[] = self::link('Покупатели', '/account/buyers', also: ['/account/interest', '/account/invites']);
            $links[] = self::link('Сделки', '/account/deals');
        } elseif ($user->isAdmin()) {
            $links[] = self::link('Пользователи', '/account/users');
            $links[] = self::link('Приглашения', '/account/invites');
        } elseif (! $user->isStaff()) {
            $links[] = self::link('Интерес', '/account/interests');
        }
        if ($user->role->canChat()) {
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
        if ($user->role->canChat()) {
            $badges['/account/chats'] = (int) Chat::where('user_id', $user->id)->sum('unread_for_user');
        }
        $badges['/account/favorites'] = Favorite::where('user_id', $user->id)->count();

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
                    '/requests/from-mail' => Candidate::where('scope', Scope::Park)->where('state', CandidateState::New)->count(),
                    '/mail' => Thread::where('unread_count', '>', 0)->whereHas('account', fn ($a) => $a->where('scope', Scope::Park))->count(),
                ];
            }
            $badges = [
                '/' => Bid::where('state', BidState::Active)->count(),
                '/offers/from-mail' => Candidate::where('scope', Scope::Offers)->where('state', CandidateState::New)->count(),
                '/work/mail' => Thread::where('unread_count', '>', 0)->whereHas('account', fn ($a) => $a->where('scope', Scope::Offers))->count(),
                '/work/chats' => Chat::where('unread_for_staff', '>', 0)->count(),
                '/work/deals' => Position::where('track', 'sale')
                    ->whereHas('offer.deal', fn ($d) => $d->where('state', DealState::Active))
                    ->where(fn ($w) => $w->where('deadline_at', '<', now())->orWhereHas('stage', fn ($s) => $s->where('waits_for', WaitsFor::Us)))
                    ->count(),
            ];
            $badges['/work'] = $badges['/work/deals'] + $badges['/work/mail'] + $badges['/work/chats'];

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
        $paths[] = '/account/notifications';
        if ($surface === Surface::Site) {
            $paths[] = '/account/favorites';
            $paths[] = '/account/chats';
            $paths[] = '/account/deals';
        }
        if ($surface === Surface::Crm) {
            $paths[] = '/work/deals';
            $paths[] = '/work/mail';
            $paths[] = '/work/chats';
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
            array_push($items, self::link('Сделки', '/work/deals'), self::link('Почта', '/work/mail'), self::link('Чаты', '/work/chats'));
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
