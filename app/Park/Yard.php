<?php

namespace App\Park;

use App\Cars\Settlement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'address', 'settlement_id', 'capacity', 'is_active', 'notes', 'rows'])]
class Yard extends Model
{
    protected $table = 'park_yards';

    protected function casts(): array
    {
        return ['is_active' => 'bool', 'capacity' => 'int', 'rows' => 'array'];
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

    /** Все места по рядам: «A-1»…«A-20». Без рядов — пусто, место пишется свободным текстом. @return list<string> */
    public function spots(): array
    {
        $spots = [];
        foreach ($this->rows ?? [] as $row) {
            $name = mb_strtoupper(trim((string) ($row['name'] ?? '')));
            $n = (int) ($row['n'] ?? 0);
            for ($i = 1; $i <= $n; $i++) {
                $spots[] = $name === '' ? (string) $i : $name.'-'.$i;
            }
        }

        return $spots;
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

    /** «Ряд A: 20» строками формы → jsonb. */
    public static function parseRows(?string $raw): array
    {
        $rows = [];
        foreach (preg_split('/[\n,;]+/u', (string) $raw) ?: [] as $line) {
            if (preg_match('/^\s*([^:\s]*)\s*[:\s]\s*(\d{1,3})\s*$/u', $line, $m)) {
                $rows[] = ['name' => mb_strtoupper($m[1]), 'n' => (int) $m[2]];
            } elseif (preg_match('/^\s*(\d{1,3})\s*$/u', $line, $m)) {
                $rows[] = ['name' => '', 'n' => (int) $m[1]];
            }
        }

        return $rows;
    }
}
