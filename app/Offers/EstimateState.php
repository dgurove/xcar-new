<?php

namespace App\Offers;

/**
 * Просьба оценить цену продажи (11.10.2026): `asked` — ждём менеджера (и после «Отклонить» — снова), `given` — оценил,
 * решает админ, `taken` — оценку взяли ценой продажи, `closed` — просьбу сняли или цену поставили иначе.
 */
enum EstimateState: string
{
    case Asked = 'asked';
    case Given = 'given';
    case Taken = 'taken';
    case Closed = 'closed';
    /** Менеджер отказался оценивать (желаемая стоимость и причина — в просьбе). */
    case Declined = 'declined';

    public const OPEN = [self::Asked, self::Given];

    public function isOpen(): bool
    {
        return in_array($this, self::OPEN, true);
    }
}
