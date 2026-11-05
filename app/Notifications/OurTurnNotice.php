<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Workflow\Outcome;
use App\Workflow\Stage;

/**
 * Менеджер сделал своё — ход наш, и делать надо сейчас (05.11.2026, владелец: «менеджер заполнил реквизиты и надо срочно
 * писать письмо в страховую — вот тут уведомление уместно»; вместо спама о каждом новом подтверждении). Сотрудникам громко и
 * в Telegram: что сделал менеджер и что теперь за нами. Согласие «Беру в работу» у Альфы — своё `ConsentGivenNotice`.
 */
final class OurTurnNotice extends Notice
{
    public function __construct(private Deal $deal, private Stage $to, private ?Outcome $exit = null) {}

    public function label(): string
    {
        return 'Ход за нами';
    }

    public function title(): string
    {
        $did = $this->exit ? '«'.$this->exit->label.'»' : 'сделал своё';

        return ($this->deal->buyer?->name ?? 'Менеджер').': '.$did.' — '.$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return ($this->to->isLetterStep() ? 'Напишите поставщику: ' : 'Ваш ход: ').'«'.$this->to->name.'»';
    }

    public function href(): string
    {
        return '/work/deals/'.$this->deal->id;
    }

    public function offerNumber(): ?int
    {
        return $this->deal->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function subject(): string
    {
        return '/work/deals/'.$this->deal->id;
    }

    public function critical(): bool
    {
        return true;
    }

    public function important(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => [$this->text()], 'button' => 'Открыть сделку'];
    }
}
