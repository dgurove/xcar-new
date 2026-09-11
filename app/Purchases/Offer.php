<?php

namespace App\Purchases;

use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Цена, названная покупателем за машину закупки. */
#[Fillable(['car_id', 'user_id', 'amount', 'comment', 'state'])]
class Offer extends Model
{
    protected $table = 'purchase_offers';

    protected function casts(): array
    {
        return ['state' => OfferState::class, 'amount' => 'int'];
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class, 'car_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
