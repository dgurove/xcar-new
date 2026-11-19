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
    /** Категории админа — порядок строк в настройках. */
    public const ADMIN = [
        'supplier_mail' => 'Письма поставщиков',
        'managers' => 'Менеджеры: ответы и кнопки',
        'our_mail' => 'Наши письма поставщикам',
        'deals' => 'Ход сделок',
        'money' => 'Деньги',
        'bids' => 'Подтверждения и приём',
        'estimates' => 'Оценки менеджеров',
        'chats' => 'Чаты',
        'logistics' => 'Вывоз',
        'people' => 'Новые люди',
    ];

    /**
     * Темы менеджера, супер-покупателя и покупателя — строки «тема × канал» (19.11.2026, владелец выбрал: в каждой строке
     * «Пуш» и «Telegram», лента получает всё всегда; критичное выключить нельзя — можно выбрать канал). Ключ — `Notice::topic()`.
     * Только у кого нет ролей CRM, парковки и логистики (`siteOnly`): у них свои категории (`ADMIN`, правка 18.11.2026).
     *
     * @return array<string, array{label: string, critical: bool}>|null null — экран прежний
     */
    public static function topics(User $user): ?array
    {
        if (! self::siteOnly($user)) {
            return null;
        }
        $t = fn (string $label, bool $critical = false) => ['label' => $label, 'critical' => $critical];
        $deals = $user->hasRole(Role::Manager, Role::SuperBuyer) || ($user->holdsAsBuyer() && $user->canGarage());

        return [
            ...($deals ? ['deals' => $t('Сделки: ход и сроки', true), 'money' => $t('Счета и оплаты', true)] : []),
            ...($user->hasRole(Role::Manager, Role::SuperBuyer) ? [
                'bids' => $t('Подтверждения и цена', true),
                'handover' => $t('Выдача и вывоз', true),
            ] : []),
            ...($user->hasRole(Role::Manager) ? ['estimates' => $t('Оценки')] : []),
            'offers' => $t($user->hasRole(Role::Manager, Role::SuperBuyer) ? 'Новые предложения' : 'Новые предложения для вас'),
            ...($user->hasRole(Role::Manager, Role::SuperBuyer) ? ['closing' => $t('Конец приёма')] : []),
            'chats' => $t('Чаты'),
            ...($user->hasRole(Role::Manager) ? ['people' => $t('Покупатели')] : []),
        ];
    }

    /** Только роли сайта: менеджер, супер-покупатель, покупатель — без CRM, парковки, логистики и поставщика. */
    public static function siteOnly(User $user): bool
    {
        return $user->hasRole(Role::Manager, Role::SuperBuyer, Role::Buyer)
            && ! $user->hasRole(Role::Admin, Role::Moderator, Role::Owner, Role::Parking, Role::ParkAdmin, Role::Logist, Role::Driver, Role::Contractor, Role::Supplier);
    }

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
                'always' => ['Ответ на подтверждение', 'Сделки: дела и сроки', 'Счета', 'Выбор вашей цены в закупке'],
            ]);
        }
        if ($user->hasRole(Role::SuperBuyer)) {
            $add(['on' => ['offers' => 'Новые предложения', 'chats' => 'Чаты'], 'always' => ['Ответ на подтверждение', 'Сделки: дела и сроки', 'Счета']]);
        }
        if ($user->hasRole(Role::Buyer)) {
            $add(['on' => ['offers' => 'Новые предложения для вас', 'chats' => 'Чаты'], 'always' => []]);
        }
        // Покупатель с гаражом (20.10.2026): по машинам гаража — сделка и его счёт, как у супер-покупателя.
        if ($user->holdsAsBuyer() && $user->canGarage()) {
            $add(['on' => [], 'always' => ['Сделки: дела и сроки', 'Счета']]);
        }
        // Модератору — новые цепочки «Из писем» (с галкой почты) и чаты площадки: с 17.11.2026 он ведёт сделки и пишет менеджерам.
        if ($user->hasRole(Role::Moderator) && $user->canCrmMail()) {
            $add(['on' => ['letters' => 'Новые из писем'], 'always' => []]);
        }
        if ($user->hasRole(Role::Moderator)) {
            $add(['on' => ['chats' => 'Чаты'], 'always' => []]);
        }
        // Админу — всё, что происходит по сделкам, тонкими категориями, по умолчанию всё включено (18.11.2026, начальник: «все
        // уведомления по всем событиям по сделкам — в центр уведомлений и в Telegram, и когда мы действие сделали, и когда другие»;
        // владелец: «разбей на категории, по умолчанию всё включи, в настройках можно выключать»). Критичное — «всегда».
        if ($user->hasRole(Role::Admin)) {
            $add(['on' => self::ADMIN, 'always' => ['Менеджер берёт в работу', 'Ход за нами после менеджера', 'Счёт от поставщика', 'Горит срок обязывающего']]);
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
        if (! $on && ! $always && ! $user->hasRole(Role::Moderator, Role::Supplier)) {
            $add(['on' => ['offers' => 'Новые предложения', 'chats' => 'Чаты'], 'always' => []]);
        }

        return ['on' => $on, 'always' => $always];
    }

    /**
     * Что можно получать на почту (06.11.2026, владелец: «только по очень важным вещам, все ненавидят спам; даже если
     * обязывающее сгорает — это не письмо, а пуш»): короткий список по ролям, по умолчанию всё выключено
     * (`notification_settings.mail_on`). И из включённого письмом уходит только то, где нужен человек (`Notice::important`,
     * `critical`). Чатов, новых предложений, подтверждений, оценок и сроков на почте нет никогда.
     *
     * @return array<string, string>
     */
    public static function mail(User $user): array
    {
        return [
            ...($user->hasRole(Role::Manager, Role::SuperBuyer) || ($user->holdsAsBuyer() && $user->canGarage()) ? ['deals' => 'Сделки: дела, счета', 'bids' => 'Выбрали ваше подтверждение'] : []),
            ...($user->hasRole(Role::Admin) ? ['deals' => 'Сделки: ход за нами', 'money' => 'Деньги'] : []),
            ...($user->hasRole(Role::Parking, Role::ParkAdmin) ? ['park' => 'Парковка: заявки и письма', ...($user->hasRole(Role::ParkAdmin) || $user->parkMoney() ? ['park_money' => 'Парковка: оплаты'] : [])] : []),
            ...($user->hasRole(Role::Logist, Role::Driver, Role::Contractor) || $user->carriesForLogistics() ? ['logistics' => 'Вывоз поручен вам'] : []),
        ];
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
            // Админу — каждая категория и в Telegram (18.11.2026): туда идёт всё, что в ленту, тумблером по категории.
            ...($user->hasRole(Role::Admin) ? array_keys(self::ADMIN) : []),
            // Чаты в Telegram выключает только сотрудник: менеджеру и покупателю приходит каждое сообщение (`ChatNotice::via`,
            // 16.11.2026, владелец: «всё, что в чат приходит, — в телегу»), переключатель им ничего бы не выключал.
            ...($user->hasRole(Role::Admin, Role::Moderator) ? ['chats'] : []),
            // Новые предложения пачкой (21.10.2026, владелец: «менеджеры не видят, когда приходят новые машины»).
            ...($user->hasRole(Role::Manager, Role::SuperBuyer) ? ['offers'] : []),
            // Покупателю — машины, что открыл менеджер, и его сообщения (12.10.2026).
            ...($user->hasRole(Role::Buyer) ? ['offers'] : []),
            ...($user->hasRole(Role::Parking) ? ['park', 'park_money'] : []),
            ...($user->hasRole(Role::Admin, Role::Moderator, Role::Logist) ? ['logistics'] : []),
        ];

        return array_intersect_key(self::for($user)['on'], array_flip($keys));
    }
}
