<?php

namespace App\Notifications;

use App\Support\Money;

/** Оценку менеджера взяли ценой продажи — тихо, строкой в ленте. */
final class EstimateTakenNotice extends EstimateNotice
{
    public function title(): string
    {
        return ($this->estimate->isCorrection() ? 'Взяли вашу цену ' : 'Вашу оценку взяли: ').Money::rub($this->estimate->amount);
    }

    public function text(): ?string
    {
        return $this->estimate->offer->titleWithYear();
    }

    public function critical(): bool
    {
        return false;
    }

    public function quiet(): bool
    {
        return true;
    }

    public function telegramSubject(): ?string
    {
        return null;
    }
}
