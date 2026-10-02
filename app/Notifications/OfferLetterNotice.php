<?php

namespace App\Notifications;

use App\Mail\Candidate;
use App\Support\Surface;
use Illuminate\Support\HtmlString;

/**
 * Новая цепочка «Из писем» CRM, которую надо завести, — модераторам с галкой почты. Кто-то завёл её или убрал в архив —
 * сообщение в Telegram и строка ленты у всех удаляются (`RetractCandidateNotices`), чтобы второй не шёл заводить
 * заведённое. Строка ленты своя на каждую цепочку (`?candidate=`), а не одна на всё «Из писем».
 */
final class OfferLetterNotice extends Notice
{
    public function __construct(private Candidate $candidate) {}

    public function title(): string
    {
        return 'Новое из писем: '.$this->car();
    }

    public function text(): ?string
    {
        return implode(', ', array_filter([$this->candidate->vendor?->name, $this->candidate->code])) ?: null;
    }

    public function href(): string
    {
        return Surface::Crm->url('/offers/from-mail?candidate='.$this->candidate->id);
    }

    public function category(): string
    {
        return 'letters';
    }

    public function telegramSubject(): ?string
    {
        return self::subjectOf($this->candidate->id);
    }

    public static function subjectOf(int $candidateId): string
    {
        return 'candidate:'.$candidateId;
    }

    public function toTelegram(): ?array
    {
        $vin = $this->candidate->value('vin');

        return [
            'title' => $this->title(),
            'lines' => [$vin ? new HtmlString('VIN <code>'.e($vin).'</code>') : null, $this->text()],
            'button' => 'Открыть в CRM',
        ];
    }

    private function car(): string
    {
        $year = $this->candidate->value('year');

        return $this->candidate->title().($year ? ', '.$year : '');
    }
}
