<?php

namespace App\Logistics;

use App\Cars\Settlement;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Стоянка логиста (18.10.2026, владелец: «вывезли в Кирове, поставили на стоянку — машина ждёт Андрея, аукцион…»): его
 * промежуточная точка — название, город, адрес, кто на месте и телефон. Справочник его (`logist_id`), как заказчики: другие
 * логисты не видят, ведущие видят только там, где машина. Слово «стоянка» — только у логиста, наши — «парковки».
 * Машины на ней — задачи следующего отрезка, что отсюда ещё не забрали (`from_depot_id`, `waiting`).
 */
#[Fillable(['logist_id', 'name', 'settlement_id', 'address', 'contact_name', 'phone', 'note', 'archived_at'])]
class Depot extends Model
{
    protected $table = 'logistics_depots';

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function logist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logist_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    /** Задачи, что везут сюда или отсюда. */
    public function departures(): HasMany
    {
        return $this->hasMany(Transport::class, 'from_depot_id');
    }

    public function scopeOf(Builder $q, User $logist): Builder
    {
        return $q->where('logist_id', $logist->id);
    }

    /** Машины, что стоят здесь сейчас: отрезок отсюда открыт и ещё не забран. */
    public function scopeWithWaiting(Builder $q): Builder
    {
        return $q->withCount(['departures as waiting_count' => fn ($d) => $d->whereIn('state', [TransportState::New, TransportState::Logist, TransportState::Assigned, TransportState::Agreed])]);
    }

    /** «Стоянка Киров, ул. Ленина 5» — одной фразой для «Куда везти», строки и текста исполнителю. */
    public function label(): string
    {
        $city = $this->settlement?->name;

        return 'Стоянка '.$this->name.(($tail = implode(', ', array_filter([$city && ! str_contains($this->name, $city) ? $city : null, $this->address]))) ? ', '.$tail : '');
    }
}
