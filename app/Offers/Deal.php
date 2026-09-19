<?php

namespace App\Offers;

use App\Users\User;
use App\Workflow\Requirement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['offer_id', 'bid_id', 'buyer_id', 'amount', 'state', 'notes', 'closed_at'])]
class Deal extends Model
{
    protected function casts(): array
    {
        return ['state' => DealState::class, 'amount' => 'int', 'closed_at' => 'datetime'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(Requirement::class)->latest();
    }

    public function openRequirement(): HasOne
    {
        return $this->hasOne(Requirement::class)->whereNull('done_at')->latestOfMany();
    }

    public function isActive(): bool
    {
        return $this->state === DealState::Active;
    }
}
