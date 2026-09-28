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

    /**
     * Кто может поправить строку: сотрудник — любую, менеджер — только свою и только пока машина
     * не продана (иначе задним числом «вспомненный» расход уменьшает то, что он нам отдаёт).
     * После счёта — никто: сумма уже в документе.
     */
    public function editableBy(User $user): bool
    {
        $car = $this->car;
        if ($car->isFrozen()) {
            return false;
        }

        return $user->isStaff() || ($this->payer === Payer::Manager && ! $car->isSold() && $car->manager_id === $user->id);
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
