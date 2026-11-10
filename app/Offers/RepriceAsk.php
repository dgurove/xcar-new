<?php

namespace App\Offers;

use App\Support\FastAttributes;
use App\Support\Money;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Вопрос менеджеру о новой закупочной (28.10.2026, владелец: «ставим менеджера перед фактом — нужно уведомление с вопросом,
 * согласен он или отказывается»; 03.11.2026: «автоматику убрать», спрашивает только админ, в любую сторону, цифрами — ни слова
 * письма). Заводит `Actions\ApplyReprice` на «Спросить», отвечает `Actions\AnswerReprice` — менеджер (страница, Telegram) или админ
 * за него. До ответа у него прежняя закупочная; не ответил к `due_at` (17:00 следующего дня) — горит у нас.
 * Закупочной на xcar нет ни у кого: `shown_*` — то, что он видит (`shownFor`, `shownAs`).
 *
 * Ответ — его новая цена подтверждения (10.11.2026, владелец по 0790/046/09068/26: «Согласен» поменял только закупочную, сумма
 * сделки 5 440 000 осталась при закупочной 5 550 000 — «где ввод его новой цены подтверждения»): видит было → стало
 * (`shown_*` и цена продажи `asking_*`) и вводит цену. По подтверждению она сразу его цена (дальше решаем, как по любому
 * подтверждению); по сделке — `offered`, решает админ: принять, контрпредложение (`countered`, ход снова его) или отказ.
 */
#[Fillable(['reprice_id', 'offer_id', 'bid_id', 'deal_id', 'user_id', 'from_price', 'to_price', 'shown_from', 'shown_to', 'state', 'due_at', 'answered_at', 'answered_by',
    'asking_from', 'asking_to', 'offered_price', 'offered_at', 'counter_price', 'counter_at'])]
class RepriceAsk extends Model
{
    use FastAttributes;

    public const PENDING = 'pending';

    /** Менеджер назвал новую цену по сделке — решаем мы. */
    public const OFFERED = 'offered';

    /** Наше контрпредложение по сделке — ход снова его. */
    public const COUNTERED = 'countered';

    /** Вопрос живой: ждём менеджера или решаем мы. */
    public const OPEN = [self::PENDING, self::OFFERED, self::COUNTERED];

    /** Ход менеджера: ответить на вопрос или на наше контрпредложение. */
    public const HIS_TURN = [self::PENDING, self::COUNTERED];

    public const AGREED = 'agreed';

    public const DECLINED = 'declined';

    /** Погас без ответа: новая переоценка, сделку или подтверждение сняли другим путём. */
    public const VOID = 'void';

    protected function casts(): array
    {
        return ['from_price' => 'int', 'to_price' => 'int', 'shown_from' => 'int', 'shown_to' => 'int', 'due_at' => 'datetime', 'answered_at' => 'datetime',
            'asking_from' => 'int', 'asking_to' => 'int', 'offered_price' => 'int', 'offered_at' => 'datetime', 'counter_price' => 'int', 'counter_at' => 'datetime'];
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

    /** Живые: ждём менеджера или решаем мы. */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('reprice_asks.state', self::OPEN);
    }

    public function isPending(): bool
    {
        return $this->state === self::PENDING;
    }

    public function isOpen(): bool
    {
        return in_array($this->state, self::OPEN, true);
    }

    /** Менеджер назвал цену — решаем мы. */
    public function isOffered(): bool
    {
        return $this->state === self::OFFERED;
    }

    public function isCountered(): bool
    {
        return $this->state === self::COUNTERED;
    }

    /** Ход менеджера: вопрос или наше контрпредложение. */
    public function hisTurn(): bool
    {
        return in_array($this->state, self::HIS_TURN, true);
    }

