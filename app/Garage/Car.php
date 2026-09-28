<?php

namespace App\Garage;

use App\Offers\Offer;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Машина в гараже: предложение, выведенное из продажи менеджеру на ремонт.
 * Всё про саму ТС — в предложении; здесь кому отдали, за сколько, расходы и чем кончилось.
 */
#[Fillable(['offer_id', 'manager_id', 'state', 'taken_at', 'cost', 'sold_at', 'sold_price', 'buyer_name', 'buyer_phone', 'commission', 'settled_at', 'invoice_id', 'note', 'created_by'])]
class Car extends Model
{
    protected $table = 'garage_cars';

    protected function casts(): array
    {
        return [
            'state' => CarState::class,
            'taken_at' => 'datetime', 'sold_at' => 'datetime', 'settled_at' => 'datetime',
            'cost' => 'int', 'sold_price' => 'int', 'commission' => 'int',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** Расходы, свежие первыми: их и читают сверху вниз. */
    public function costs(): HasMany
    {
        return $this->hasMany(Cost::class, 'garage_car_id')->orderByDesc('spent_at')->orderByDesc('id');
    }

    /** Машины одного человека: менеджеру — его, взятые под себя видит только сотрудник. */
    public function scopeOf(Builder $q, User $user): Builder
    {
        return $user->isStaff() ? $q : $q->where('manager_id', $user->id);
    }

    /** Потрачено: все расходы или только чьи-то. */
    public function spent(?Payer $payer = null): float
    {
        $costs = $this->relationLoaded('costs') ? $this->costs : $this->costs()->get();

        return round($costs->when($payer, fn ($c) => $c->where('payer', $payer))->sum('amount'), 2);
    }

    /** Вложено в машину: отдали за плюс все расходы. */
    public function invested(): float
    {
        return round((float) $this->cost + $this->spent(), 2);
    }

    /** Прибыль: цена продажи минус вложенное; не продана — null. */
    public function profit(): ?float
    {
        return $this->sold_price === null ? null : round($this->sold_price - $this->invested(), 2);
    }

    /** Сколько дней машина в гараже; проданная — по день продажи. */
    public function days(): int
    {
        return (int) $this->taken_at->startOfDay()->diffInDays(($this->sold_at ?? now())->startOfDay()) + 1;
    }

    public function isSold(): bool
    {
        return $this->state !== CarState::Repair;
    }
}
