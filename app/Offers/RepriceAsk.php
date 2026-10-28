<?php

namespace App\Offers;

use App\Support\Money;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Вопрос менеджеру о выросшей закупочной (28.10.2026, владелец: «когда закупочная повышается, мы просто ставим менеджера
 * перед фактом — нужно уведомление с вопросом, согласен он или отказывается; когда понижается — не спрашивать»). Заводит
 * `Actions\ApplyReprice` на «Обновить» вверх, отвечает `Actions\AnswerReprice` — менеджер (страница, Telegram) или админ
 * за него. До ответа у него прежняя закупочная; не ответил к `due_at` (17:00 следующего дня) — горит у нас.
 * Закупочной на xcar нет ни у кого: `shown_*` — то, что он видит (`shownFor`).
 */
#[Fillable(['reprice_id', 'offer_id', 'bid_id', 'deal_id', 'user_id', 'from_price', 'to_price', 'shown_from', 'shown_to', 'state', 'due_at', 'answered_at', 'answered_by'])]
class RepriceAsk extends Model
{
    public const PENDING = 'pending';

    public const AGREED = 'agreed';

    public const DECLINED = 'declined';

    /** Погас без ответа: новая переоценка, сделку или подтверждение сняли другим путём. */
    public const VOID = 'void';

    protected function casts(): array
    {
        return ['from_price' => 'int', 'to_price' => 'int', 'shown_from' => 'int', 'shown_to' => 'int', 'due_at' => 'datetime', 'answered_at' => 'datetime'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('reprice_asks.state', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    /** Вопрос ещё про живое: подтверждение не сняли, сделку не отменили другим путём. */
    public function alive(): bool
    {
        if (! $this->isPending()) {
            return false;
        }

        return $this->bid_id ? $this->bid?->state === BidState::Active : $this->deal?->state === DealState::Active;
    }

    public function late(): bool
    {
        return $this->isPending() && $this->due_at?->isPast();
    }

    /** Ответил админ за менеджера. */
    public function byStaff(): bool
    {
        return $this->answered_by !== null && $this->answered_by !== $this->user_id;
    }

    /** Срок ответа: 17:00 следующего дня по Москве, как у «Согласия менеджера» (`DeadlineSource::NextDay17`). */
    public static function dueFrom(Carbon $now): Carbon
    {
        return $now->copy()->setTimezone('Europe/Moscow')->addDay()->setTime(17, 0)->utc();
    }

    /**
     * Что менеджер видит из этой переоценки, — правило прежнего уведомления о закупочной: у подтверждения — цена «от»
     * (заявленная вписана рукой или «от» не сдвинулась — для него ничего не поменялось), у сделки ДКП, «страховой напрямую»
     * и гаража — закупочная числом, у остальных сделок — только разница. Супер-покупателю и покупателю с гаражом закупочная
     * не видна вовсе — его не спрашиваем. null — спрашивать не о чем, цена встаёт молча.
     *
     * @return array{0: ?int, 1: ?int}|null
     */
    public static function shownFor(?User $user, Bid|Deal $on, Offer $offer, int $from, int $to): ?array
    {
        if (! $user || $user->isSuperBuyer() || $user->holdsAsBuyer()) {
            return null;
        }
        if ($on instanceof Bid) {
            if ($offer->publish_price !== null || Offer::declaredFrom($from) === Offer::declaredFrom($to)) {
                return null;
            }

            return [Offer::declaredFrom($from), Offer::declaredFrom($to)];
        }

        return $on->paysSelection() || $on->isGarage() ? [$from, $to] : [null, null];
    }

    /** Что выросло — словами для менеджера: «Цена «от» 900 000 → 950 000 ₽», «Закупочная …», «Закупочная выросла на 50 000 ₽». */
    public function phrase(): string
    {
        if ($this->shown_from !== null && $this->shown_to !== null) {
            return ($this->bid_id ? 'Цена «от» ' : 'Закупочная ').Money::nums($this->shown_from).' → '.Money::rub($this->shown_to);
        }

        return 'Закупочная выросла на '.Money::rub($this->to_price - $this->from_price);
    }

    /** Что будет при отказе — словами кнопки и переспроса. */
    public function declineMeans(): string
    {
        return $this->deal_id ? 'Сделка будет отменена, ТС вернётся в продажу' : 'Подтверждение будет снято';
    }
}
