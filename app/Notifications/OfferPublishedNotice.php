<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Ago;
use App\Support\Money;
use App\Telegram\Offers\Subscriber;
use App\Users\User;

/** «Открыты только Вам» (`NotifyViewers::solo`) — сразу, мимо пачки «Опубликовано N». */
final class OfferPublishedNotice extends Notice
{
    public function __construct(private Offer $offer) {}

    public function label(): string
    {
        return 'Открыто только вам';
    }

    public function title(): string
    {
        return 'Новое предложение: '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->offer->asking_price ? Money::plain($this->offer->asking_price).', приём подтверждений до '.Ago::day($this->offer->bids_close_at, true) : null;
    }

    public function href(): string
    {
        return "/offers/{$this->offer->number}";
    }

    public function offerNumber(): ?int
    {
        return $this->offer->number;
    }

    public function category(): string
    {
        return 'offers';
    }

    /** Новое в Telegram с 21.10.2026 (владелец), кроме подписчика бота предложений: тот пишет о нём сам. */
    public function via(User $user): array
    {
        $via = parent::via($user);

        return in_array(TelegramChannel::class, $via, true) && Subscriber::listens($user)
            ? array_values(array_diff($via, [TelegramChannel::class])) : $via;
    }

    /** Карточка и пуш уходят сами, как прежде: Telegram — не повод висеть до нажатия. */
    public function important(): bool
    {
        return false;
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => array_values(array_filter([$this->text()])), 'button' => 'Посмотреть'];
    }

    public function telegramSubject(): ?string
    {
        return self::offerSubject($this->offer->id);
    }

    protected function visibleOffer(): ?Offer
    {
        return $this->offer;
    }
}
