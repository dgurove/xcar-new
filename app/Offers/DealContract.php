<?php

namespace App\Offers;

use App\Billing\Party;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Договор купли-продажи ТС страхователя с покупателем менеджера (сделка «страхователю по ДКП», 05.10.2026). Продавец —
 * всегда сам собственник по СТС: его паспорт сотрудник переносит со сканов страховой (`seller`, физлицо). Покупатель —
 * из «Покупателей» менеджера, паспорт — у его контрагента (`users.party_id`). Марка, модель, VIN, год и цвет — из
 * предложения; номера документов ТС и номера агрегатов — тут. Не хватает данных — в договоре прочерки (`missing`).
 */
#[Fillable(['deal_id', 'seller_party_id', 'buyer_user_id', 'price', 'city', 'signed_at', 'plate', 'sts', 'pts', 'pts_issued', 'body_no', 'chassis_no', 'engine_no', 'ready_at'])]
class DealContract extends Model
{
    public const VEHICLE = ['plate' => 'Госномер', 'sts' => 'СТС', 'pts' => 'ПТС', 'pts_issued' => 'ПТС выдан', 'body_no' => 'Кузов №', 'chassis_no' => 'Шасси (рама) №', 'engine_no' => 'Двигатель №'];

    protected function casts(): array
    {
        return ['price' => 'int', 'signed_at' => 'date', 'ready_at' => 'datetime'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'seller_party_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    /** Договор сделки: строка заводится при первом обращении, цена — что получает собственник (`Deal::ownerPrice`). */
    public static function for(Deal $deal): self
    {
        return $deal->contract ?? tap(self::firstOrCreate(['deal_id' => $deal->id], ['price' => $deal->ownerPrice()]), fn ($c) => $deal->setRelation('contract', $c));
    }

    public function buyerParty(): ?Party
    {
        return $this->buyer?->party;
    }

    /** Чего не хватает договору — словами для карточки: «данные продавца», «покупатель», «паспорт покупателя». @return list<string> */
    public function missing(): array
    {
        return array_values(array_filter([
            $this->seller?->hasPassport() ? null : 'данные продавца',
            ! $this->plate && ! $this->sts ? 'документы ТС' : null,
            $this->buyer ? ($this->buyerParty()?->hasPassport() ? null : 'паспорт покупателя') : 'покупатель',
            $this->price ? null : 'цена',
        ]));
    }

    public function isReady(): bool
    {
        return $this->missing() === [];
    }
}
