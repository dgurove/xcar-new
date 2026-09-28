<?php

namespace App\Garage;

use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Расход по машине одной строкой: что и сколько стоило. */
#[Fillable(['garage_car_id', 'title', 'amount', 'spent_at', 'payer', 'created_by'])]
class Cost extends Model
{
    protected $table = 'garage_costs';

    protected function casts(): array
    {
        return ['amount' => 'float', 'spent_at' => 'date', 'payer' => Payer::class];
    }

    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class, 'garage_car_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
