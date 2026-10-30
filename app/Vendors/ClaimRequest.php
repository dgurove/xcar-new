<?php

namespace App\Vendors;

use App\Offers\Offer;
use App\Support\Ago;
use App\Support\FastAttributes;
use App\Support\Plural;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Запрос номеров убытка вендору (15–16.10.2026, владелец каждый день шлёт Т-Страхованию номера, по которым есть интерес
 * или подтверждение): кто, когда и снимок номеров (`refs`) — его копирует история, даже если номер потом вернули в
 * «К запросу». У отправленного предложения — `offers.claim_request_id`.
 */
#[Fillable(['vendor_id', 'user_id', 'refs'])]
class ClaimRequest extends Model
{
    use FastAttributes;

    /** Был отклик: подтверждение (любое, и отклонённое) или интерес. */
    public const REPLY_SQL = '(exists (select 1 from bids where bids.offer_id = offers.id) or exists (select 1 from interests where interests.offer_id = offers.id))';

    /** Вендор сам прислал предложение письмом (`Offer::LETTER_SQL`). Такие не запрашиваем. */
    public const LETTER_SQL = Offer::LETTER_SQL;

    protected function casts(): array
    {
        return ['refs' => 'array'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * «К запросу» вендора: был отклик, машина ещё продаётся (черновик, галерея, приём — без сделки, гаража, выдачи и
     * архива), вендор её письмом не присылал, номер есть и его не отправляли.
     */
    public static function pendingFor(Vendor $vendor): Builder
    {
        return Offer::where('offers.vendor_id', $vendor->id)->whereNull('offers.claim_request_id')
            ->whereIn('offers.state', ['draft', 'gallery', 'open'])
            ->whereRaw(self::REPLY_SQL)->whereRaw('not '.self::LETTER_SQL)
            ->whereCar('claim_ref', '!=', '');
    }

    /** Номера столбиком — в буфер. */
    public function text(): string
    {
        return implode("\n", $this->refs);
    }

    /** Сообщение в Telegram: вендор, дата, число и номера (`<code>` — копируется касанием). */
    public function telegram(): string
    {
        $n = count($this->refs);

        return e($this->vendor->name).', запрос '.Ago::day($this->created_at).': '.$n.' '.Plural::of($n, ['номер', 'номера', 'номеров'])
            ."\n\n".implode("\n", array_map(fn ($r) => '<code>'.e($r).'</code>', $this->refs));
    }
}
