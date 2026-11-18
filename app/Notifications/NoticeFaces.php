<?php

namespace App\Notifications;

use App\Users\User;
use App\Vendors\Vendor;

/**
 * Лица строк ленты (18.11.2026, строка как капсула: аватар того, кто действовал, или логотип вендора): `actor` и `vendor` из
 * `data` уведомления — двумя запросами на страницу ленты (`prime`), а не на строку. Память — на запрос (`scoped`: воркер Octane
 * живёт дольше запроса, аватар мог смениться).
 */
final class NoticeFaces
{
    /** @var array<int, ?User> */
    private array $users = [];

    /** @var array<int, ?Vendor> */
    private array $vendors = [];

    private static function bag(): self
    {
        app()->scopedIf(self::class);

        return app(self::class);
    }

    /** @param iterable<\Illuminate\Notifications\DatabaseNotification> $items */
    public static function prime(iterable $items): void
    {
        $users = $vendors = [];
        foreach ($items as $item) {
            ($id = $item->data['actor'] ?? null) && $users[] = (int) $id;
            ($id = $item->data['vendor'] ?? null) && $vendors[] = (int) $id;
        }
        $bag = self::bag();
        $users = array_diff(array_unique($users), array_keys($bag->users));
        $vendors = array_diff(array_unique($vendors), array_keys($bag->vendors));
        if ($users) {
            $bag->users += User::whereIn('id', $users)->get()->keyBy('id')->all() + array_fill_keys($users, null);
        }
        if ($vendors) {
            $bag->vendors += Vendor::whereIn('id', $vendors)->get()->keyBy('id')->all() + array_fill_keys($vendors, null);
        }
    }

    public static function user(array $data): ?User
    {
        $id = (int) ($data['actor'] ?? 0);

        $bag = self::bag();

        return $id ? (array_key_exists($id, $bag->users) ? $bag->users[$id] : ($bag->users[$id] = User::find($id))) : null;
    }

    public static function vendor(array $data): ?Vendor
    {
        $id = (int) ($data['vendor'] ?? 0);

        $bag = self::bag();

        return $id ? (array_key_exists($id, $bag->vendors) ? $bag->vendors[$id] : ($bag->vendors[$id] = Vendor::find($id))) : null;
    }
}
