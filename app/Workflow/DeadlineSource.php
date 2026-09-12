<?php

namespace App\Workflow;

use App\Cars\HasLabels;

/** Откуда этап берёт срок: свой лимит, окно приёма подтверждений или дата от страховой. */
enum DeadlineSource: string
{
    use HasLabels;

    case Own = 'own';
    case BidsClose = 'bids_close';
    case InsurerDeadline = 'insurer_deadline';

    public function label(): string
    {
        return match ($this) {
            self::Own => 'Свой срок',
            self::BidsClose => 'Срок приёма подтверждений',
            self::InsurerDeadline => 'Срок от страховой',
        };
    }
}
