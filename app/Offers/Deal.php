<?php

namespace App\Offers;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Garage\Car as GarageCar;
use App\Garage\GaragePayer;
use App\Support\Demo\HidesDemo;
use App\Users\User;
use App\Workflow\Requirement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Сделка: чьё подтверждение принято и почём. Деньги — `cost` (закупочная в момент
 * принятия, снимок), `commission` (агентское вознаграждение менеджеру) и режим:
 * выплачиваем после оплаты или менеджер удерживает сам. Менеджер вознаграждение
 * не видит, пока по сделке нет живого счёта — `showsCommission()`.
 */
#[Fillable(['offer_id', 'bid_id', 'buyer_id', 'amount', 'cost', 'owner_price', 'share', 'commission', 'commission_mode', 'scheme', 'garage_payer', 'state', 'notes', 'closed_at'])]
class Deal extends Model
{
    use HidesDemo;

    protected function casts(): array
    {
        return ['state' => DealState::class, 'amount' => 'int', 'cost' => 'int', 'owner_price' => 'int', 'share' => 'int', 'commission' => 'int', 'commission_mode' => CommissionMode::class, 'scheme' => DealScheme::class, 'garage_payer' => GaragePayer::class, 'closed_at' => 'datetime'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(Requirement::class)->latest();
    }

    public function openRequirement(): HasOne
    {
        return $this->hasOne(Requirement::class)->whereNull('done_at')->latestOfMany();
    }

    /** Договор купли-продажи: собственника (ДКП) или ПРАЙМ с покупателем менеджера (`DealContract`). */
    public function contract(): HasOne
    {
        return $this->hasOne(DealContract::class);
    }

    /** Схема оплаты (`DealScheme`); у старых строк — ПРАЙМ по счёту. */
    public function schemeOf(): DealScheme
    {
        return $this->scheme ?? DealScheme::Prime;
    }

    /**
     * Покупатель менеджера платит закупочную страхователю сам по ДКП, менеджер нам — разницу за подбор, вознаграждение
     * оставляет себе (05.10.2026, Т-Страхование).
     */
    public function isDkp(): bool
    {
        return $this->schemeOf() === DealScheme::OwnerDkp;
    }

    /** Покупатель (или менеджер) платит ПРАЙМ по счёту, ПРАЙМ — страховой (Совкомбанк; Альфа бывает). */
    public function isPrime(): bool
    {
        return $this->schemeOf() === DealScheme::Prime;
    }

    /** Менеджер платит нам подбор по ссылке — у ДКП и «страховой напрямую»; гаражная — не по цене, а долей (`share`). */
    public function paysSelection(): bool
    {
        return ! $this->isGarage() && $this->schemeOf()->paysSelection();
    }

    /** Гаражная «платит менеджер»: за машину — счёт ПРАЙМ на закупочную (у ПРАЙМ), наша доля — по ссылке. */
    public function isGarageManager(): bool
    {
        return $this->garage_payer === GaragePayer::Manager;
    }

    /** Договор собираем мы: ДКП собственника или ПРАЙМ (в том числе с менеджером гаражной); «страховой» — нет. */
    public function hasContract(): bool
    {
        return $this->isDkp() || ($this->isPrime() && (! $this->isGarage() || $this->isGarageManager()));
    }

    /** Сколько покупатель отдаёт не нам — собственнику по ДКП или страховой: своя сумма, а без неё закупочная. */
    public function ownerPrice(): ?int
    {
        return $this->paysSelection() ? ($this->owner_price ?? $this->cost) : null;
    }

    /** Взаимозачёт: закупочная больше того, что получает собственник или страховая, — разницей гасится долг перед нами. */
    public function offset(): int
    {
        return $this->paysSelection() && $this->cost !== null ? max(0, $this->cost - (int) $this->ownerPrice()) : 0;
    }

    /** Подбор: цена подтверждения минус то, что отдают собственнику или страховой, — из этого и вознаграждение менеджера. */
    public function selectionBase(): ?int
    {
        return $this->amount === null || $this->ownerPrice() === null ? null : $this->amount - $this->ownerPrice();
    }

    /**
     * Почему этапу оплаты нечем платить: `buyer` — ПРАЙМ, а менеджер ещё не указал, кому счёт (ход его); `share` —
     * гаражной не вписана наша доля (ход наш); `invoice` — счёт не встал сам, выставить руками. Есть счёт — null.
     */
    public function invoiceGap(): ?string
    {
        if ($this->hasManagerInvoice()) {
            return null;
        }

        return match (true) {
            $this->isGarageManager() && ! $this->share => 'share',
            $this->isPrime() && ! $this->isGarage() && ! $this->contract?->buyer_user_id => 'buyer',
            default => 'invoice',
        };
    }

    /** Живые счета по сделке в обе стороны, старые первыми. */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->where('state', '!=', InvoiceState::Void)->orderBy('id');
    }

    /** Наши счета покупателю или вендору по этой сделке. */
    public function issuedInvoices(): HasMany
    {
        return $this->invoices()->where('direction', 'issued');
    }

    /** Обязательство перед менеджером — вознаграждение к выплате. */
    public function agentFee(): HasOne
    {
        return $this->hasOne(Invoice::class)->where('direction', 'owed')->where('kind', ChargeKind::AgentFee)->where('state', '!=', InvoiceState::Void)->latestOfMany();
    }

    /** Машина в гараже по этой сделке: пока ждёт страховую, маршрут сделки и есть её этап. */
    public function garageCar(): HasOne
    {
        return $this->hasOne(GarageCar::class);
    }

