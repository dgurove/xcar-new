<?php

namespace App\Park;

/**
 * Что открыто управляющему парковкой (роль «Парковка») сверх основы — заявок, наличия и дела ТС: админ отмечает
 * при приглашении и в CRM. Хранится в `users.access`. Настройки (вендоры, прайс, парковки, реквизиты, шаблоны) —
 * только админам, отдельной галки для них нет.
 */
enum Area: string
{
    case Money = 'money';
    case Mail = 'mail';

    public function label(): string
    {
        return match ($this) {
            self::Money => 'Оплаты: счета, начисления',
            self::Mail => 'Почта парковки',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $a) => $a->value, self::cases());
    }
}
