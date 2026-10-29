<?php

namespace App\Garage;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Offers\Binding;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferEvent;
use App\Offers\OfferEventType;
use App\Support\Surface;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Машина в гараже: предложение, выведенное из продажи менеджеру на ремонт.
 * Всё про саму ТС — в предложении; здесь кому отдали, за сколько, расходы и чем кончилось.
 */
#[Fillable(['offer_id', 'deal_id', 'manager_id', 'state', 'stage_at', 'taken_at', 'sold_at', 'sold_price', 'buyer_name', 'buyer_phone', 'commission', 'settled_at', 'invoice_id', 'invoice_to', 'payout_invoice_id', 'created_by', 'buyer_price'])]
class Car extends Model
{
    protected $table = 'garage_cars';

    protected static function booted(): void
    {
        // Машина в гараже — покупатель есть: тревога «нет покупателя» гаснет; убрали из гаража — может загореться.
        static::created(fn (self $car) => Binding::touch(Offer::find($car->offer_id)));
        static::deleted(fn (self $car) => Binding::touch(Offer::find($car->offer_id)));
    }

    protected function casts(): array
    {
        return [
            'state' => CarState::class,
            'taken_at' => 'datetime', 'stage_at' => 'datetime', 'sold_at' => 'datetime', 'settled_at' => 'datetime',
            'sold_price' => 'int', 'commission' => 'int', 'buyer_price' => 'int',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** Гаражная сделка машины (подтверждение «В гараж» или «Отдать в гараж»): пока идёт, маршрут со страховой — по ней. */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** Счёт по итогу продажи: менеджеру (`invoice_to = manager`) или его покупателю; нет — расчёт ещё не выставлен. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Кто завёл машину в гараж: от его имени уходят документы, когда оплата пришла сама. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Выплата менеджеру его расходов и вознаграждения, когда за машину заплатил покупатель. */
    public function payoutInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'payout_invoice_id');
    }

    /** Расходы, свежие первыми: их и читают сверху вниз. */
    public function costs(): HasMany
    {
        return $this->hasMany(Cost::class, 'garage_car_id')->orderByDesc('spent_at')->orderByDesc('id');
    }

    /** Машины одного человека: менеджеру — его, взятые под себя видит только сотрудник; админ-менеджер на сайте — менеджер. */
    public function scopeOf(Builder $q, User $user): Builder
    {
        return $user->isAdmin() && ! $user->isManager() ? $q : $q->where('manager_id', $user->id);
    }

    /** Потрачено: все расходы или только чьи-то. */
    public function spent(?Payer $payer = null): float
    {
        $costs = $this->relationLoaded('costs') ? $this->costs : $this->costs()->get();

        return round($costs->when($payer, fn ($c) => $c->where('payer', $payer))->sum('amount'), 2);
    }

    /**
     * Во что машина нам встала: отдали за (закупочная), пока платили мы; платил менеджер — строкой расхода `Cost::SUPPLIER`.
     * Закупочная одна — у сделки (14.10.2026: копия в строке гаража расходилась с ней после нового файла Carcade; своей
     * колонки `cost` у гаража нет с 29.10.2026 — машина без сделки в гараж не попадает).
     */
    public function base(): ?int
    {
        // Платим поставщику мы — по нашей закупке у него (`supplierCost`, 15.10.2026), менеджеру она не видна.
        return $this->deal?->garage_payer === GaragePayer::Us ? $this->deal->supplierCost() : null;
    }

    /**
     * Закупочная, по которой машина ушла в гараж (13.10.2026, владелец: «должно быть видно» — менеджеру и
     * админу на сайте и в CRM). Отдали мы — `cost`, иначе — сделки (платит менеджер, Совкомбанк). Только для показа:
     * в расклад она входит через `base()` или строку оплаты поставщику.
     */
    public function price(): ?int
    {
        // Платим поставщику мы — наша закупка у него; платит менеджер — закреплённая цена сделки, та же, что в строке
        // «Оплата поставщику» (15.10.2026).
        $price = $this->deal?->isGarageUs() ? $this->deal->supplierCost() : $this->deal?->cost;

        return $price ? (int) $price : null;
    }

    /**
     * Закупочная менеджеру (16.10.2026, владелец: «при подтверждении закрепляется закупочная на тот момент — её и
     * показывать»): закреплённая сделкой (`deals.cost` из `bids.cost`), кто бы ни платил поставщику; новая закупка у
     * поставщика (`supplier_cost`) — только сотруднику, через `price()`.
     */
    public function managerPrice(): ?int
    {
        $price = $this->deal?->cost;

        return $price ? (int) $price : null;
    }

