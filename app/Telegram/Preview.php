<?php

namespace App\Telegram;

use App\Support\Money;
use App\Users\User;
use Illuminate\Support\HtmlString;

/**
 * Пример сообщения для шторки подключения — в том же виде, в каком его пришлёт бот (Notice::toTelegram):
 * менеджеру — принятое подтверждение, админу — заявленная оплата с кнопкой решения. Решение владельца
 * 30.09.2026: всегда один пример, не данные человека.
 *
 * @return array{title: string, lines: list<string|HtmlString>, button: string}
 */
final class Preview
{
    public static function for(User $user): array
    {
        if ($user->isModerator()) {
            return ['title' => 'Новое из писем: Kia Rio, 2021', 'lines' => [new HtmlString('VIN <code>XW8ZZZ61ZNG012345</code>'), 'АльфаСтрахование, 0291/26/0123456'], 'button' => 'Открыть в CRM'];
        }

        return $user->isManager()
            ? ['title' => 'Подтверждение Kia Rio, 2021 принято', 'lines' => [new HtmlString('VIN <code>XW8ZZZ61ZNG012345</code>'), Money::rub(1250000), new HtmlString('<span class="tg-tag">#2609291066</span>')], 'button' => 'Открыть сделку']
            : ['title' => 'Андрей С. сообщил об оплате: Kia Rio, 2021', 'lines' => [new HtmlString('VIN <code>XW8ZZZ61ZNG012345</code>'), Money::rub(1180000).' от 30 сен, платёжка приложена', new HtmlString('<span class="tg-tag">#2609291066</span>')], 'button' => 'Поступило'];
    }
}
