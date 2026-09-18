<?php

namespace App\Park;

use App\Cars\DamageZone;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Осмотр — при приёме, выдаче, погрузке: показания, комплектность, что требует ремонта, повреждения по акту и не по акту. */
#[Fillable(['vehicle_id', 'request_id', 'kind', 'at', 'user_id', 'mileage', 'fuel', 'keys_count', 'docs', 'equipment', 'repair', 'damage_zones', 'damage_note', 'transit_damage', 'missing_parts', 'replaced_units', 'signer_name'])]
class Inspection extends Model
{
    protected $table = 'park_inspections';

    public const DOCS = ['pts' => 'ПТС', 'sts' => 'СТС', 'epts' => 'ЭПТС', 'service_book' => 'Сервисная книжка'];

    public const EQUIPMENT = ['spare' => 'Запаска', 'jack' => 'Домкрат', 'radio' => 'Магнитола', 'mats' => 'Коврики', 'child_seat' => 'Детское кресло', 'tools' => 'Инструмент'];

    public const REPAIR = ['body' => 'Кузов', 'engine' => 'Двигатель', 'chassis' => 'Ходовая'];

    protected function casts(): array
    {
        return ['kind' => InspectionKind::class, 'at' => 'datetime', 'docs' => 'array', 'equipment' => 'array', 'repair' => 'array', 'damage_zones' => 'array'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<string> */
    public function damages(): array
    {
        return array_values(array_filter(array_map(fn ($z) => DamageZone::tryFrom((string) $z)?->label(), $this->damage_zones ?? [])));
    }

    /** «3/8» бака словами акта. */
    public function fuelLabel(): ?string
    {
        return $this->fuel === null ? null : match (true) {
            $this->fuel <= 0 => 'пустой',
            $this->fuel >= 8 => 'полный',
            default => $this->fuel.'/8',
        };
    }

    /** @return array<string, ?bool> кузов / двигатель / ходовая */
    public function repairMap(): array
    {
        return array_map(fn ($k) => isset($this->repair[$k]) ? (bool) $this->repair[$k] : null, array_combine(array_keys(self::REPAIR), array_keys(self::REPAIR)));
    }
}
