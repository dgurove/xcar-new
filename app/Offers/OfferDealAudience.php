<?php

namespace App\Offers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка белого списка «Кому показывать в сделке»: менеджер (`user`) или группа
 * менеджеров (`group`). Пусто — машину в сделке не видит никто, кроме участников сделки.
 */
class OfferDealAudience extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'offer_deal_audience';

    protected $primaryKey = null;

    protected $guarded = [];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
