<?php

namespace App\Offers;

use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['offer_id', 'user_id', 'placed_by', 'kind', 'amount', 'comment', 'state', 'decided_at', 'decided_by', 'cost'])]
class Bid extends Model
{
    protected function casts(): array
    {
        return ['state' => BidState::class, 'kind' => BidKind::class, 'amount' => 'int', 'cost' => 'int', 'decided_at' => 'datetime'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Кто внёс за менеджера (`PlaceBidFor`), у обычного подтверждения — никто. */
    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by');
    }

    public function byStaff(): bool
    {
        return $this->placed_by !== null;
    }

    /** «В гараж»: без цены, менеджер забирает машину себе на подготовку. */
    public function isGarage(): bool
    {
        return $this->kind === BidKind::Garage;
    }

    /** Закупочная, с которой менеджер подтверждал, — она закрепится в сделке (15.10.2026); старым — как у продажи сейчас. */
    public function frozenCost(): ?int
    {
        return $this->cost ?? $this->offer->bidFloor();
    }
}
