<?php

namespace App\Notifications;

use App\Users\Role;
use App\Users\User;

/**
 * О чём человек может не получать уведомления — по роли. Единственное место
 * с подписями. Ключ категории отдаёт Notice::category(); критичное
 * (Notice::critical()) не отключается и показано с пометкой «всегда».
 *
 * @return array{on: array<string, string>, always: list<string>}
 */
final class Categories
{
    public static function for(User $user): array
    {
        $role = $user->role;

        return match (true) {
            $role === Role::Manager => [
                'on' => ['offers' => 'Новые предложения', 'purchases' => 'Новые закупки', 'interest' => 'Интерес покупателей', 'people' => 'Новые покупатели', 'chats' => 'Чаты'],
                'always' => ['Ответ на подтверждение', 'Сделки: ваш ход и сроки', 'Счета и вознаграждение', 'Выбор вашей цены в закупке'],
            ],
            $role === Role::Buyer => [
                'on' => ['offers' => 'Новые предложения для вас', 'chats' => 'Чаты'],
                'always' => ['Сделки'],
            ],
            // Модератору — только новые цепочки «Из писем», и то с галкой почты: денег, сделок и чатов у него нет.
            $role === Role::Moderator => ['on' => $user->canCrmMail() ? ['letters' => 'Новые из писем'] : [], 'always' => []],
            $role->isStaff() => [
                'on' => ['bids' => 'Подтверждения', 'interest' => 'Интерес', 'deals' => 'Сроки этапов', 'money' => 'Деньги', 'chats' => 'Чаты', 'park' => 'Парковка'],
                'always' => [],
            ],
            default => ['on' => ['offers' => 'Новые предложения', 'chats' => 'Чаты'], 'always' => []],
        };
    }

    /**
     * Что из выключаемого вообще приходит в Telegram — эти строки и выбираются «в Telegram» (`telegram_off`). Остальное
     * туда не шлётся (у уведомления нет `toTelegram`) или критичное и идёт всегда.
     *
     * @return array<string, string>
     */
    public static function telegram(User $user): array
    {
        $keys = match ($user->role) {
            Role::Manager, Role::Admin => ['chats'],
            Role::Moderator => ['letters'],
            default => [],
        };

        return array_intersect_key(self::for($user)['on'], array_flip($keys));
    }
}
