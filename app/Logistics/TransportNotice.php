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
 * вывезена, привёз) — админам в ленту, «Вывезена» ещё и в Telegram с ключами. Зависшее (`logistics:tick`, 19.01.2027):
 * админам раз в день сводкой — сколько ждут исполнителя и созвона; исполнителю или логисту — «созвонитесь» / «назначьте»;
 * третье «Не дозвонился» подряд — админам сразу.
 */
final class TransportNotice extends Notice
{
    private function __construct(private ?Transport $transport, private string $title, private ?string $text, private bool $critical, private bool $telegram, private ?string $path = null) {}

    /** Новый вывоз — админам: выбрать, кто вывозит. */
    public static function requested(Transport $t): self
    {
        return new self($t, 'Вывоз: '.$t->title(), self::where($t), true, true);
    }

    /** Поручение исполнителю или логисту. */
    public static function assigned(Transport $t, User $to): self
    {
        return $t->channel === Channel::Logist && ! $t->isExecutedBy($to)
            ? new self($t, 'Вывоз в регионе: '.$t->title(), self::where($t), true, true)
            : new self($t, 'Вам вывоз: '.$t->title(), implode(', ', array_filter([self::where($t), $t->ownerName() ? 'владелец '.$t->ownerName() : null])), true, true);
    }

    /** Модератору — оформить и отправить документы СДЭКом. */
    public static function docsNeeded(Transport $t): self
    {
        return new self($t, 'Отправьте документы: '.$t->title(), implode(', ', array_filter([$t->executor?->name, $t->executor?->cdek_point])), true, true);
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
        return new self($t, 'Не вывезли в срок: '.$t->title(), $t->carrier(), true, true);
    }

    /**
     * Сводка админам раз в день: сутки без исполнителя и сутки без созвона. Одна строка в ленте (предмет — адрес списка),
     * новая заменяет вчерашнюю.
     */
    public static function stale(int $waiting, int $silent): self
    {
        $parts = array_filter([
            $waiting ? 'без исполнителя '.$waiting : null,
            $silent ? 'без созвона '.$silent : null,
        ]);

        return new self(null, 'Вывоз ждёт: '.implode(', ', $parts), 'дольше суток', true, true);
    }

    /** Сутки без шага — исполнителю «созвонитесь», логисту «назначьте исполнителя». */
    public static function callDue(Transport $t): self
    {
        return $t->state === TransportState::Logist
            ? new self($t, 'Назначьте исполнителя: '.$t->title(), self::where($t), true, true)
            : new self($t, 'Созвонитесь с владельцем: '.$t->title(), implode(', ', array_filter([$t->ownerName(), $t->ownerPhone()])), true, true);
    }

    /**
     * «Назначить (N)» пачкой — одно уведомление вместо N: исполнителю «Вам вывозов: N», логисту «Вывозы в регионах: N»,
     * модераторам — «Отправьте документы: N» (исполнитель без печати).
     */
    public static function assignedMany(int $n, bool $logist): self
    {
        return $logist
            ? new self(null, 'Вывозы в регионах: '.$n, 'назначьте исполнителей и цену', true, true, '/transports?preset=logist')
            : new self(null, 'Вам вывозов: '.$n, 'созвонитесь с владельцами', true, true, '/transports?preset=work');
    }

    public static function docsNeededMany(int $n): self
    {
        return new self(null, 'Отправьте документы: '.$n.' '.Plural::of($n, ['вывоз', 'вывоза', 'вывозов']), null, true, true, '/documents');
    }

    /** Отменили пачкой — другим админам одной строкой. */
    public static function cancelledMany(int $n, ?string $reason): self
    {
        return new self(null, 'Отменено вывозов: '.$n, $reason, false, false, '/transports?preset=all');
    }

    /**
     * Вывоз сняли — логисту и исполнителю задачи, в Telegram (20.10.2026: Татьяна могла уже отправить водителя, а отмена
     * уходила только ведущим). Пачкой — одно на человека с числом.
     */
    public static function cancelledYours(Transport $t): self
    {
        return new self($t, 'Вывоз отменён: '.$t->title(), $t->cancel_reason, true, true);
    }

    /** Покупатель аукциона забрал сам (20.10.2026) — логисту и исполнителю, что уже взяли задачу: ехать не нужно. */
    public static function auctionYours(Transport $t): self
    {
        return new self(null, 'Вывоз не нужен: '.$t->title(), 'забрал покупатель аукциона', true, true, '/transports');
    }

    public static function cancelledYoursMany(int $n, ?string $reason): self
    {
        return new self(null, 'Отменено вывозов: '.$n, $reason, true, true, '/transports?preset=all');
    }

    /** «Куда везти» поменял не тот, кто везёт, — логисту и исполнителю, у кого задача уже в работе. */
    public static function rerouted(Transport $t): self
    {
        return new self($t, 'Куда везти: '.$t->title(), $t->routeLabel() ?? 'пока неизвестно', true, true);
    }

    /** «Уже вывезли» пачкой — другим ведущим одной строкой. */
    public static function backdatedMany(int $n): self
    {
        return new self(null, 'Отмечено вывезенными: '.$n, null, false, false, '/transports?preset=done');
    }

    /** Третий раз подряд не дозвонились — админам: решать, что дальше. */
    public static function unreachable(Transport $t): self
    {
        return new self($t, 'Не дозвонились 3 раза: '.$t->title(), implode(', ', array_filter([$t->carrier(), $t->ownerPhone()])), true, true);
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
        return Surface::Logistics->url($this->path ?? ($this->transport ? '/transports/'.$this->transport->id : '/transports?preset=new'));
    }

    public function offerNumber(): ?int
    {
        return $this->transport?->saleOffer()?->number;
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
