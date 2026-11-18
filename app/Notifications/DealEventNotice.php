<?php

namespace App\Notifications;

use App\Billing\Payment;
use App\Mail\Message;
use App\Offers\Bid;
use App\Offers\Deal;
use App\Offers\Offer;
use App\Support\Money;
use App\Support\Surface;
use App\Telegram\Text;
use App\Users\User;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use Illuminate\Support\Str;

/**
 * Что произошло по сделке — админам в ленту, пушем и в Telegram (18.11.2026, начальник: «все уведомления по всем событиям по
 * сделкам — и когда мы действие сделали, и когда другие что-то сделали»): письмо поставщику ушло, доп. просьба, шаг сдвинул
 * коллега, менеджер нажал шаг, сделка открыта или отменена, новое подтверждение, оплата принята или отклонена. Своих действий
 * человек не получает (`Notify::toStaff`). Звенит и висит только то, что ждёт нас (`loud`), остальное уходит само.
 * Заголовок «кто: что — машина»: капсула берёт «кто: что» (`brief`) и машину (`car`).
 */
final class DealEventNotice extends Notice
{
    private function __construct(
        private Offer $offer,
        private string $key,
        private string $label,
        private string $what,
        private ?string $line,
        private string $cat,
        private ?User $by,
        private bool $loud = false,
        private ?string $path = null,
    ) {}

    /** Наше письмо по сделке ушло; доп. просьба — своими словами. Пачкой: десять писем подряд — одна строка. */
    public static function ourLetter(Offer $offer, Message $letter, ?User $by): self
    {
        $extra = trim((string) ($letter->requests['text'] ?? ''));
        $words = Str::limit(trim((string) preg_replace('/\s+/u', ' ', $letter->shownText())), 160);

        return new self($offer, $extra !== '' ? 'extra-ask' : 'our-letter', $extra !== '' ? 'Доп. просьба поставщику' : 'Письмо поставщику ушло',
            $extra !== '' ? 'доп. просьба: '.$extra : 'письмо поставщику', $extra !== '' ? null : ($words ?: $letter->subject), 'our_mail', $by,
            path: '/offers/'.$offer->number.'?window='.rawurlencode('/offers/'.$offer->number.'/letters?at='.$letter->id));
    }

    /** Шаг сделки сдвинул сотрудник: «Андрей Кузнецов: «Оплата получена» → «Подписание»». */
    public static function stageMoved(Deal $deal, Stage $to, ?Outcome $exit, User $by): self
    {
        return new self($deal->offer, 'stage', 'Шаг сделки сдвинули', self::step($exit, $to), null, 'deals', $by, path: self::dealPath($deal));
    }

    /** Менеджер нажал шаг, после которого ждём его или поставщика (ход наш — `OurTurnNotice`). */
    public static function managerStep(Deal $deal, Stage $to, ?Outcome $exit, User $manager): self
    {
        return new self($deal->offer, 'manager-step', 'Менеджер нажал шаг', self::step($exit, $to), null, 'managers', $manager, path: self::dealPath($deal));
    }

    /** Сделка открыта: подтверждение принято или машину отдали. */
    public static function dealOpened(Deal $deal, ?User $by): self
    {
        $who = $deal->buyer?->shortName() ?? 'аукцион';

        return new self($deal->offer, 'opened', 'Сделка открыта', 'открыл сделку, менеджер '.$who.($deal->amount ? ', '.Money::rub((int) $deal->amount) : ''), null, 'deals', $by,
            path: self::dealPath($deal));
    }

    /** Сделка отменена; сам менеджер («Я передумал», «Отказываюсь») — громко: машину снова продавать. */
    public static function dealCancelled(Deal $deal, ?User $by): self
    {
        $himself = $by && $deal->buyer_id === $by->id;

        return new self($deal->offer, 'cancelled', 'Сделка отменена', $himself ? 'передумал, сделка отменена' : 'сделка отменена', null,
            $himself ? 'managers' : 'deals', $by, loud: $himself, path: self::dealPath($deal));
    }

    /** «Показать менеджеру»: ответ поставщика ушёл менеджеру. */
    public static function disclosed(Deal $deal, User $by): self
    {
        return new self($deal->offer, 'disclosed', 'Показали менеджеру', 'показал менеджеру ответ поставщика', null, 'deals', $by, path: self::dealPath($deal));
    }

    /** Новое подтверждение менеджера. */
    public static function bidPlaced(Bid $bid, ?User $by): self
    {
        $bid->loadMissing('offer', 'user');

        return new self($bid->offer, 'bid', 'Новое подтверждение', 'подтверждает '.Money::rub((int) $bid->amount), null, 'bids', $by ?? $bid->user,
            path: '/offers/'.$bid->offer->number);
    }

    /** Оплату приняли или отклонили. */
    public static function paymentDecided(Payment $p, bool $confirmed, ?User $by): self
    {
        $offer = $p->invoice->deal->offer;

        return new self($offer, $confirmed ? 'payment-confirmed' : 'payment-rejected', $confirmed ? 'Оплата принята' : 'Оплата отклонена',
            ($confirmed ? 'принял оплату ' : 'отклонил оплату ').Money::rub((int) $p->amount), $confirmed ? null : $p->reject_reason, 'money', $by,
            path: '/work/money/invoices/'.$p->invoice_id);
    }

    private static function step(?Outcome $exit, Stage $to): string
    {
        return $exit ? '«'.trim($exit->label).'» → '.$to->name : $to->name;
    }

    private static function dealPath(Deal $deal): string
    {
        return '/work/deals/'.$deal->id;
    }

    public function kind(): string
    {
        return 'DealEventNotice:'.$this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function title(): string
    {
        return ($this->by?->shortName() ?? 'Система').': '.$this->what.' — '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->line;
    }

    public function href(): string
    {
        $path = $this->path ?? '/offers/'.$this->offer->number;

        return str_starts_with($path, '/work/') ? $path : Surface::Crm->url($path);
    }

    /** Открыл машину или сделку — строка прочитана. */
    public function subject(): string
    {
        return $this->path && str_starts_with($this->path, '/work/deals/') ? $this->path : '/offers/'.$this->offer->number;
    }

    public function offerNumber(): ?int
    {
        return $this->offer->number;
    }

    public function car(): ?string
    {
        return $this->offer->titleWithYear();
    }

    public function actor(): ?User
    {
        return $this->by;
    }

    public function category(): string
    {
        return $this->cat;
    }

    public function important(): bool
    {
        return $this->loud;
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => Text::lines($this->offer, $this->line), 'button' => 'Открыть'];
    }

    /** Свои письма подряд — одной строкой: «Письма поставщикам: 9». */
    public function batchKey(): ?string
    {
        return in_array($this->key, ['our-letter', 'extra-ask'], true) ? 'our-mail' : null;
    }

    public function batchLine(): string
    {
        return $this->offer->titleWithYear();
    }
}
