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

/** Пункты таб-бара и меню — одним списком на роль. Счётчики — отдельно, их перечитывает live. */
final class Nav
{
    /** @return list<array{label: string, icon: string, href: string, match: string}> */
    public static function tabs(?User $user, string $surface = 'site'): array
    {
        if ($surface === 'park') {
            return [
                self::tab('Заявки', 'mail', '/zayavki'),
                self::tab('Машины', 'car', '/mashiny'),
                self::tab('Стоянки', 'park', '/stoyanki'),
                self::tab('Почта', 'mail', '/pochta'),
                self::tab('Ещё', 'more', '/eshchyo'),
            ];
        }

        if ($user?->isStaff()) {
            return [
                self::tab('Офферы', 'car', '/admin/offers', '/admin/offers'),
                self::tab('Почта', 'mail', '/admin/pochta', '/admin/pochta'),
                self::tab('Сделки', 'deal', '/admin/sdelki'),
                self::tab('Закупки', 'cart', '/admin/zakupki'),
                self::tab('Ещё', 'more', '/admin/eshchyo'),
            ];
        }

        if ($user?->role === Role::Manager) {
            return [
                self::tab('Предложения', 'car', '/', '/'),
                self::tab('Сделки', 'deal', '/lk/sdelki'),
                self::tab('Закупки', 'cart', '/zakupki'),
                self::tab('Уведомления', 'bell', '/lk/uvedomleniya'),
                self::tab('Кабинет', 'user', '/lk'),
            ];
        }

        return [
            self::tab('Предложения', 'car', '/', '/'),
            self::tab('Галерея', 'photo', '/galereya'),
            self::tab('Избранное', 'heart', '/lk/izbrannoe'),
            self::tab('Уведомления', 'bell', '/lk/uvedomleniya'),
            self::tab($user ? 'Кабинет' : 'Войти', 'user', $user ? '/lk' : '/vhod'),
        ];
    }

    /** Счётчики на пунктах: путь → число. Ноль не отдаётся. */
    public static function badges(?User $user): array
    {
        if (! $user) {
            return [];
        }
        $badges = [];
        if ($user->isStaff()) {
            $badges['/admin/offers'] = Bid::where('state', BidState::Active)->count();
            $badges['/admin/pochta'] = \App\Mail\Thread::where('unread_count', '>', 0)->whereHas('account', fn ($a) => $a->where('scope', \App\Mail\Scope::Offers))->count()
                + \App\Chats\Chat::where('unread_for_staff', '>', 0)->count();
            $badges['/admin/sdelki'] = Position::where('track', 'sale')
                ->whereHas('offer.deal', fn ($d) => $d->where('state', DealState::Active))
                ->where(fn ($w) => $w->where('deadline_at', '<', now())->orWhereHas('stage', fn ($s) => $s->where('waits_for', WaitsFor::Us)))
                ->count();
        } else {
            $badges['/lk/sdelki'] = Requirement::where('user_id', $user->id)->whereNull('done_at')->count();
        }
        $badges['/lk/uvedomleniya'] = $user->unreadCount();

        return array_filter($badges);
    }

    public static function badgeId(string $href): string
    {
        return 'badge-'.trim(str_replace('/', '-', $href), '-');
    }

    private static function tab(string $label, string $icon, string $href, ?string $match = null): array
    {
        return ['label' => $label, 'icon' => $icon, 'href' => $href, 'match' => $match ?? $href];
    }

    public static function isCurrent(array $tab, string $path): bool
    {
        return $tab['match'] === '/' ? $path === '/' : str_starts_with($path, $tab['match']);
    }
}
