<?php

namespace App\Offers;

use App\Mail\Message;
use App\Support\FastAttributes;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Доп. просьба поставщику (18.11.2026, владелец по Z691/046/13570/26: Роман не берёт машину, пока не согласуют осмотр, — «пока активна
 * дополнительная просьба, ход сделки замораживается»). Письмо — `ExtraRequest` (галки шаблонов), запись — здесь: заводит
 * `Actions\LogExtraRequest`, ведёт `Actions\SettleExtraAsk`.
 *
 * Шаги (`phase`), пока просьба не решена (`decided_at`), ход идущей сделки стоит (`Position::extraAsk`):
 * - `supplier` — ждём первого ответа поставщика по машине (не автоответа, `Message::isAutomatic`) с `since_at`;
 * - `show` — ответ пришёл, наш ход: показать менеджеру (`Disclose`) или «Вопрос не решён» — снова `supplier` с этой минуты;
 * - `manager` — показали, ход менеджера: «Беру в работу» / «Отказываюсь» (`SettleExtraAsk::decide`).
 * Шаг менеджера (`asks_manager`) — у сделки с менеджером, пока поставщику не заплачено, и не у просьбы подтвердить нашу оплату;
 * у остальных ответ поставщика и решает просьбу. До согласия кнопки менеджера — «Беру в работу» / «Отказываюсь», после — «Всё в
 * порядке, берём» / «Отказываюсь» (`labels`): осмотр до «Беру в работу» и после — один модуль (18.11.2026, владелец: «это одно и то
 * же, выглядеть и работать должно одинаково»; прежний этап Альфы «Осмотр перед оплатой» снят).
 */
#[Fillable(['offer_id', 'deal_id', 'message_id', 'text', 'asks_manager', 'asked_by', 'asked_at', 'since_at', 'frozen_at', 'answered_at',
    'answer_message_id', 'shown_at', 'decided_at', 'decision', 'decided_by', 'remind_at'])]
class ExtraAsk extends Model
{
    use FastAttributes;

    public const SUPPLIER = 'supplier';

    public const SHOW = 'show';

    public const MANAGER = 'manager';

    /** Решения: менеджер берёт или отказался, просьбу решил ответ поставщика (без менеджера), сделки больше нет. */
    public const TAKE = 'take';

    public const DROP = 'drop';

    public const ANSWERED = 'answered';

    public const GONE = 'gone';

    protected function casts(): array
    {
        return [
            'asks_manager' => 'boolean',
            'asked_at' => 'datetime',
            'since_at' => 'datetime',
            'frozen_at' => 'datetime',
            'answered_at' => 'datetime',
            'shown_at' => 'datetime',
            'decided_at' => 'datetime',
            'remind_at' => 'datetime',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'answer_message_id');
    }

    public function askedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asked_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('extra_asks.decided_at');
    }

    public function isOpen(): bool
    {
        return $this->decided_at === null;
    }

    /** Шаг открытой просьбы (`SUPPLIER` | `SHOW` | `MANAGER`), решённой — null. */
    public function phase(): ?string
    {
        return match (true) {
            $this->decided_at !== null => null,
            $this->answered_at === null => self::SUPPLIER,
            $this->shown_at === null => self::SHOW,
            default => self::MANAGER,
        };
    }

    /**
     * Кнопки менеджера: до согласия — те же, что у «Согласия менеджера»; согласие уже дал — продолжить или отказаться после
     * ответа поставщика; отказ — «Отказываюсь» и до, и после (19.11.2026, начальник). @return array{take: string, drop: string}
     */
    public static function labels(Deal $deal): array
    {
        return self::consented($deal) ? ['take' => 'Всё в порядке, берём', 'drop' => 'Отказываюсь'] : ['take' => 'Беру в работу', 'drop' => 'Отказываюсь'];
    }

    /** Менеджер уже ответил на «Согласии менеджера» этой сделки. */
    public static function consented(Deal $deal): bool
    {
        return \App\Workflow\Requirement::where('deal_id', $deal->id)->whereNotNull('done_at')
            ->whereHas('stage', fn ($s) => $s->whereHas('exits', fn ($x) => $x->where('actor', 'manager')->whereIn(\Illuminate\Support\Facades\DB::raw('lower(label)'), \App\Workflow\Outcome::TAKES)))->exists();
    }

    /** «Попросили прислать ПТС и согласовать осмотр» — фраза письма без «Просьба». */
    public function phrase(): string
    {
        return mb_lcfirst(trim($this->text));
    }

    /**
     * Открытые просьбы, что держат идущую сделку: offer_id → просьба (последняя). Списки спрашивают одним запросом, как
     * `ReplyReview::waiting`; в консоли и очереди — каждый раз заново.
     *
     * @return Collection<int, self>
     */
    public static function frozen(): Collection
    {
        $load = fn () => self::open()->whereNotNull('extra_asks.deal_id')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('deals as d')->whereColumn('d.id', 'extra_asks.deal_id')->whereRaw(Deal::ACTIVE_D))
            ->orderBy('id')->get()->keyBy('offer_id');

        return app()->runningInConsole() ? $load() : once($load);
    }

    /** Открытая просьба, что держит эту идущую сделку. */
    public static function holding(?Deal $deal): ?self
    {
        if (! $deal?->id || ! $deal->isActive()) {
            return null;
        }
        $ask = self::frozen()->get($deal->offer_id);

        return $ask?->deal_id === $deal->id ? $ask : null;
    }

    /** SQL над `offer_positions`: открытая просьба держит идущую сделку машины (`Position::NEEDS_US_SQL`, `TickStages`). */
    public const HOLDS_SQL = 'exists (select 1 from extra_asks ea join deals d on d.id = ea.deal_id where ea.offer_id = offer_positions.offer_id and ea.decided_at is null and '.Deal::ACTIVE_D.')';

    /** То же — на шаге «Показать менеджеру» (наш ход). */
    public const SHOW_SQL = 'exists (select 1 from extra_asks ea join deals d on d.id = ea.deal_id where ea.offer_id = offer_positions.offer_id and ea.decided_at is null
        and ea.answered_at is not null and ea.shown_at is null and '.Deal::ACTIVE_D.')';

    /** SQL над `deals`: просьба ещё не у менеджера — его ход стоит (`Deal::MANAGER_TURN_SQL`). */
    public const DEAL_BEFORE_SQL = 'exists (select 1 from extra_asks ea where ea.deal_id = deals.id and ea.decided_at is null and ea.shown_at is null)';

    /** SQL над `deals`: ответ показали — ход менеджера. */
    public const DEAL_MANAGER_SQL = 'exists (select 1 from extra_asks ea where ea.deal_id = deals.id and ea.decided_at is null and ea.shown_at is not null and ea.asks_manager)';
}
