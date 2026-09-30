<?php

namespace App\Telegram;

use App\Support\Money;
use App\Users\User;

/**
 * Пример сообщения для шторки подключения — в том же виде, в каком его пришлёт бот (Notice::toTelegram):
 * менеджеру — принятое подтверждение, админу — заявленная оплата с кнопкой решения. Решение владельца
 * 30.09.2026: всегда один пример, не данные человека.
 *
 * @return array{title: string, lines: list<string>, button: string}
 */
final class Preview
{
    public static function for(User $user): array
    {
        return $user->isManager()
            ? ['title' => 'Подтверждение '.Money::rub(1250000).' принято', 'lines' => ['Kia Rio, 2021', '№ 2609291066'], 'button' => 'Открыть сделку']
            : ['title' => 'Сообщил об оплате: Андрей Смирнов', 'lines' => ['Счёт № 114 на '.Money::rub(1180000), 'Платёжка приложена'], 'button' => 'Поступило'];
    }
}