    /**
     * Цена тому, кто смотрит: сотруднику — закупочная `price()`, менеджеру — закреплённая `managerPrice()`, покупателю с
     * гаражом — его цена `buyer_price` (20.10.2026, владелец: «покупатель нигде ни в коем случае не должен видеть
     * закупочные цены»).
     */
    public function priceFor(bool $staff): ?int
    {
        return match (true) {
            $staff => $this->price(),
            $this->forBuyer() => $this->hidesBuyerPrice() ? $this->offer?->asking_price : $this->buyer_price,
            default => $this->managerPrice(),
        };
    }

    /** Счёт покупателю с гаражом на его цену — счёт сделки (`SyncDealInvoices`), последний живой; у остальных — нет. */
    public function holderInvoice(): ?Invoice
    {
        return $this->forBuyer() && $this->deal_id
            ? Invoice::where('deal_id', $this->deal_id)->where('direction', 'issued')->where('kind', ChargeKind::Sale)
                ->where('state', '!=', InvoiceState::Void)->latest('id')->first()
            : null;
    }

    /**
     * Покупатель уже платил или сообщил об оплате своего счёта: цену не правим и машину у него не забираем, пока деньги не
     * вернули (зачёт и аннулированные — не в счёт).
     */
    public function buyerHasPaid(): bool
    {
        $invoice = $this->holderInvoice()?->load(['payments', 'claims']);

        return $invoice !== null && ($invoice->claims->isNotEmpty() || $invoice->payments->contains(fn ($p) => ! $p->source->isSoft() && ! $p->voided_at));
    }

    /** Покупатель с гаражом оплатил свой счёт целиком: отметил «Продана» — и расчёт закрыт. */
    public function buyerPaid(): bool
    {
        return $this->forBuyer() && $this->holderInvoice()?->state === InvoiceState::Paid;
    }

    /** Подпись цены тому, кто смотрит: покупателю — «Цена», остальным — «Закупочная». */
    public function priceLabel(bool $staff): string
    {
        return match (true) {
            $staff || ! $this->forBuyer() => 'Закупочная',
            $this->hidesBuyerPrice() => 'Цена продажи',
            default => 'Цена',
        };
    }

    /** Покупателю с гаражом без галки «Показывать цену покупателя» — только цена продажи (`User::seesGaragePrice`). */
    public function hidesBuyerPrice(): bool
    {
        return $this->forBuyer() && ! $this->manager->seesGaragePrice();
    }

    /**
     * Машина у покупателя (или супер-покупателя) с гаражом по тумблеру: цена — `buyer_price` счётом ПРАЙМ, поставщику
     * платим мы, расчёта и вознаграждения нет; закупочной ему не видно нигде. Одна дверь для всех веток гаража.
     */
    public function forBuyer(): bool
    {
        return (bool) $this->manager?->holdsAsBuyer();
    }

    /** Расходы рядом с закупочной — без строки «Оплата поставщику»: это та же цена, второй раз её не пишем. */
    public function extraSpent(?Payer $payer = null): float
    {
        $costs = $this->relationLoaded('costs') ? $this->costs : $this->costs()->get();

        return round($costs->reject(fn ($c) => $c->isSupplier())->when($payer, fn ($c) => $c->where('payer', $payer))->sum('amount'), 2);
    }

    /** Вложено в машину: отдали за плюс все расходы; у машины покупателя — только наши (его расходы — его). */
    public function invested(): float
    {
        return round((float) $this->base() + ($this->forBuyer() ? $this->spent(Payer::Xcar) : $this->spent()), 2);
    }

    /** Прибыль: цена продажи минус вложенное; не продана — null. У машины покупателя — его цена минус вложенное нами. */
    public function profit(): ?float
    {
        $price = $this->forBuyer() ? $this->buyer_price : $this->sold_price;

        return $price === null ? null : round($price - $this->invested(), 2);
    }

    /** Сколько дней машина в гараже; проданная — по день продажи. */
    public function days(): int
    {
        return (int) $this->taken_at->copy()->startOfDay()->diffInDays(($this->sold_at ?? now())->copy()->startOfDay()) + 1;
    }

    /** Сколько дней машина на текущем этапе — его и показывает строка списка. */
    public function stageDays(): int
    {
        return (int) ($this->stage_at ?? $this->taken_at)->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1;
    }

    /** Расходы и итог заморожены: по машине выставлен счёт. Поправить — аннулировать счёт. */
    public function isFrozen(): bool
    {
        return $this->invoice_id !== null || $this->state === CarState::Settled;
    }

    /** Машина, по которой выставлен этот счёт или выплата: уведомления о гаражном счёте ведут к ней, а не в сделки. */
    public static function ofInvoice(Invoice $invoice): ?self
    {
        return $invoice->deal_id ? null : self::with(['offer', 'manager'])
            ->where(fn ($q) => $q->where('invoice_id', $invoice->id)->orWhere('payout_invoice_id', $invoice->id))->first();
    }

