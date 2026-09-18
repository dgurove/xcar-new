<?php

namespace App\Billing;

use App\Offers\Deal;
use App\Park\Vehicle;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Начисление: за что, сколько и по какой цене; в счёт попадает при выставлении, до того — «не выставлено». */
#[Fillable(['party_id', 'vehicle_id', 'deal_id', 'invoice_id', 'kind', 'title', 'qty', 'unit', 'price', 'amount', 'period_from', 'period_to', 'voided_at', 'void_reason', 'created_by'])]
class Charge extends Model
{
    protected $table = 'billing_charges';

    protected function casts(): array
    {
        return ['kind' => ChargeKind::class, 'qty' => 'float', 'price' => 'float', 'amount' => 'float', 'period_from' => 'date', 'period_to' => 'date', 'voided_at' => 'datetime'];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function unitLabel(): string
    {
        return match ($this->unit) {
            'day' => 'сут', 'km' => 'км', 'h' => 'ч', default => 'шт'
        };
    }
}
