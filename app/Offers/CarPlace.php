<?php

namespace App\Offers;

use App\Cars\HasLabels;

enum CarPlace: string
{
    use HasLabels;

    case Owner = 'owner';
    case Moving = 'moving';
    case Ours = 'ours';
    case Keeper = 'keeper';     // стоит у менеджера, который вывез (вне парковки)
    case WithUs = 'with_us';    // стоит у нас, но не на парковке: хранение не считаем

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'У владельца',
            self::Moving => 'В пути к нам',
            self::Ours => 'На нашей парковке',
            self::Keeper => 'У менеджера',
            self::WithUs => 'У нас',
        };
    }

    /** Подпись на витрине: что ТС стоит у другого менеджера, наружу не выходит — «У нас». */
    public function publicLabel(): string
    {
        return $this === self::Keeper ? self::WithUs->label() : $this->label();
    }
}
