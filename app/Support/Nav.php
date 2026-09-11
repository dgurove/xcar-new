<?php

namespace App\Support;

use App\Users\Role;
use App\Users\Section;
use App\Users\User;

/** Пункты таб-бара и меню — одним списком на роль. */
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
                self::tab('Разговоры', 'mail', '/admin/razgovory', '/admin/razgovory'),
                self::tab('Сделки', 'deal', '/admin/sdelki'),
                self::tab('Закупки', 'cart', '/admin/zakupki'),
                self::tab('Ещё', 'more', '/admin/eshchyo'),
            ];
        }

        if ($user?->role === Role::Manager) {
            return [
                self::tab('Закупки', 'cart', '/zakupki'),
                self::tab('Сделки', 'deal', '/lk/sdelki'),
                self::tab('Избранное', 'heart', '/lk/izbrannoe'),
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

    private static function tab(string $label, string $icon, string $href, ?string $match = null): array
    {
        return ['label' => $label, 'icon' => $icon, 'href' => $href, 'match' => $match ?? $href];
    }

    public static function isCurrent(array $tab, string $path): bool
    {
        return $tab['match'] === '/' ? $path === '/' : str_starts_with($path, $tab['match']);
    }
}
