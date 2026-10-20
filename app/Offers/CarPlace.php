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
    case Elsewhere = 'elsewhere';
    case Buyer = 'buyer';       // забрал покупатель (аукцион Совкомбанка, 20.10.2026): у нас её больше нет
    case Depot = 'depot';       // стоит на стоянке логиста между отрезками вывоза (18.10.2026) // привезли по адресу, что вписал исполнитель вывоза (17.10.2026): не у нас и не у менеджера

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'У владельца',
            self::Moving => 'В пути к нам',
            self::Ours => 'На нашей парковке',
            self::Keeper => 'У менеджера',
            self::WithUs => 'У нас',
            self::Elsewhere => 'По адресу',
            self::Depot => 'На стоянке логиста',
            self::Buyer => 'У покупателя',
        };
    }

    /** Подпись на витрине: что ТС стоит у другого менеджера, наружу не выходит — «У нас». */
    public function publicLabel(): ?string
    {
        // Привезли по чужому адресу — на витрине места не пишем: «У нас» было бы неправдой.
        return match ($this) {
            self::Keeper => self::WithUs->label(),
            self::Elsewhere, self::Depot, self::Buyer => null,
            default => $this->label(),
        };
    }
}
