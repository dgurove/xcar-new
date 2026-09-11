<?php

namespace App\Users;

/** Куда пускают, независимо от роли. Лежит в users.access списком. */
enum Section: string
{
    case Admin = 'admin';
    case Park = 'park';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Админ',
            self::Park => 'Стоянка',
        };
    }
}
