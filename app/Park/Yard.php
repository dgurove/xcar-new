<?php

namespace App\Park;

use App\Cars\Settlement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'address', 'settlement_id', 'capacity', 'is_active', 'notes'])]
class Yard extends Model
{
    protected $table = 'park_yards';

    protected function casts(): array
    {
        return ['is_active' => 'bool', 'capacity' => 'int'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'yard_id');
    }

    public function storedVehicles(): HasMany
    {
        return $this->vehicles()->where('state', VehicleState::Stored);
    }
}
