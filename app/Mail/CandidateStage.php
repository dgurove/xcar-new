<?php

namespace App\Mail;

/** Последний этап цепочки писем кандидата: ждёт приёма, по письмам уже на парковке, продана. */
enum CandidateStage: string
{
    case Intake = 'intake';
    case Stored = 'stored';
    case Sold = 'sold';

    public function label(): string
    {
        return match ($this) {
            self::Intake => 'ждёт приёма',
            self::Stored => 'на парковке',
            self::Sold => 'продана, заберёт покупатель',
        };
    }
}
