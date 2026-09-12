<?php

namespace App\Users;

/** Куда пускают, независимо от роли. Лежит в users.access списком; CRM открыта по роли, без раздела. */
enum Section: string
{
    case Park = 'park';

    public function label(): string
    {
        return match ($this) {
            self::Park => 'Стоянка',
        };
    }
}
