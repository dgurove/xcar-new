<?php

namespace App\Offers;

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
#[Fillable(['offer_id', 'user_id', 'asked_by', 'state', 'due_at', 'amount', 'estimated_at', 'rejected_amount', 'rejected_at', 'reminded_at'])]
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
            'rejected_amount' => 'integer',
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

    public function isOpen(): bool
    {
        return $this->state->isOpen();
    }

    public function isGiven(): bool
    {
        return $this->state === EstimateState::Given;
    }

    /** Ждём менеджера, а срок вышел. Оценённая не просрочена: дальше ход админа. */
    public function isOverdue(): bool
    {
        return $this->state === EstimateState::Asked && $this->due_at->isPast();
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
