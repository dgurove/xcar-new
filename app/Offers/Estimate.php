<?php

namespace App\Offers;

use App\Support\Ago;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Оценка цены продажи менеджером (11.10.2026): админ отдаёт черновик без цены продажи одному или нескольким менеджерам,
 * у каждого 48 часов; оценка — ориентир под полем цены продажи в CRM, ценой она становится только по «Взять».
 * Срок вышел — просьба висит просроченной, пока менеджер не оценит или админ не снимет. Действия — `Actions\*Estimate*`.
 */
#[Fillable(['offer_id', 'user_id', 'asked_by', 'state', 'due_at', 'base_price', 'amount', 'estimated_at', 'rejected_amount', 'rejected_at', 'reminded_at', 'show_declared'])]
class Estimate extends Model
{
    protected $table = 'offer_estimates';

    public const HOURS = 48;

    /** За сколько до срока напомнить менеджеру. */
    public const REMIND_HOURS = 6;

    protected function casts(): array
    {
        return [
            'state' => EstimateState::class,
            'due_at' => 'datetime',
            'estimated_at' => 'datetime',
            'rejected_at' => 'datetime',
            'reminded_at' => 'datetime',
            'amount' => 'integer',
            'base_price' => 'integer',
            'rejected_amount' => 'integer',
            'show_declared' => 'boolean',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('offer_estimates.state', EstimateState::OPEN);
    }

    public function scopeWaiting(Builder $q): Builder
    {
        return $q->where('offer_estimates.state', EstimateState::Asked);
    }

    public function scopeGiven(Builder $q): Builder
    {
        return $q->where('offer_estimates.state', EstimateState::Given);
    }

    /** Ответ, по которому решать админу: оценка без цены или своя цена вместо цены админа (не «Согласен»). */
    public function scopeNeedsDecision(Builder $q): Builder
    {
        return $q->given()->where(fn ($w) => $w->whereNull('offer_estimates.base_price')->orWhereColumn('offer_estimates.amount', '!=', 'offer_estimates.base_price'));
    }

    public function isOpen(): bool
    {
        return $this->state->isOpen();
    }

    public function isGiven(): bool
    {
        return $this->state === EstimateState::Given;
    }

    /**
     * Корректировка (11.10.2026): админ цену поставил и спрашивает, согласны ли (`base_price` — его цена на момент просьбы).
     * Иначе оценка черновика без цены.
     */
    public function isCorrection(): bool
    {
        return $this->base_price !== null;
    }

    /** Ответ на корректировку — «Согласен»: его сумма и есть цена админа. */
    public function agreed(): bool
    {
        return $this->isCorrection() && $this->isGiven() && $this->amount === $this->base_price;
    }

    /** Предложил свою цену вместо цены админа — решение за админом. */
    public function proposes(): bool
    {
        return $this->isGiven() && ! $this->agreed();
    }

    /** Заявленную этому менеджеру показали — админ решает при просьбе, за каждого (11.10.2026). */
    public function showsDeclared(): bool
    {
        return (bool) $this->show_declared;
    }

    /** Ждём менеджера, а срок вышел. Оценённая не просрочена: дальше ход админа. */
    public function isOverdue(): bool
    {
        return $this->state === EstimateState::Asked && $this->due_at->isPast();
    }

    /** Когда оценил — «только что», «12 мин назад» (строка оценки в окошке CRM). */
    public function givenAgo(): string
    {
        $ago = $this->estimated_at ? Ago::short($this->estimated_at) : 'сейчас';

        return $ago === 'сейчас' ? 'только что' : $ago.' назад';
    }

    /** Последняя открытая просьба этому человеку по предложению — одна дверь для каталога, страницы и формы. */
    public static function openFor(Offer $offer, ?User $user): ?self
    {
        if (! $user) {
            return null;
        }
        if ($offer->relationLoaded('openEstimates')) {
            return $offer->openEstimates->firstWhere('user_id', $user->id);
        }

        return $offer->openEstimates()->where('user_id', $user->id)->first();
    }
}
