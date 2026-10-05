<?php

namespace App\Offers;

use App\Billing\Party;
use App\Billing\Seller;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Договор купли-продажи ТС (05.10.2026). Продавец по схеме: у ДКП — сам собственник по СТС, его паспорт сотрудник
 * переносит со сканов страховой (`seller`, физлицо); у ПРАЙМ — ПРАЙМ. Покупатель — из «Покупателей» менеджера (или сам
 * менеджер), физлицо с паспортом или организация, данные — у его контрагента (`users.party_id`). У ПРАЙМ ещё `payer` —
 * кто платит по счёту: покупатель или менеджер за вычетом вознаграждения. Марка, модель, VIN, год и цвет — из
 * предложения; номера документов ТС и номера агрегатов — тут. Не хватает данных — в договоре прочерки (`missing`).
 */
#[Fillable(['deal_id', 'seller_party_id', 'buyer_user_id', 'payer', 'price', 'city', 'signed_at', 'plate', 'sts', 'pts', 'pts_issued', 'body_no', 'chassis_no', 'engine_no', 'ready_at'])]
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

    /**
     * Договор сделки: строка заводится при первом обращении. Цена — что получает собственник (ДКП), цена подтверждения
     * (ПРАЙМ) или закупочная (гаражная «платит менеджер» у ПРАЙМ: покупатель — сам менеджер).
     */
    public static function for(Deal $deal): self
    {
        $price = match (true) {
            $deal->isDkp() => $deal->ownerPrice(),
            $deal->isGarage() => $deal->cost,
            default => $deal->amount,
        };
        $defaults = ['price' => $price] + ($deal->isGarageManager() ? ['buyer_user_id' => $deal->buyer_id, 'payer' => 'manager'] : []);

        return $deal->contract ?? tap(self::firstOrCreate(['deal_id' => $deal->id], $defaults), fn ($c) => $deal->setRelation('contract', $c));
    }

    /** Продавец: у ДКП — собственник по СТС (вносит сотрудник), у ПРАЙМ — сам ПРАЙМ. */
    public function sellerParty(): ?Party
    {
        return $this->deal->isDkp() ? $this->seller : Party::seller(Seller::Prime);
    }

    public function buyerParty(): ?Party
    {
        return $this->buyer?->party;
    }

    /** По счёту ПРАЙМ платит сам менеджер (за вычетом своего вознаграждения) — он же покупатель или нет. */
    public function managerPays(): bool
    {
        return $this->payer === 'manager' || ($this->buyer_user_id !== null && $this->buyer_user_id === $this->deal->buyer_id);
    }

    /** Чего не хватает договору — словами для карточки: «данные продавца», «покупатель», «паспорт покупателя». @return list<string> */
    public function missing(): array
    {
        return array_values(array_filter([
            $this->deal->isDkp() && ! $this->seller?->readyForContract() ? 'данные продавца' : null,
            ! $this->plate && ! $this->sts ? 'документы ТС' : null,
            $this->buyer ? ($this->buyerParty()?->readyForContract() ? null : 'данные покупателя') : 'покупатель',
            $this->price ? null : 'цена',
        ]));
    }

    public function isReady(): bool
    {
        return $this->missing() === [];
    }
}
