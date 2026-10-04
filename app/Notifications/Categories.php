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
        // Ролей может быть несколько — наборы складываются (менеджер и модератор получают и то, и другое).
        $on = [];
        $always = [];
        $add = function (array $set) use (&$on, &$always) {
            $on += $set['on'];
            $always = array_values(array_unique([...$always, ...$set['always']]));
        };
        if ($user->hasRole(Role::Manager)) {
            $add([
                'on' => ['offers' => 'Новые предложения', 'purchases' => 'Новые закупки', 'interest' => 'Интерес покупателей', 'people' => 'Новые покупатели', 'chats' => 'Чаты'],
                'always' => ['Ответ на подтверждение', 'Сделки: ваш ход и сроки', 'Счета и вознаграждение', 'Выбор вашей цены в закупке'],
            ]);
        }
        if ($user->hasRole(Role::Buyer)) {
            $add(['on' => ['offers' => 'Новые предложения для вас', 'chats' => 'Чаты'], 'always' => ['Сделки']]);
        }
        // Модератору — только новые цепочки «Из писем», и то с галкой почты: денег, сделок и чатов у него нет.
        if ($user->hasRole(Role::Moderator) && $user->canCrmMail()) {
            $add(['on' => ['letters' => 'Новые из писем'], 'always' => []]);
        }
        if ($user->hasRole(Role::Admin)) {
            $add(['on' => ['bids' => 'Подтверждения', 'interest' => 'Интерес', 'deals' => 'Сроки этапов', 'money' => 'Деньги', 'chats' => 'Чаты', 'park' => 'Парковка'], 'always' => []]);
        }
        if (! $on && ! $always && ! $user->hasRole(Role::Moderator)) {
            $add(['on' => ['offers' => 'Новые предложения', 'chats' => 'Чаты'], 'always' => []]);
        }

        return ['on' => $on, 'always' => $always];
    }

    /**
     * Что из выключаемого вообще приходит в Telegram — эти строки и выбираются «в Telegram» (`telegram_off`). Остальное
     * туда не шлётся (у уведомления нет `toTelegram`) или критичное и идёт всегда.
     *
     * @return array<string, string>
     */
    public static function telegram(User $user): array
    {
        $keys = [
            ...($user->hasRole(Role::Manager, Role::Admin) ? ['chats'] : []),
            ...($user->hasRole(Role::Moderator) ? ['letters'] : []),
        ];

        return array_intersect_key(self::for($user)['on'], array_flip($keys));
    }
}
