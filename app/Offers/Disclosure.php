<?php

namespace App\Offers;

use App\Support\FastAttributes;
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

    /** Документы, что ещё лежат у машины. @return Collection<int, Media> */
    public function files(): Collection
    {
        $ids = array_filter(array_map(fn ($i) => ($i['kind'] ?? null) === 'file' ? (int) ($i['media'] ?? 0) : 0, $this->items ?? []));

        return $ids ? Media::whereIn('id', $ids)->get() : collect();
    }

    /** Строкой для истории и CRM — то, что видит менеджер: «+7 999 123-45-67, Иван, паспорт.pdf». */
    public function summary(): string
    {
        return implode(', ', array_map(fn ($i) => Str::limit((string) $i['value'], 60), $this->items ?? []));
    }
}
