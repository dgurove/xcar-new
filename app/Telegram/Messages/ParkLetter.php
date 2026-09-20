<?php

namespace App\Telegram\Messages;

use App\Mail\Candidate;
use App\Support\Money;
use App\Support\Surface;

/** Письмо на стоянку: кто, что за ТС, откуда забирать, телефон страхователя. Решать нечего — заводит сотрудник. */
final class ParkLetter extends Message
{
    public function __construct(private Candidate $candidate) {}

    protected function title(): string
    {
        $v = fn (string $f) => $this->candidate->extracted[$f]['value'] ?? null;

        return $v('request') === 'tow' ? 'На вывоз' : 'На приём';
    }

    protected function lines(): array
    {
        $v = fn (string $f) => $this->candidate->extracted[$f]['value'] ?? null;

        return [
            $v('vendor') ?? $v('sender'),
            trim($this->candidate->title().($this->candidate->hasCar() && $v('year') ? ', '.$v('year') : '')),
            $this->candidate->hasCar() ? $this->candidate->code : null,
            $v('location'),
            $v('insured_name') || $v('insured_phone') ? trim(($v('insured_name') ?? '').' '.($v('insured_phone') ?? '')) : null,
            $v('value') ? 'Оценка '.Money::rub($v('value')) : null,
            $v('answer_by') ? 'Срок '.self::moment(new \DateTime($v('answer_by'))) : null,
        ];
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'Из писем на парковке', 'url' => Surface::Park->url('/requests/from-mail')];
    }
}
