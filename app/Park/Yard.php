<?php

namespace App\Park;

use App\Cars\Settlement;
use App\Support\FastAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'address', 'settlement_id', 'capacity', 'is_active', 'notes', 'rows'])]
class Yard extends Model
{
    use FastAttributes;

    protected $table = 'park_yards';

    /** Управляющий с городом видит только парковки своего города (`City`); перевод в другой город берёт их без области. */
    protected static function booted(): void
    {
        static::addGlobalScope(City::SCOPE, fn (Builder $q) => ($city = City::viewing()) ? $q->where($q->getModel()->qualifyColumn('settlement_id'), $city) : null);
    }

    protected function casts(): array
    {
        return ['is_active' => 'bool', 'capacity' => 'int', 'rows' => 'array'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    /** Адрес для людей: город и улица; без адреса — название парковки. */
    public function fullAddress(): string
    {
        return trim(($this->settlement?->title() ?? '').', '.($this->address ?? ''), ', ') ?: $this->name;
    }

    /** Ссылка на карту — покупателю, который едет забирать ТС. */
    public function mapUrl(): string
    {
        return 'https://yandex.ru/maps/?text='.rawurlencode($this->fullAddress());
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'yard_id');
    }

    public function storedVehicles(): HasMany
    {
        return $this->vehicles()->where('state', VehicleState::Stored);
    }

    /** Ряды схемы в полном виде (`Plan::rows`): координаты, размер мест, убранные номера. @return list<array> */
    public function plan(): array
    {
        return Plan::rows($this->rows);
    }

    /** Ревизия схемы: редактор сохраняет поверх той, что открыл, иначе — «Схему поменяли». */
    public function planRev(): string
    {
        return substr(md5(json_encode($this->plan())), 0, 12);
    }

    /** Все места по рядам: «A-1»…«A-20», убранные — мимо. Без рядов — пусто, место пишется свободным текстом. @return list<string> */
    public function spots(): array
    {
        return array_merge([], ...array_map(fn (array $row) => array_values(Plan::codes($row)), $this->plan()));
    }

    /** @return array<string, int> место → id ТС, которая на нём стоит */
    public function occupied(): array
    {
        return $this->storedVehicles()->whereNotNull('spot')->pluck('id', 'spot')->all();
    }

    /** @return list<string> */
    public function freeSpots(): array
    {
        return array_values(array_diff($this->spots(), array_keys($this->occupied())));
    }
}
