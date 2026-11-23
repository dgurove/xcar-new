<?php

namespace App\Offers;

use App\Support\FastAttributes;
use App\Users\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Кто видит предложение и с какого момента; notified_at — уведомление о нём ушло. Считает SyncViewers. */
class OfferViewer extends Model
{
    use FastAttributes;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $guarded = [];

    /**
     * Строка — пара (предложение, человек): своего ключа у таблицы нет. Без этого `save()` и `delete()` модели писали по пустому ключу
     * и меняли все строки предложения (23.11.2026: `$offer->viewers()->updateOrCreate([...])` сдвинул волну всем менеджерам).
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query->where('offer_id', $this->getOriginal('offer_id') ?? $this->getAttribute('offer_id'))
            ->where('user_id', $this->getOriginal('user_id') ?? $this->getAttribute('user_id'));
    }

    protected function casts(): array
    {
        return ['opens_at' => 'datetime', 'notified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
