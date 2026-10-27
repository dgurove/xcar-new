<?php

namespace App\Workflow;

use App\Cars\HasLabels;

/**
 * Откуда этап берёт срок: свой лимит, окно приёма подтверждений, дата от страховой или «до 17:00 следующего дня» (27.10.2026,
 * владелец: менеджеру на согласие — сегодня и завтра до 17:00).
 */
enum DeadlineSource: string
{
    use HasLabels;

    case Own = 'own';
    case BidsClose = 'bids_close';
    case InsurerDeadline = 'insurer_deadline';
    case NextDay17 = 'next_day_17';

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Свой срок',
            self::BidsClose => 'Срок приёма подтверждений',
            self::InsurerDeadline => 'Срок от страховой',
            self::NextDay17 => 'До 17:00 следующего дня',
        };
    }
}
