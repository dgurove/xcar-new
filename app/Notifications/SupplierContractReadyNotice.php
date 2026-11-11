<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Offers\Signing;

/**
 * Админам: поставщик пишет, что договор готов (11.11.2026, Альфа по BMW X6 № 2610041185 — письмо лежало в «Разобрать ответ»
 * молча, о нём сказал владелец). Наш ход — сразу письмо «запишите на …» со временем, что менеджер назвал заранее (`{{ signing_when }}`).
 */
final class SupplierContractReadyNotice extends Notice
{
    public function __construct(private Deal $deal) {}

    public function label(): string
    {
        return 'Договор готов';
    }

    public function title(): string
    {
        return ($this->deal->offer->vendor?->name ?? 'Поставщик').': договор готов — '.$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        $wish = Signing::wishLine($this->deal);

        return $wish ? 'Покупатель может '.$wish.' — напишите поставщику о записи' : 'Менеджер ещё не назвал время подписания';
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

    public function important(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => [$this->text()], 'button' => 'Открыть сделку'];
    }
}