    /** Гаражная сделка: менеджер забирает машину себе на подготовку, цены у неё нет. */
    public function isGarage(): bool
    {
        return $this->garage_payer !== null;
    }

    /** Гаражная, и поставщику платим мы: маршрут идёт гаражной веткой. */
    public function isGarageUs(): bool
    {
        return $this->garage_payer === GaragePayer::Us;
    }

    /** От чего считать счёт и письма: цена подтверждения, у гаражной — закупочная. */
    public function base(): ?int
    {
        return $this->amount ?? $this->cost;
    }

    /** Куда ведёт сделка менеджера: гаражная живёт в гараже, а не в «Сделках». */
    public function href(): string
    {
        if ($this->isGarage() && ($car = $this->garageCar ?? GarageCar::where('offer_id', $this->offer_id)->first())) {
            return $car->url();
        }

        return '/deals/'.$this->id;
    }

    public function isActive(): bool
    {
        return $this->state === DealState::Active;
    }

    /**
     * Автомобиль у страхователя забирает сам менеджер сделки (04.10.2026): он и вывозчик предложения. Админ решает это
     * при принятии подтверждения (`BidController::accept` → `AssignPickup`), им же меняет в карточке сделки. Ветка
     * продажи `Outcome::BUYER_PICKS` — его шаг «Заберите автомобиль».
     */
    public function buyerPicksUp(): bool
    {
        // Гаражная «платит менеджер» идёт путём обычной сделки: везёт сам — его шаг «Заберите автомобиль».
        return $this->buyer_id !== null && ! $this->isGarageUs() && $this->offer?->evacuator_id === $this->buyer_id;
    }

    /** Разница между ценой подтверждения и закупочной; без закупочной — null. */
    public function margin(): ?int
    {
        return $this->cost === null || $this->amount === null ? null : $this->amount - $this->cost;
    }

    /**
     * Что остаётся нам: ПРАЙМ — разница минус вознаграждение менеджера; ДКП и страховой — подбор, который менеджер платит
     * нам (взаимозачёт внутри); гаражная «платит менеджер» — наша доля.
     */
    public function ours(): ?int
    {
        if ($this->isGarageManager()) {
            return $this->share;
        }
        $margin = $this->paysSelection() ? $this->selectionBase() : $this->margin();

        return $margin === null ? null : $margin - (int) $this->commission;
    }

    /**
     * Менеджер удерживает вознаграждение. У ПРАЙМ — только когда по счёту платит он сам: платит покупатель — удерживать
     * не из чего, вознаграждение выплачиваем (режим, что выбрал админ, при этом не теряется).
     */
    public function withholds(): bool
    {
        if ($this->commission_mode !== CommissionMode::Withheld) {
            return false;
        }

        return ! ($this->isPrime() && ! $this->isGarage() && $this->contract?->buyer_user_id && ! $this->contract->managerPays());
    }

    /** Менеджеру вознаграждение открывается с первого живого счёта по сделке. */
    /** Есть ли живой счёт — для экранов: по загруженным счетам, если они уже есть (списки «Денег»), иначе запросом. */
    /**
     * Есть ли счёт, который платит менеджер (или его покупатель): наш, живой, не вознаграждение от вендора. Без него
     * этап оплаты ждёт нас — выставить счёт (`Position::awaitsInvoice`).
     */
    public function hasManagerInvoice(): bool
    {
        return $this->issuedInvoices()->where('kind', '!=', ChargeKind::Reward)->exists();
    }

    public function hasInvoices(): bool
    {
        return $this->relationLoaded('invoices') ? $this->invoices->isNotEmpty() : $this->invoices()->exists();
    }

    public function showsCommission(): bool
    {
        return $this->commission !== null && $this->hasInvoices();
    }

    /** Вознаграждение правится, пока по сделке нет ни одного живого счёта. */
    public function commissionEditable(): bool
    {
        // Проверка перед записью (UpdateDealMoney) — всегда из базы: загруженные счета могли устареть.
        return ! $this->invoices()->exists();
    }

    /**
     * Деньги сделки правятся, пока за наши счета не платили (зачёт удержанного вознаграждения — не оплата) и нет
     * выплаты менеджеру: счета, что ставятся сами, перевыставит `SyncDealInvoices`.
     */
    public function moneyEditable(): bool
    {
        $invoices = $this->invoices()->with(['payments', 'claims'])->get();

        return $invoices->every(fn (Invoice $i) => $i->direction === 'issued' && $i->kind !== ChargeKind::Reward && $i->claims->isEmpty()
            && $i->payments->every(fn ($p) => $p->source === \App\Billing\PaymentSource::Offset));
    }

    public function commissionState(): CommissionState
    {
        if (! $this->showsCommission()) {
            return CommissionState::Hidden;
        }
        if ($this->withholds()) {
            return CommissionState::Withheld;
        }
        $fee = $this->agentFee;

        return match (true) {
            $fee === null => CommissionState::Awaiting,
            $fee->state === InvoiceState::Paid => CommissionState::Paid,
            default => CommissionState::Payable,
        };
    }

    /** Все наши счета по сделке оплачены, и хотя бы один есть — сигнал «вознаграждение к выплате». */
    public function fullyPaid(): bool
    {
        $issued = $this->issuedInvoices()->get();

        return $issued->isNotEmpty() && $issued->every(fn (Invoice $i) => $i->state === InvoiceState::Paid);
    }
}
