<?php

namespace App\Telegram\Messages;

use App\Support\Money;
use App\Support\Surface;

/** Утренняя сводка по банку: поступления без счёта, сбой выписки, скорое окончание согласия СберБизнеса. */
final class BankDigest extends Message
{
    public function __construct(private int $unmatched, private float $sum, private ?string $error, private ?int $daysLeft) {}

    public function worthSending(): bool
    {
        return $this->unmatched > 0 || $this->error || ($this->daysLeft !== null && $this->daysLeft <= 14);
    }

    protected function title(): string
    {
        return 'Банк';
    }

    protected function lines(): array
    {
        return [
            $this->unmatched > 0 ? 'Без счёта: '.$this->unmatched.' на '.Money::rub($this->sum) : null,
            $this->error ? 'Выписка не загружается: '.$this->error : null,
            $this->daysLeft !== null && $this->daysLeft <= 14 ? 'Подключение СберБизнеса закончится через '.$this->daysLeft.' дн, подключите заново в настройках CRM' : null,
        ];
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'В CRM', 'url' => Surface::Crm->url($this->unmatched > 0 ? '/settings/bank/statement' : '/settings/bank')];
    }
}
