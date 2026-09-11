<?php

namespace App\Purchases;

use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['number', 'title', 'supplier', 'state', 'offers_close_at', 'source_file', 'imported_at', 'imported_by'])]
class Purchase extends Model
{
    protected function casts(): array
    {
        return ['state' => PurchaseState::class, 'offers_close_at' => 'datetime', 'imported_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function cars(): HasMany
    {
        return $this->hasMany(Car::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /** Наружу — номер и месяц: по названию с именем лизинговой компании покупатель уйдёт искать те же машины у неё. */
    public function publicTitle(): string
    {
        return "Закупка № {$this->number}, ".mb_strtolower($this->created_at->translatedFormat('F Y'));
    }

    public function acceptsOffers(): bool
    {
        return $this->state === PurchaseState::Open && (! $this->offers_close_at || $this->offers_close_at->isFuture());
    }

    public static function nextNumber(): int
    {
        return (int) (self::max('number') ?? 0) + 1;
    }
}
