<?php

namespace App\Notifications;

use App\Users\Role;

/**
 * О чём человек может не получать уведомления — по роли. Единственное место
 * с подписями. Ключ категории отдаёт Notice::category(); критичное
 * (Notice::critical()) не отключается и показано с пометкой «всегда».
 *
 * @return array{on: array<string, string>, always: list<string>}
 */
final class Categories
{
    public static function for(Role $role): array
    {
        return match (true) {
            $role === Role::Manager => [
                'on' => ['offers' => 'Новые предложения', 'purchases' => 'Новые закупки', 'interest' => 'Интерес покупателей', 'people' => 'Новые покупатели', 'chats' => 'Чаты'],
                'always' => ['Ответ на подтверждение', 'Сделки: ваш ход и сроки', 'Выбор вашей цены в закупке'],
            ],
            $role === Role::Buyer => [
                'on' => ['offers' => 'Новые предложения для вас', 'chats' => 'Чаты'],
                'always' => ['Сделки'],
            ],
            $role->isStaff() => [
                'on' => ['bids' => 'Подтверждения', 'interest' => 'Интерес', 'deals' => 'Сроки этапов', 'chats' => 'Чаты', 'park' => 'Парковка'],
                'always' => [],
            ],
            default => ['on' => ['offers' => 'Новые предложения', 'chats' => 'Чаты'], 'always' => []],
        };
    }
}
