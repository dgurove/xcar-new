<?php

namespace App\Cars;

enum Papers: string
{
    use HasLabels;

    case Both = 'both';
    case Pts = 'pts';
    case Sts = 'sts';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Both => 'ПТС и СТС',
            self::Pts => 'Только ПТС',
            self::Sts => 'Только СТС',
            self::None => 'Без документов',
        };
    }
}
