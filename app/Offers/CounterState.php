<?php

namespace App\Offers;

/** Контрпредложение админа к подтверждению (26.10.2026). Живое одно на предложение. */
enum CounterState: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
}
