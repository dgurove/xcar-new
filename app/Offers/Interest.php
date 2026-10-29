<?php

namespace App\Offers;

use App\Support\FastAttributes;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['offer_id', 'user_id', 'state', 'comment', 'placed_by'])]
class Interest extends Model
{
    use FastAttributes;

    protected function casts(): array
    {
        return ['state' => InterestState::class];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Сотрудник, что внёс интерес за менеджера (`placed_by`); свой интерес — пусто. */
    public function placer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by');
    }
}
