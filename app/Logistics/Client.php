<?php

namespace App\Logistics;

use App\Support\FastAttributes;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Заказчик логиста (18.10.2026, владелец: «удобно заводить заказчиков»): кто заказывает Татьяне вывоз помимо XCar —
 * организация или человек, контакт, телефон, адрес, откуда обычно забирать. Только её (`logist_id`): ни XCar, ни другие
 * логисты их не видят. Долг — заявки, привезённые и не оплаченные (`client_paid_at`).
 */
#[Fillable(['logist_id', 'name', 'kind', 'contact_name', 'phone', 'email', 'inn', 'address', 'note', 'archived_at'])]
class Client extends Model
{
    use FastAttributes;

    protected $table = 'logistics_clients';

    public const KINDS = ['company' => 'Организация', 'person' => 'Человек'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function logist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logist_id');
    }

    public function transports(): HasMany
    {
        return $this->hasMany(Transport::class);
    }

    /** Заказчики этого логиста. */
    public function scopeOf(Builder $q, User $logist): Builder
    {
        return $q->where('logist_id', $logist->id);
    }

    /** Должен: привезли, цена есть, оплаты нет. */
    public static function owedSql(): string
    {
        return "transports.state = 'delivered' and transports.client_price is not null and transports.client_paid_at is null";
    }
}
