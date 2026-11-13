<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Telegram\Text;

/**
 * Менеджеру: укажите покупателя (10.11.2026, `Offers\Actions\AskBuyer`) — сразу после «Беру в работу», чтобы реквизиты ушли
 * страховой в день запроса. Ссылка — на сделку с окном «Кто покупатель?» (`?buyer=1`).
 */
final class BuyerAskNotice extends Notice
{
    /** @param  ?string  $need  чего не хватает после внесения фото («Приложите паспорт ИП», `AskBuyer::need`) */
    public function __construct(private Deal $deal, private ?string $need = null) {}

    /** Покупателя указали или прислали его реквизиты фотографией раньше, чем дошла очередь, — не шлём. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return Deal::whereKey($this->deal->id)->whereNotNull('buyer_asked_at')->whereNull('buyer_scans_at')->exists() && parent::shouldSend($notifiable, $channel);
    }

    public function label(): string
    {
        return $this->need ?? 'Укажите покупателя';
    }

    public function title(): string
    {
        return ($this->need ?? 'Укажите покупателя').' — № '.$this->deal->offer->number;
    }

    public function text(): ?string
    {
        return $this->need ? 'Реквизиты с фото внесли' : 'Страховая попросит реквизиты, отправим сразу';
    }

    public function href(): string
    {
        return $this->deal->href().($this->need ? '' : '?buyer=1');
    }

    public function offerNumber(): ?int
    {
        return $this->deal->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function important(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        return ['title' => ($this->need ?? 'Укажите покупателя').': '.$this->deal->offer->titleWithYear(),
            'lines' => Text::lines($this->deal->offer, $this->text()),
            'button' => $this->need ? 'Открыть сделку' : 'Указать покупателя'];
    }

    public function speaksRoute(): bool
    {
        return true;
    }
}
