<?php

namespace App\Offers;

use App\Media\ForManagers;
use App\Support\FastAttributes;
use App\Support\Phone;
use App\Support\VendorNames;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Что админ показал менеджеру сделки (03.11.2026, владелец: «админ отмечает, что показать, жмёт „Показать менеджеру“ — и только
 * тогда менеджеру уведомление и ТОЛЬКО то, что выбрано»). Из писем страховой менеджер видит только это — блок «От XCar» в
 * сделке и гараже, получение автомобиля (`Handover`), `DealInfoNotice`. Пишет `Actions\Disclose`, строки только дописываются.
 *
 * `items`: `[{kind: phone|name|address|text|file, value, media?}]` — значения уже правленные админом, у файла — имя и id.
 */
#[Fillable(['deal_id', 'items', 'message_ids', 'created_by'])]
class Disclosure extends Model
{
    use FastAttributes;

    protected $table = 'deal_disclosures';

    protected function casts(): array
    {
        return ['items' => 'array', 'message_ids' => 'array'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return list<string> значения пунктов этого вида */
    public function values(string $kind): array
    {
        return array_values(array_map(fn ($i) => (string) $i['value'], array_filter($this->items ?? [], fn ($i) => ($i['kind'] ?? null) === $kind)));
    }

    /** Документы, что ещё лежат у машины и всё ещё открыты менеджеру (сняли «Видно менеджеру» — из блока ушли). @return Collection<int, Media> */
    public function files(): Collection
    {
        $ids = array_filter(array_map(fn ($i) => ($i['kind'] ?? null) === 'file' ? (int) ($i['media'] ?? 0) : 0, $this->items ?? []));

        return $ids ? Media::whereIn('id', $ids)->get()->filter(fn (Media $m) => ForManagers::is($m))->values() : collect();
    }

    /**
     * Пункты, которых нет в карточке «Получение автомобиля» рядом (`Handover` берёт оттуда контакт): тот же телефон, имя и адрес
     * второй раз под ним не пишутся. @return list<array{kind: string, value: string, media?: int}>
     */
    public function besides(?Handover $handover): array
    {
        if (! $handover?->shows()) {
            return $this->items ?? [];
        }
        $phones = array_filter([$handover->phone, $handover->phone2]);

        return array_values(array_filter($this->items ?? [], fn ($i) => match ($i['kind'] ?? null) {
            'phone' => ! in_array(Phone::normalize($i['value']), $phones, true),
            'name' => $i['value'] !== $handover->name,
            'address' => VendorNames::strip($i['value']) !== $handover->address,
            default => true,
        }));
    }

    /** Строкой для истории и CRM — то, что видит менеджер: «+7 999 123-45-67, Иван, паспорт.pdf». */
    public function summary(): string
    {
        return implode(', ', array_map(fn ($i) => Str::limit((string) $i['value'], 60), $this->items ?? []));
    }
}