    /** Вопрос ещё про живое: подтверждение не сняли, сделку не отменили другим путём. */
    public function alive(): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        return $this->bid_id ? $this->bid?->state === BidState::Active : $this->deal?->state === DealState::Active;
    }

    /**
     * Ждущие вопросы идущих сделок: offer_id → срок ответа. Списки («Сделки» CRM и менеджера, «Ваш ход», бейдж) спрашивают
     * про каждую строку — одним запросом на веб-запрос (в очереди и консоли `once` жил бы весь процесс — там без памяти).
     *
     * @return Collection<int, Carbon>
     */
    public static function dealDue(): Collection
    {
        $load = fn () => self::whereIn('reprice_asks.state', self::HIS_TURN)->whereNotNull('deal_id')->whereHas('deal', fn ($q) => $q->where('state', DealState::Active))
            ->pluck('due_at', 'offer_id')->map(fn ($at) => Carbon::parse($at));

        return app()->runningInConsole() ? $load() : once($load);
    }

    /** Менеджер назвал цену по идущей сделке — решаем мы: offer_id → когда назвал. Одним запросом, как `dealDue`. */
    public static function dealOffered(): Collection
    {
        $load = fn () => self::where('reprice_asks.state', self::OFFERED)->whereNotNull('deal_id')->whereHas('deal', fn ($q) => $q->where('state', DealState::Active))
            ->pluck('offered_at', 'offer_id')->map(fn ($at) => Carbon::parse($at));

        return app()->runningInConsole() ? $load() : once($load);
    }

    public function late(): bool
    {
        return $this->hisTurn() && $this->due_at?->isPast();
    }

    /** Его цена подтверждения до вопроса: у сделки — сумма сделки, у подтверждения — его сумма. */
    public function amountWas(): ?int
    {
        $v = $this->deal_id ? $this->deal?->amount : $this->bid?->amount;

        return $v === null ? null : (int) $v;
    }

    /** Цена, на которой сойдёмся при «Принять» / «Согласен»: наше контрпредложение или его. */
    public function settlePrice(): ?int
    {
        return $this->isCountered() ? $this->counter_price : $this->offered_price;
    }

    /**
     * Было → стало для менеджера двумя парами (владелец: «было 5 189 000 → 5 490 000, стало 5 550 000 → 5 700 000»):
     * то, что он видит (`shown_*`), и цена продажи. Цены продажи нет (старый вопрос) или видит он вознаграждение — одно число.
     *
     * @return array{was: string, now: string}
     */
    public function pairs(): array
    {
        $reward = $this->shownAs() === 'reward';
        $pair = fn (?int $a, ?int $b) => Money::nums((int) $a).($b && ! $reward ? ' → '.Money::rub($b) : ' ₽');

        return ['was' => $pair($this->shown_from, $this->asking_from), 'now' => $pair($this->shown_to, $this->asking_to)];
    }

    /** Ответил админ за менеджера. */
    public function byStaff(): bool
    {
        return $this->answered_by !== null && $this->answered_by !== $this->user_id;
    }

    /** Срок ответа: 17:00 следующего дня по Москве, как у «Согласия менеджера» (`DeadlineSource::NextDay17`). */
    public static function dueFrom(Carbon $now): Carbon
    {
        // Без ->utc(): база хранит время без пояса в поясе приложения — UTC сохранялся бы как «14:00» вместо 17:00.
        return $now->copy()->setTimezone('Europe/Moscow')->addDay()->setTime(17, 0)->setTimezone(config('app.timezone'));
    }

    /**
     * Что менеджер видит из этой переоценки (закупочной на xcar нет ни у кого — CLAUDE.md): у подтверждения — цена «от»
     * (заявленная вписана рукой или «от» не сдвинулась — для него ничего не поменялось), у сделки ДКП, «страховой напрямую»
     * и гаража — закупочная (она у него в «Расчёте»), у ПРАЙМ, где платит он сам, — его вознаграждение (разница цены и
     * закупочной, строка счёта). У остальных — супер-покупателя, покупателя с гаражом, ПРАЙМ, где платит его покупатель, —
     * для него не меняется ничего: null, цена встаёт молча.
     *
     * @return array{0: int, 1: int}|null
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
        if ($on->paysSelection() || $on->isGarage()) {
            return [$from, $to];
        }
        $contract = $on->contract;
        if ($on->isPrime() && ! $on->isDirect() && $on->amount !== null && (! $contract?->buyer_user_id || $contract->managerPays())) {
            return [max(0, (int) $on->amount - $from), max(0, (int) $on->amount - $to)];
        }

        return null;
    }

    /** Чем для менеджера выражена перемена: цена «от» подтверждения, закупочная сделки или его вознаграждение. */
    public function shownAs(): string
    {
        return match (true) {
            $this->bid_id !== null => 'from',
            $this->deal && ($this->deal->paysSelection() || $this->deal->isGarage()) => 'cost',
            default => 'reward',
        };
    }

    /** Подпись того, что поменялось: «Цена «от»», «Закупочная», «Вознаграждение». */
    public function shownLabel(): string
    {
        return ['from' => 'Цена «от»', 'cost' => 'Закупочная', 'reward' => 'Вознаграждение'][$this->shownAs()];
    }

    /** Что поменялось — словами для менеджера: «Цена «от» 900 000 → 950 000 ₽», «Вознаграждение 100 000 → 50 000 ₽». */
    public function phrase(): string
    {
        return $this->shownLabel().' '.Money::nums((int) $this->shown_from).' → '.Money::rub((int) $this->shown_to);
    }

    /** Что будет при отказе — словами кнопки и переспроса. */
    public function declineMeans(): string
    {
        return $this->deal_id ? 'Сделка будет отменена, ТС вернётся в продажу' : 'Подтверждение будет снято';
    }
}