    /** Адрес машины в гараже для менеджера — из уведомлений, писем, Telegram: с хостом сайта, откуда бы ни открыли. */
    public function url(): string
    {
        return Surface::Site->url('/garage/cars/'.$this->offer->number);
    }

    /** Адрес для сотрудника: машину в гараже ведут в CRM — редактор предложения с карточкой «Гараж». */
    public function crmUrl(): string
    {
        return Surface::Crm->url('/offers/'.$this->offer->number.'#garage');
    }

    public function isSold(): bool
    {
        return $this->state === CarState::Sold || $this->state === CarState::Settled;
    }

    /** Машины у менеджера ещё нет: её везёт вывоз (`StartPrepOnArrival` переводит на подготовку). */
    public function isWaiting(): bool
    {
        return $this->state === CarState::Waiting;
    }

    /**
     * Сделка со страховой ещё идёт: машину могут уже готовить, но продавать — только после закрытия бумаг (06.10.2026).
     * Машины без сделки (отданы руками до 06.10.2026) — как закрытые.
     */
    public function dealOpen(): bool
    {
        return $this->deal_id !== null && $this->deal?->state === DealState::Active;
    }

    /**
     * Продавать рано: идёт сделка со страховой, и бумаги по ней ещё не закрыты. У Совкомбанка (`Deal::namesBuyer`, 10.10.2026)
     * не держит: машину забирают без оплаты, продают, и уже продажа выставляет счёт ПРАЙМ с договором.
     */
    public function papersPending(): bool
    {
        return $this->dealOpen() && ! $this->deal->namesBuyer();
    }

    /** Гаражная Совкомбанка: деньги идут сделкой (счёт ПРАЙМ покупателю), расчёта по машине нет. */
    public function settledByDeal(): bool
    {
        return (bool) $this->deal?->namesBuyer();
    }

    /** Поставщику платил сам менеджер: документы на нём, счёт по продаже — только ему. */
    public function managerPaidSupplier(): bool
    {
        return $this->deal_id !== null && $this->deal?->garage_payer === GaragePayer::Manager;
    }

    /** Покупатель заплатил, а выплаты менеджеру нет (аннулировали или оплата пришла без автора) — её заводят кнопкой. */
    public function awaitsPayout(): bool
    {
        return $this->state === CarState::Sold && $this->invoice_to === 'buyer' && $this->invoice?->state === InvoiceState::Paid
            && (! $this->payoutInvoice || $this->payoutInvoice->state === InvoiceState::Void);
    }

    /**
     * Перевести на этап: дни на этапе считаются от этого момента, шаг ложится в «Историю» машины — из неё же и путь
     * (`stages`; своей копии пути у гаража нет с 29.10.2026).
     */
    public function moveTo(CarState $state, array $attributes = [], ?User $by = null): void
    {
        $this->update(['state' => $state, 'stage_at' => now()] + $attributes);
        $this->log($by, ['do' => 'stage', 'state' => $state->value] + ($state === CarState::Sold && $this->sold_price ? ['price' => $this->sold_price] : []));
    }

    /** Этапы машины по порядку — строки «Истории» гаража `do: stage` (путь машины, `path`). */
    public function stages(): HasMany
    {
        return $this->hasMany(OfferEvent::class, 'offer_id', 'offer_id')->where('type', OfferEventType::Garage)
            ->whereRaw("payload->>'do' = 'stage'")->reorder()->orderBy('created_at')->orderBy('id');
    }

    /** Строка «Истории» предложения о гараже (`OfferEventType::Garage`). */
    public function log(?User $by, array $payload): void
    {
        $this->offer?->log(OfferEventType::Garage, $by, $payload);
    }

    /**
     * Путь машины по этапам набора (подготовка — дорожка «Гараж», продажа — хвост дорожки «Продажа»): пройденные с
     * датой, текущий, впереди. Записи истории со снятыми этапами («Доставка» до 06.10.2026) пропускаются.
     *
     * @param  list<CarState>  $states
     * @return list<array{state: CarState, at: ?Carbon, status: string}>
     */
    public function path(array $states): array
    {
        // Журнал — у предложения: машину отдавали в гараж и раньше (строку прошлого раза удалили) — её этапы не наши.
        $since = $this->created_at?->copy()->subSeconds(5);
        $history = $this->stages->filter(fn (OfferEvent $e) => ! $since || $e->created_at >= $since)
            ->map(fn (OfferEvent $e) => [CarState::tryFrom($e->payload['state'] ?? ''), $e->created_at])->filter(fn ($h) => $h[0]);
        $path = [];
        foreach ($states as $state) {
            $path[] = [
                'state' => $state,
                'at' => $history->last(fn ($h) => $h[0] === $state)[1] ?? null,
                'status' => match (true) {
                    $state === $this->state => $state === CarState::Settled ? 'done' : 'current',
                    $state->order() < $this->state->order() => 'done',
                    default => 'next',
                },
            ];
        }

        return $path;
    }
}
