<?php

namespace App\Mail;

use App\Cars\HasLabels;

enum CandidateState: string
{
    use HasLabels;

    case New = 'new';
    case Rejected = 'rejected';
    case Promoted = 'promoted';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Ждёт',
            self::Rejected => 'В архиве',
            self::Promoted => 'Заведён',
        };
    }
}
