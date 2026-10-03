<?php

namespace App\Garage;

use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Offers\Deal;
use App\Offers\Offer;
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
#[Fillable(['offer_id', 'deal_id', 'manager_id', 'state', 'stage_at', 'history', 'taken_at', 'cost', 'sold_at', 'sold_price', 'buyer_name', 'buyer_phone', 'commission', 'settled_at', 'invoice_id', 'invoice_to', 'payout_invoice_id', 'note', 'created_by'])]
class Car extends Model
{
    protected $table = 'garage_cars';

    protected function casts(): array
    {
        return [
            'state' => CarState::class,
            'history' => 'array',
            'taken_at' => 'datetime', 'stage_at' => 'datetime', 'sold_at' => 'datetime', 'settled_at' => 'datetime',
            'cost' => 'int', 'sold_price' => 'int', 'commission' => 'int',
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

    /** Сделка, которой машина пришла из подтверждения «В гараж»: пока ждёт страховую, маршрут идёт по ней. */
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

    /** Машины одного человека: менеджеру — его, взятые под себя видит только сотрудник. */
    public function scopeOf(Builder $q, User $user): Builder
    {
        return $user->isStaff() ? $q : $q->where('manager_id', $user->id);
    }

    /** Потрачено: все расходы или только чьи-то. */
    public function spent(?Payer $payer = null): float
    {
        $costs = $this->relationLoaded('costs') ? $this->costs : $this->costs()->get();

        return round($costs->when($payer, fn ($c) => $c->where('payer', $payer))->sum('amount'), 2);
    }

    /** Вложено в машину: отдали за плюс все расходы. */
    public function invested(): float
    {
        return round((float) $this->cost + $this->spent(), 2);
    }

    /** Прибыль: цена продажи минус вложенное; не продана — null. */
    public function profit(): ?float
    {
        return $this->sold_price === null ? null : round($this->sold_price - $this->invested(), 2);
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
        return $invoice->deal_id ? null : self::with(['offer.brand', 'offer.model', 'manager'])
            ->where(fn ($q) => $q->where('invoice_id', $invoice->id)->orWhere('payout_invoice_id', $invoice->id))->first();
    }

    /** Адрес машины в гараже — из уведомлений, писем, Telegram и CRM: с хостом сайта, откуда бы ни открыли. */
    public function url(): string
    {
        return Surface::Site->url('/garage/cars/'.$this->offer->number);
    }

    public function isSold(): bool
    {
        return $this->state === CarState::Sold || $this->state === CarState::Settled;
    }

    /** Ждёт страховую: машины у менеджера ещё нет, расходов не пишут, двигает маршрут сделки. */
    public function isWaiting(): bool
    {
        return $this->state === CarState::Waiting;
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

    /** Перевести на этап: дни на этапе считаются от этого момента, шаг ложится в путь машины. */
    public function moveTo(CarState $state, array $attributes = []): void
    {
        $this->update(['state' => $state, 'stage_at' => now(), 'history' => [...($this->history ?? []), [$state->value, now()->toIso8601String()]]] + $attributes);
    }

    /**
     * Путь машины для карточки: от этапа, с которого она пришла, до расчёта — пройденные с датой, текущий, впереди.
     *
     * @return list<array{state: CarState, at: ?Carbon, status: string}>
     */
    public function path(): array
    {
        $history = collect($this->history ?? [])->map(fn ($h) => [CarState::from($h[0]), Carbon::parse($h[1])]);
        $from = $history->first()[0] ?? $this->state;
        $path = [];
        foreach (CarState::cases() as $state) {
            if ($state->order() < $from->order()) {
                continue;
            }
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
