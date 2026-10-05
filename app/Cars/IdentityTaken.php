<?php

namespace App\Cars;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Номер убытка или VIN уже у другой записи (`Identity`). Это ошибка поля формы — человек видит, у кого номер, а
 * фоновые пути (письма, Мигторг, закупка) ловят её и встают на найденную запись вместо второй.
 */
final class IdentityTaken extends ValidationException
{
    public Model $holder;

    public string $field;

    public static function of(Model $holder, string $field, string $message): self
    {
        $e = self::withMessages([$field => $message]);
        $e->holder = $holder;
        $e->field = $field;

        return $e;
    }
}
