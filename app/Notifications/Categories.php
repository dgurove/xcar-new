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
        if ($user->hasRole(Role::SuperBuyer)) {
            $add(['on' => ['offers' => 'Новые предложения', 'chats' => 'Чаты'], 'always' => ['Ответ на подтверждение', 'Сделки: ваш ход и сроки', 'Счета']]);
        }
        if ($user->hasRole(Role::Buyer)) {
            $add(['on' => ['offers' => 'Новые предложения для вас', 'chats' => 'Чаты'], 'always' => []]);
        }
        // Покупатель с гаражом (20.10.2026): по машинам гаража — сделка и его счёт, как у супер-покупателя.
        if ($user->holdsAsBuyer() && $user->canGarage()) {
            $add(['on' => [], 'always' => ['Сделки: ваш ход и сроки', 'Счета']]);
        }
        // Модератору — только новые цепочки «Из писем», и то с галкой почты: денег, сделок и чатов у него нет.
        if ($user->hasRole(Role::Moderator) && $user->canCrmMail()) {
            $add(['on' => ['letters' => 'Новые из писем'], 'always' => []]);
        }
        if ($user->hasRole(Role::Admin)) {
            $add(['on' => ['bids' => 'Подтверждения', 'deals' => 'Сроки этапов', 'money' => 'Деньги', 'chats' => 'Чаты'], 'always' => []]);
        }
        // Администратор парковки — своя роль, не у каждого админа CRM.
        if ($user->hasRole(Role::ParkAdmin)) {
            $add(['on' => ['park' => 'Парковка', 'park_money' => 'Оплаты парковки'], 'always' => []]);
        }
        // Управляющий парковкой: заявки и письма, оплаты; назначенное ему и вышедший срок — всегда. Помощнику оплат нет.
        if ($user->hasRole(Role::Parking)) {
            $add([
                'on' => ['park' => 'Заявки и письма', ...($user->parkMoney() ? ['park_money' => 'Оплаты'] : [])],
                'always' => ['Вам назначено', 'Срок вышел', ...($user->parkMoney() ? ['Просроченные счета'] : [])],
            ]);
        }
        // Логистика (17.10.2026): ведущим (админ, модераторы — 18.10.2026) и логисту — новый вывоз и ход (выключаемо);
        // логисту и исполнителям — поручение всегда, модератору — документы СДЭКом всегда.
        if ($user->hasRole(Role::Admin, Role::Moderator, Role::Logist)) {
            $add(['on' => ['logistics' => 'Вывоз'], 'always' => []]);
        }
        if ($user->hasRole(Role::Logist, Role::Driver, Role::Contractor) || $user->carriesForLogistics()) {
            $add(['on' => [], 'always' => ['Вывоз поручен вам']]);
        }
        if ($user->hasRole(Role::Moderator)) {
            $add(['on' => [], 'always' => ['Документы на вывоз']]);
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
            ...($user->hasRole(Role::Manager, Role::Admin, Role::SuperBuyer) ? ['chats'] : []),
            // Новые предложения пачкой (21.10.2026, владелец: «менеджеры не видят, когда приходят новые машины»).
            ...($user->hasRole(Role::Manager, Role::SuperBuyer) ? ['offers'] : []),
            // Покупателю — машины, что открыл менеджер, и его сообщения (12.10.2026).
            ...($user->hasRole(Role::Buyer) ? ['offers', 'chats'] : []),
            ...($user->hasRole(Role::Moderator) ? ['letters'] : []),
            ...($user->hasRole(Role::Parking) ? ['park', 'park_money'] : []),
            ...($user->hasRole(Role::Admin, Role::Moderator, Role::Logist) ? ['logistics'] : []),
        ];

        return array_intersect_key(self::for($user)['on'], array_flip($keys));
    }
}
