<?php

namespace App\Billing;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Оплата по счёту: частями, откуда пришла, номер платёжки и её скан (`slip`); отменённая остаётся с `voided_at`.
 * Заявленная менеджером (`claimed`) в `paid` не входит, пока сотрудник не подтвердит; не поступившая — `rejected`.
 */
#[Fillable(['invoice_id', 'party_id', 'amount', 'paid_at', 'source', 'ref', 'note', 'state', 'reject_reason', 'decided_at', 'voided_at', 'created_by'])]
class Payment extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'billing_payments';

    protected function casts(): array
    {
        return ['amount' => 'float', 'paid_at' => 'date', 'source' => PaymentSource::class, 'state' => PaymentState::class, 'decided_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('slip')->useDisk('private')->singleFile();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Номер платёжки без «п/п» и «№», которые вписывают вместе с ним: «п/п п/п 15» на экране было. */
    public function refNumber(): ?string
    {
        $ref = trim((string) preg_replace('/^\s*(п\s*\/?\s*п\.?|№)\s*№?\s*/ui', '', (string) $this->ref));

        return $ref !== '' ? $ref : null;
    }

    public function slip(): ?Media
    {
        return $this->getFirstMedia('slip');
    }
}
