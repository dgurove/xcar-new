<?php

namespace App\Telegram\Messages;

use App\Mail\Candidate;
use App\Mail\CandidateStage;
use App\Park\Request;
use App\Support\Money;
use App\Support\Surface;

/**
 * Письмо на стоянку: кто, что за ТС, откуда забирать, телефон страхователя. Решать нечего: заявку заводит почта
 * (`AutoRequest`) — тогда ссылка на дело, — а что не завелось само, заводит сотрудник из «Из писем».
 */
final class ParkLetter extends Message
{
    public function __construct(private Candidate $candidate, private ?Request $request = null) {}

    protected function title(): string
    {
        $v = fn (string $f) => $this->candidate->extracted[$f]['value'] ?? null;

        if ($this->request) {
            return 'Заявка заведена: '.mb_strtolower($this->request->type->label());
        }

        return match ($this->candidate->stage) {
            CandidateStage::Sold => 'Данное ТС реализовано',
            CandidateStage::Released => 'ТС выдана',
            CandidateStage::Stored => 'Уже на парковке',
            default => $v('request') === 'tow' ? 'На вывоз' : 'На приём',
        };
    }

    protected function lines(): array
    {
        $v = fn (string $f) => $this->candidate->extracted[$f]['value'] ?? null;

        return [
            $v('vendor') ?? $v('sender'),
            trim($this->candidate->title().($this->candidate->hasCar() && $v('year') ? ', '.$v('year') : '')),
            $this->candidate->code,
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
        return $this->request
            ? ['text' => 'Дело на парковке', 'url' => Surface::Park->url('/cars/'.$this->request->vehicle_id)]
            : ['text' => 'Из писем на парковке', 'url' => Surface::Park->url('/requests/from-mail')];
    }
}
