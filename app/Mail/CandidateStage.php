<?php

namespace App\Mail;

/** Последний этап цепочки писем кандидата: ждёт приёма, по письмам уже на парковке, продана, выдана (цепочка закрыта). */
enum CandidateStage: string
{
    case Intake = 'intake';
    case Stored = 'stored';
    case Sold = 'sold';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Intake => 'ждёт приёма',
            self::Stored => 'на парковке',
            self::Sold => 'продана, заберёт покупатель',
            self::Released => 'выдана',
        };
    }
}
