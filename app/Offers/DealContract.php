<?php

namespace App\Offers;

use App\Billing\Party;
use App\Billing\Seller;
use App\Cars\Car;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Договор купли-продажи ТС (05.10.2026; по шаблонам владельца с 19.10.2026). Продавец по схеме: у ДКП — сам
 * собственник по СТС, его паспорт сотрудник переносит со сканов страховой (`seller`, физлицо); у ПРАЙМ и Р.О.С. — они
 * сами (`Party::seller`). Покупатель — из «Покупателей» менеджера (или сам менеджер), физлицо с паспортом или
 * организация, данные — у его контрагента (`users.party_id`). У ПРАЙМ ещё `payer` — кто платит по счёту: покупатель или
 * менеджер за вычетом вознаграждения. Всё про машину (марка, VIN, госномер, СТС, (Э)ПТС, кузов, шасси, ключи) — у
 * машины (`Cars\Car`), тут только цена, город, дата и номер. Вид документа — `form()`: `owner` — физлицо физлицу
 * (Т-Страхование), `prime` / `ros` — ДКП и акт от нашего юрлица; номер у юрлица свой за год (`NumberContract`).
 */
#[Fillable(['deal_id', 'seller_party_id', 'buyer_user_id', 'payer', 'price', 'city', 'signed_at', 'ready_at', 'issuer', 'number', 'year'])]
class DealContract extends Model
{
    /** Документы ТС для договора — поля машины, правит сотрудник в карточке ДКП. */
    public const VEHICLE = ['plate' => 'Госномер', 'sts' => 'СТС', 'pts' => 'ПТС или ЭПТС', 'body_no' => 'Кузов №', 'chassis_no' => 'Шасси (рама) №'];

    protected function casts(): array
    {
        return ['price' => 'int', 'signed_at' => 'date', 'ready_at' => 'datetime', 'issuer' => Seller::class, 'number' => 'int', 'year' => 'int'];
    }

    /** Вид документа: физлицо физлицу (ДКП собственника) или ДКП с актом от нашего юрлица. */
    public function form(): string
    {
        return $this->deal->isDkp() ? 'owner' : $this->deal->seller()->value;
    }

    /** Номер для договора и акта: «ПР-15/2026»; номера ещё нет (договор не готов) — null. */
    public function numberLabel(): ?string
    {
        if (! $this->number || ! $this->issuer) {
            return null;
        }

        return ($this->issuer->config()['contract_prefix'] ?? $this->issuer->short()).'-'.$this->number.'/'.$this->year;
    }

    /** Машина договора — одна запись на все дорожки. */
    public function car(): ?Car
    {
        return $this->deal->offer->car;
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
        if ($deal->contract) {
            return $deal->contract;
        }
        // Гаражная «платит менеджер» и сделка супер-покупателя — покупатель он сам, он же платит.
        $self = $deal->isGarageManager() || $deal->isDirect();
        $defaults = ['price' => self::priceFor($deal)] + ($self ? ['buyer_user_id' => $deal->buyer_id, 'payer' => 'manager'] : []);

        return tap(self::firstOrCreate(['deal_id' => $deal->id], $defaults), fn ($c) => $deal->setRelation('contract', $c));
    }

    /** Цена договора по деньгам сделки: собственнику (ДКП), закупочная (гаражная), иначе цена подтверждения (у гаражной
     *  Совкомбанка — цена продажи, которую назвал менеджер; платит покупатель — минус вознаграждение, как в счёте,
     *  27.10.2026, `Deal::buyerPaysReward`). */
    public static function priceFor(Deal $deal): ?int
    {
        return match (true) {
            $deal->isDkp() => $deal->ownerPrice(),
            $deal->isGarage() && ! $deal->namesBuyer() => $deal->cost,
            $deal->buyerPaysReward() && $deal->amount !== null => max(0, (int) $deal->amount - (int) $deal->commission),
            default => $deal->amount,
        };
    }

    /** Продавец: у ДКП — собственник по СТС (вносит сотрудник), у ПРАЙМ — сам ПРАЙМ. */
    public function sellerParty(): ?Party
    {
        return $this->deal->isDkp() ? $this->seller : Party::seller($this->deal->seller());
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
            // ДКП от Р.О.С. — продавец Р.О.С.: без его реквизитов (пришлёт владелец) договор не печатаем с прочерками.
            $this->deal->seller() === Seller::Ros && ! Party::seller(Seller::Ros)->readyForContract() ? 'данные продавца' : null,
            // (Э)ПТС стоит в обоих видах договора: у собственника — ещё и основание права собственности.
            ! $this->car()?->pts ? '(Э)ПТС' : null,
            $this->buyer ? ($this->buyerParty()?->readyForContract() ? null : 'данные покупателя') : 'покупатель',
            // Скан паспорта покупателя-физлица (11.10.2026): без него договор не печатается.
            $this->buyer && $this->buyerParty()?->readyForContract() && ! $this->buyerParty()->readyAsBuyer() ? 'скан паспорта покупателя' : null,
            $this->price ? null : 'цена',
        ]));
    }

    public function isReady(): bool
    {
        return $this->missing() === [];
    }
}
