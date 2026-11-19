<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Telegram\Text;

/**
 * Менеджеру: отправленный ему счёт или договор отозвали (19.11.2026, `ShowToManager::recall*`) — поправим и пришлём снова.
 * По делу, без описаний: «Счёт № 248 отозван».
 */
final class DocRecalledNotice extends Notice
{
    public function __construct(private Deal $deal, private string $what) {}

    public function label(): string
    {
        return $this->what.' отозван';
    }

    public function title(): string
    {
        return $this->what.' отозван: '.$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return null;
    }

    public function href(): string
    {
        return $this->deal->href();
    }

    public function offerNumber(): ?int
    {
        return $this->deal->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function critical(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => Text::lines($this->deal->offer, null), 'button' => 'Открыть сделку'];
    }
}
