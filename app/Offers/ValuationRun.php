<?php

namespace App\Offers;

use App\Support\FastAttributes;
use App\Users\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одно сохранение «Оценить из текста» или бота оценок (17.11.2026, владелец: «всё, что через это окно проставляют, — в историю:
 * лого вендора, сколько машин, оценочные или закупочные, с наценкой или без, дата и время»). Пишет `ApplyValuations` — только
 * когда что-то проставили или завели. items — ТС: offer_id, number, ref, vendor_id, amount; vendors — vendor_id → сколько ТС.
 */
class ValuationRun extends Model
{
    use FastAttributes;

    public const UPDATED_AT = null;

    public const SOURCES = ['text' => 'текст', 'file' => 'Excel', 'photo' => 'фото', 'bot' => 'бот'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['markup' => 'bool', 'vendors' => 'array', 'items' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param list<array{offer_id: int, number: int, ref: string, vendor_id: ?int, amount: int}> $items */
    public static function record(User $user, string $source, bool $markup, array $items): ?self
    {
        $items = array_values(collect($items)->filter(fn ($i) => ! empty($i['offer_id']))->unique('offer_id')->all());
        if (! $items) {
            return null;
        }
        $vendors = collect($items)->countBy(fn ($i) => (string) ($i['vendor_id'] ?? 0))->sortDesc()->all();

        return self::create(['user_id' => $user->id, 'source' => isset(self::SOURCES[$source]) ? $source : 'text', 'markup' => $markup,
            'vendors' => $vendors, 'cars' => count($items), 'items' => $items]);
    }
}
