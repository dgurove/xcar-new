<?php

namespace App\Logistics;

use App\Notifications\Notice;
use App\Notifications\TelegramChannel;
use App\Support\Plural;
use App\Support\Surface;
use App\Users\User;

/**
 * Уведомление логистики — в logistics.xcar.ru (`category` logistics). Вывоз Совкомбанка — наша обязанность, поэтому
 * громко (17.10.2026, владелец: «уделить ОЧЕНЬ много внимания»): админам — новый вывоз без исполнителя и что дата прошла,
 * исполнителю и логисту — поручение, модератору — отправить документы, исполнителю — трек СДЭК; ход (созвонились,
 * вывезена, привёз) — админам в ленту, «Вывезена» ещё и в Telegram с ключами.
 */
final class TransportNotice extends Notice
{
    private function __construct(private Transport $transport, private string $title, private ?string $text, private bool $critical, private bool $telegram) {}

    /** Новый вывоз — админам: выбрать, кто вывозит. */
    public static function requested(Transport $t): self
    {
        return new self($t, 'Вывоз: '.$t->title(), self::where($t), true, true);
    }

    /** Поручение исполнителю или логисту. */
    public static function assigned(Transport $t, User $to): self
    {
        return $t->executor === Executor::Logist && ! $t->isExecutedBy($to)
            ? new self($t, 'Вывоз в регионе: '.$t->title(), self::where($t), true, true)
            : new self($t, 'Вам вывоз: '.$t->title(), implode(', ', array_filter([self::where($t), $t->ownerName() ? 'владелец '.$t->ownerName() : null])), true, true);
    }

    /** Модератору — оформить и отправить документы СДЭКом. */
    public static function docsNeeded(Transport $t): self
    {
        return new self($t, 'Отправьте документы: '.$t->title(), implode(', ', array_filter([$t->executorUser?->name, $t->executorUser?->cdek_point])), true, true);
    }

    /** Исполнителю — трек отправленных документов. */
    public static function docsSent(Transport $t): self
    {
        return new self($t, 'Документы отправлены СДЭКом: '.$t->title(), 'Трек '.$t->docs_track, true, true);
    }

    /** Ход задачи — админам: созвонились, вывезена (ключи), привёз, отменили. */
    public static function moved(Transport $t, string $step): self
    {
        $keys = $t->car?->keys_count;
        [$title, $text] = match ($step) {
            'agreed' => ['Созвонились: '.$t->title(), $t->next()],
            'picked' => ['Вывезена: '.$t->title(), implode(', ', array_filter([$keys === null ? null : ($keys === 1 ? '1 ключ!' : $keys.' '.Plural::of($keys, ['ключ', 'ключа', 'ключей'])), $t->destinationLabel()]))],
            'delivered' => ['Привёз: '.$t->title(), $t->destinationLabel()],
            default => [$t->state->label().': '.$t->title(), $t->cancel_reason],
        };

        return new self($t, $title, $text, false, $step === 'picked');
    }

    /** Дата вывоза прошла, «Забрал» нет — админам и исполнителю. */
    public static function late(Transport $t): self
    {
        return new self($t, 'Не вывезли в срок: '.$t->title(), $t->executorName(), true, true);
    }

    /** Город и адрес, откуда забирать. */
    private static function where(Transport $t): ?string
    {
        return implode(', ', array_filter([Choices::regionOf($t), $t->fromAddress()])) ?: null;
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
        return $this->transport->saleOffer()?->number;
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
        return $this->telegram ? ['title' => $this->title, 'lines' => [$this->text], 'button' => 'Открыть'] : null;
    }

    /** Кнопка в Telegram — прямо в логистику. */
    public function telegramUrl(): string
    {
        return $this->href();
    }

    public function via(User $user): array
    {
        $via = parent::via($user);

        return $this->telegram ? $via : array_values(array_diff($via, [TelegramChannel::class]));
    }
}
