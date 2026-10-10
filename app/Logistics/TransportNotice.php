<?php

namespace App\Logistics;

use App\Notifications\Notice;
use App\Notifications\TelegramChannel;
use App\Support\Surface;
use App\Users\User;

/**
 * Уведомление логистики — в logistics.xcar.ru (`category` logistics): логисту — новая межрегиональная, водителю и
 * партнёру — назначенная им (всегда, и в Telegram), тому, кто отправил, — забрали и сдали (в ленту).
 */
final class TransportNotice extends Notice
{
    private function __construct(private Transport $transport, private string $title, private ?string $text, private bool $critical) {}

    public static function requested(Transport $t): self
    {
        return new self($t, 'Нужен перевозчик: '.$t->title(), $t->regions(), false);
    }

    public static function assigned(Transport $t): self
    {
        return new self($t, 'Вам перевозка: '.$t->title(), self::route($t), true);
    }

    public static function moved(Transport $t): self
    {
        return new self($t, $t->state->label().': '.$t->title(), implode(', ', array_filter([$t->executorName(), self::route($t)])), false);
    }

    /** «Откуда → куда» адресами; без адресов — регионами. */
    private static function route(Transport $t): ?string
    {
        return $t->from_address || $t->to_address ? trim(($t->from_address ?? '?').' → '.($t->to_address ?? '?')) : $t->regions();
    }

    public function title(): string
    {
        return $this->title;
    }

    public function text(): ?string
    {
        return $this->text ?: null;
    }

    public function href(): string
    {
        return Surface::Logistics->url('/transports/'.$this->transport->id);
    }

    public function offerNumber(): ?int
    {
        return $this->transport->offer?->number;
    }

    public function category(): string
    {
        return 'logistics';
    }

    public function critical(): bool
    {
        return $this->critical;
    }

    public function toTelegram(): ?array
    {
        return $this->critical || str_starts_with($this->title, 'Нужен') ? ['title' => $this->title, 'lines' => [$this->text], 'button' => 'Открыть'] : null;
    }

    /** Кнопка в Telegram — прямо в логистику. */
    public function telegramUrl(): string
    {
        return $this->href();
    }

    /** В Telegram — исполнителю и логисту; админам хватает ленты. */
    public function via(User $user): array
    {
        $via = parent::via($user);

        return $user->isDriver() || $user->isPartner() || $user->isLogist() ? $via : array_values(array_diff($via, [TelegramChannel::class]));
    }
}
