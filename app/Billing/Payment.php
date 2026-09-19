<?php

namespace App\Billing;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** Оплата по счёту: частями, откуда пришла, номер платёжки и её скан (`slip`); отменённая остаётся с `voided_at`. */
#[Fillable(['invoice_id', 'party_id', 'amount', 'paid_at', 'source', 'ref', 'note', 'voided_at', 'created_by'])]
class Payment extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'billing_payments';

    protected function casts(): array
    {
        return ['amount' => 'float', 'paid_at' => 'date', 'source' => PaymentSource::class, 'voided_at' => 'datetime'];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('slip')->useDisk('private')->singleFile();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function slip(): ?Media
    {
        return $this->getFirstMedia('slip');
    }
}
