<?php

namespace App\Logistics;

use App\Notifications\Notice;
use App\Notifications\TelegramChannel;
use App\Support\Plural;
use App\Support\Surface;
use App\Users\User;
use Illuminate\Support\Str;

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
    private function __construct(private ?Transport $transport, private string $title, private ?string $text, private bool $critical, private bool $telegram, private ?string $path = null,
        private ?string $batch = null, private bool $quiet = false) {}

    /**
     * Ведущим логистику (админы, модераторы) — пачкой (`Notifications\Batch`, 27.10.2026: «Привёз: …» по одному на машину —
     * 37 за неделю каждому); ход задачи — ещё и тихо, строкой ленты: везёт логист, ведущим знать, а не бежать.
     */
    public function forLeads(string $batch, bool $quiet = false): self
    {
        $copy = new self($this->transport, $this->title, $this->text, $this->critical, $this->telegram, $this->path, $batch, $quiet);

        return $this->variantLabel() ? $copy->variant(Str::after($this->kind(), ':'), $this->variantLabel()) : $copy;
    }

    public function batchKey(): ?string
    {
        return $this->batch;
    }

    public function batchLine(): string
    {
        return $this->transport?->title() ?? $this->title;
    }

    public function quiet(): bool
    {
        return $this->quiet;
    }

    /** Новый вывоз — админам: выбрать, кто вывозит. */
    public static function requested(Transport $t): self
    {
        return (new self($t, 'Вывоз: '.$t->title(), self::where($t), true, true))->variant('requested', 'Новый вывоз');
    }

    /**
     * Новый вывоз два часа без исполнителя — ведущим (07.11.2026, владелец: «уведомления — только где нужен я»; сам новый
     * вывоз приходит строкой в ленте, открывали 0 из 97): тогда уже пора решать, кто везёт.
     */
    public static function waiting(Transport $t): self
    {
        return (new self($t, 'Вывоз ждёт назначения: '.$t->title(), self::where($t), false, true, batch: 'transport-waiting'))->variant('waiting', 'Вывоз ждёт назначения');
    }

    /** Поручение исполнителю или логисту. */
    public static function assigned(Transport $t, User $to): self
    {
        return ($t->channel === Channel::Logist && ! $t->isExecutedBy($to)
            ? new self($t, 'Вывоз в регионе: '.$t->title(), self::where($t), true, true)
            : new self($t, 'Вам вывоз: '.$t->title(), implode(', ', array_filter([self::where($t), $t->ownerName() ? 'владелец '.$t->ownerName() : null])), true, true))->variant('assigned', 'Вывоз поручен');
    }

    /** Модератору — оформить и отправить документы СДЭКом. */
    public static function docsNeeded(Transport $t): self
    {
        return (new self($t, 'Отправьте документы: '.$t->title(), implode(', ', array_filter([$t->executor?->name, $t->executor?->cdek_point])), true, true))->variant('docs-needed', 'Отправьте документы');
    }

    /** Исполнителю — трек отправленных документов. */
    public static function docsSent(Transport $t): self
    {
        return (new self($t, 'Документы отправлены СДЭКом: '.$t->title(), 'Трек '.$t->docs_track, true, true))->variant('docs-sent', 'Документы отправлены');
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

        return (new self($t, $title, $text, false, $step === 'picked'))->variant('moved', 'Ход вывоза');
    }

    /** Дата вывоза прошла, «Забрал» нет — админам и исполнителю. */
    public static function late(Transport $t): self
    {
        return (new self($t, 'Не вывезли в срок: '.$t->title(), $t->carrier(), true, true))->variant('late', 'Не вывезли в срок');
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

        return (new self(null, 'Вывоз ждёт: '.implode(', ', $parts), 'дольше суток', true, true))->variant('stale', 'Вывоз ждёт');
    }

    /** Сутки без шага — исполнителю «созвонитесь», логисту «назначьте исполнителя». */
    public static function callDue(Transport $t): self
    {
        return ($t->state === TransportState::Logist
            ? new self($t, 'Назначьте исполнителя: '.$t->title(), self::where($t), true, true)
            : new self($t, 'Созвонитесь с владельцем: '.$t->title(), implode(', ', array_filter([$t->ownerName(), $t->ownerPhone()])), true, true))->variant('call-due', 'Назначить или созвониться');
    }

    /**
     * «Назначить (N)» пачкой — одно уведомление вместо N: исполнителю «Вам вывозов: N», логисту «Вывозы в регионах: N»,
     * модераторам — «Отправьте документы: N» (исполнитель без печати).
     */
    public static function assignedMany(int $n, bool $logist): self
    {
        return ($logist
            ? new self(null, 'Вывозы в регионах: '.$n, 'назначьте исполнителей и цену', true, true, '/transports?preset=logist')
            : new self(null, 'Вам вывозов: '.$n, 'созвонитесь с владельцами', true, true, '/transports?preset=work'))->variant('assigned-many', 'Вывозы поручены');
    }

    public static function docsNeededMany(int $n): self
    {
        return (new self(null, 'Отправьте документы: '.$n.' '.Plural::of($n, ['вывоз', 'вывоза', 'вывозов']), null, true, true, '/documents'))->variant('docs-needed-many', 'Отправьте документы, пачкой');
    }

    /** Отменили пачкой — другим админам одной строкой. */
    public static function cancelledMany(int $n, ?string $reason): self
    {
        return (new self(null, 'Отменено вывозов: '.$n, $reason, false, false, '/transports?preset=all'))->variant('cancelled-many', 'Вывозы отменены');
    }

    /**
     * Вывоз сняли — логисту и исполнителю задачи, в Telegram (20.10.2026: Татьяна могла уже отправить водителя, а отмена
     * уходила только ведущим). Пачкой — одно на человека с числом.
     */
    public static function cancelledYours(Transport $t): self
    {
        return (new self($t, 'Вывоз отменён: '.$t->title(), $t->cancel_reason, true, true))->variant('cancelled-yours', 'Ваш вывоз отменён');
    }

    /** Покупатель аукциона забрал сам (20.10.2026) — логисту и исполнителю, что уже взяли задачу: ехать не нужно. */
    public static function auctionYours(Transport $t): self
    {
        return (new self(null, 'Вывоз не нужен: '.$t->title(), 'забрал покупатель аукциона', true, true, '/transports'))->variant('auction-yours', 'Вывоз не нужен');
    }

    public static function cancelledYoursMany(int $n, ?string $reason): self
    {
        return (new self(null, 'Отменено вывозов: '.$n, $reason, true, true, '/transports?preset=all'))->variant('cancelled-yours-many', 'Ваши вывозы отменены');
    }

    /** «Куда везти» поменял не тот, кто везёт, — логисту и исполнителю, у кого задача уже в работе. */
    public static function rerouted(Transport $t): self
    {
        return (new self($t, 'Куда везти: '.$t->title(), $t->routeLabel() ?? 'пока неизвестно', true, true))->variant('rerouted', 'Куда везти');
    }

    /**
     * ТС отдали в гараж менеджеру, пока вывоз в работе (10.11.2026, админ: «когда отдаю в гараж — нужно, чтобы исполнителя
     * информировала, что ушла в гараж и какому менеджеру»): логисту и исполнителю — куда теперь везти, в Telegram.
     */
    public static function toGarage(Transport $t): self
    {
        return (new self($t, 'В гараж: '.$t->title(), 'везти: '.($t->routeLabel() ?? 'менеджеру'), true, true))->variant('to-garage', 'Ушла в гараж');
    }

    /** Везёт сам менеджер — логисту и исполнителю, что уже взяли задачу: ехать не нужно (10.11.2026). */
    public static function selfPickup(Transport $t): self
    {
        return (new self($t, 'Вывоз не нужен: '.$t->title(), 'забирает сам менеджер'.(($m = $t->executor?->shortName()) ? ' '.$m : ''), true, true))->variant('self-pickup', 'Забирает менеджер');
    }

    /** «Уже вывезли» пачкой — другим ведущим одной строкой. */
    public static function backdatedMany(int $n): self
    {
        return (new self(null, 'Отмечено вывезенными: '.$n, null, false, false, '/transports?preset=done'))->variant('backdated-many', 'Отмечены вывезенными');
    }

    /** Третий раз подряд не дозвонились — админам: решать, что дальше. */
    public static function unreachable(Transport $t): self
    {
        return (new self($t, 'Не дозвонились 3 раза: '.$t->title(), implode(', ', array_filter([$t->carrier(), $t->ownerPhone()])), true, true))->variant('unreachable', 'Не дозвонились');
    }

    /** Город и адрес, откуда забирать. */
    private static function where(Transport $t): ?string
    {
        return implode(', ', array_filter([Choices::regionOf($t), $t->fromAddress()])) ?: null;
    }

    public function label(): string
    {
        return $this->variantLabel() ?? 'Вывоз';
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
