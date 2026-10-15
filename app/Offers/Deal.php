<?php

namespace App\Offers;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\Payment;
use App\Billing\PaymentSource;
use App\Billing\PaymentState;
use App\Billing\Seller;
use App\Garage\Car as GarageCar;
use App\Garage\GaragePayer;
use App\Support\Demo\HidesDemo;
use App\Support\Surface;
use App\Users\User;
use App\Vendors\DealFormat;
use App\Workflow\Requirement;
use App\Workflow\Track;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Сделка: чьё подтверждение принято и почём. Деньги — `cost` (закупочная, какой её видел менеджер, когда подтверждал,
 * — закреплена навсегда, 15.10.2026: по ней счета, ДКП и всё, что видит менеджер; новую от поставщика пишет
 * `supplier_cost`, только админу — `supplierCost()`), `commission` (агентское вознаграждение менеджеру) и режим:
 * выплачиваем после оплаты или менеджер удерживает сам. Менеджер вознаграждение
 * не видит, пока по сделке нет живого счёта — `showsCommission()`.
 */
#[Fillable(['offer_id', 'bid_id', 'buyer_id', 'amount', 'cost', 'supplier_cost', 'owner_price', 'share', 'commission', 'commission_mode', 'scheme', 'garage_payer', 'state', 'notes', 'closed_at', 'agreed_at', 'prepaid_at'])]
class Deal extends Model
{
    use HidesDemo;

    protected function casts(): array
    {
        return ['state' => DealState::class, 'amount' => 'int', 'cost' => 'int', 'supplier_cost' => 'int', 'owner_price' => 'int', 'share' => 'int', 'commission' => 'int', 'commission_mode' => CommissionMode::class, 'scheme' => DealScheme::class, 'garage_payer' => GaragePayer::class, 'closed_at' => 'datetime', 'agreed_at' => 'datetime', 'prepaid_at' => 'datetime'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    protected static function booted(): void
    {
        // Супер-покупатель покупает у нас сам: вознаграждения у его сделки не бывает, какой бы путь её ни правил
        // («Принять», «Деньги» в CRM, передача резерву).
        static::saving(function (self $deal) {
            if ($deal->commission !== null && $deal->isDirect()) {
                $deal->commission = null;
            }
        });
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /** Сделка супер-покупателя: он покупает сам, без вознаграждения; покупатель и плательщик по договору — он же. */
    public function isDirect(): bool
    {
        return (bool) $this->buyer?->isSuperBuyer();
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

    /**
     * Поставщик согласовал, а где спрашиваем менеджера — тот сказал «Покупаю» (09.10.2026, владелец: «ссылки на оплату
     * вообще быть не должно, пока поставщик не подтвердил окончательно»). До того счетов у сделки нет (`AgreeDeal`).
     */
    public function agreed(): bool
    {
        return $this->agreed_at !== null;
    }

    /**
     * Когда встанет счёт, пока поставщик не согласовал (`agreed`): одна фраза для «Расчёта» сделки и строки «Оплат» —
     * у Совкомбанка после «Автомобиль забрал», где спрашиваем менеджера — после «Беру в работу», иначе после ответа
     * поставщика. null — согласовано, счёт ставится сам.
     */
    public function invoiceNotYet(): ?string
    {
        if ($this->agreed()) {
            return null;
        }

        return match (true) {
            $this->isCommission() => 'после получения автомобиля',
            (bool) $this->offer?->position(Track::Sale)?->stage->asksConsent() => 'после «Беру в работу»',
            default => 'после ответа поставщика',
        };
    }

    /**
     * Забирать можно (10.10.2026, владелец: «альфастрахование — только с предварительной оплатой»): у вендора с
     * `pay_before_pickup` — когда маршрут прошёл проверку оплаты (`prepaid_at`, ставит `EnterStage`); у оптового (Каркаде,
     * «выкупаем мы», 11.10.2026, владелец: «набираем лот, отправляем заявку на десятки машин, и только после этого их можно
     * забрать») — когда поставщик согласовал (`agreed_at`); у остальных — всегда.
     */
    public function mayPickUp(): bool
    {
        return $this->pickupHold() === null;
    }

    /** Почему забирать рано — словом для пути и вывоза: «ждём поставщика», «после оплаты»; null — можно. */
    public function pickupHold(): ?string
    {
        $vendor = $this->offer?->vendor;
        if ($vendor?->deal_format === DealFormat::Wholesale && $this->agreed_at === null) {
            return 'ждём поставщика';
        }
        if ($this->prepaid_at !== null || ! $vendor?->pay_before_pickup) {
            return null;
        }

        // Маршрута продажи у вендора нет (Альфа СПб завели без него) — `prepaid_at` ставить некому: забирать, когда
        // счета сделки оплачены, а не никогда.
        return ! $vendor->workflow(Track::Sale)?->is_active && $this->fullyPaid() ? null : 'после оплаты';
    }

    /**
     * Сделки вендора, что ждут ответа поставщика «отдаёт или нет» (этап с исходом «Поставщик согласовал»): «Ответ по
     * списку» в CRM (09.10.2026, Каркаде отвечает одним ответом на весь список).
     */
    public static function awaitingSupplier(int $vendorId): Collection
    {
        return self::onExit($vendorId, 'поставщик согласовал%');
    }

    /** Сделки вендора, что ждут заявки поставщику (этап с исходом «Заявка отправлена», Каркаде): следующая заявка — они. */
    public static function awaitingRequest(int $vendorId): Collection
    {
        return self::onExit($vendorId, 'заявка отправлена');
    }

    /** Активные сделки вендора, чей этап продажи уходит исходом с такой подписью (ilike). */
    private static function onExit(int $vendorId, string $label): Collection
    {
        return self::where('state', DealState::Active)
            ->whereHas('offer', fn (Builder $o) => $o->where('vendor_id', $vendorId)->whereHas('positions', fn (Builder $p) => $p->where('track', 'sale')
                ->whereHas('stage.exits', fn (Builder $e) => $e->where('label', 'ilike', $label))))
            ->with(['offer', 'offer.settlement', 'buyer'])->orderBy('id')->get();
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

    /**
     * Покупатель (или менеджер) платит за ТС нашей конторе по счёту, она — страховой: ПРАЙМ (Совкомбанк; Альфа бывает)
     * или Р.О.С. (Альфа с НДС). Счёт, договор и вознаграждение у обеих одинаковые, разный только продавец (`seller`).
     */
    public function isPrime(): bool
    {
        return $this->schemeOf()->paysCar();
    }

    /** Кто продаёт ТС по этой сделке — от него счёт за ТС и ДКП. */
    public function seller(): Seller
    {
        return $this->schemeOf()->seller();
    }

    /** Менеджер платит нам подбор по ссылке — у ДКП и «страховой напрямую»; гаражная — не по цене, а долей (`share`). */
    public function paysSelection(): bool
    {
        return ! $this->isGarage() && $this->schemeOf()->paysSelection();
    }

    /**
     * Кто платит у гаражной — словами: у Совкомбанка (договор комиссии) поставщику платит ПРАЙМ, а менеджер — ПРАЙМ по
     * счёту (10.10.2026, владелец: «совкому всегда платит только ООО ПРАЙМ»), у остальных — «поставщику платит …».
     */
    public function garagePayerLine(): ?string
    {
        if (! $this->garage_payer) {
            return null;
        }
        if ($this->isCommission()) {
            return $this->garage_payer === GaragePayer::Manager ? 'без оплаты, счёт ПРАЙМ при продаже' : 'поставщику платим мы';
        }

        return $this->garage_payer === GaragePayer::Us ? 'поставщику платим мы' : 'поставщику платит менеджер';
    }

    /**
     * Гаражная «платит менеджер»: за машину — счёт ПРАЙМ на закупочную (у ПРАЙМ), наша доля — по ссылке. Кроме Совкомбанка
     * (`namesBuyer`): там машина уходит без оплаты, а платит покупатель, когда менеджер её продаст.
     */
    public function isGarageManager(): bool
    {
        return $this->garage_payer === GaragePayer::Manager && ! $this->isCommission();
    }

    /**
     * Совкомбанк (договор комиссии, `DealFormat::Commission`; 10.10.2026, владелец): машину вывозят без оплаты, менеджер
     * забирает её сразу; счёт ПРАЙМ и договор купли-продажи выставляются, когда он назовёт покупателя, ПРАЙМ платит
     * Совкомбанку после оплаты.
     */
    public function isCommission(): bool
    {
        return (bool) $this->offer?->vendor?->isCommission();
    }

    /**
     * Гаражная Совкомбанка: машина у менеджера без оплаты, цену он называет при продаже, мы — его вознаграждение; дальше
     * как обычная ПРАЙМ-сделка: счёт и договор тому, кого он назовёт.
     */
    public function namesBuyer(): bool
    {
        // «Взяли под себя» (платим мы, менеджера нет) — не она: там машину оплачиваем и оформляем на ПРАЙМ сами.
        return $this->garage_payer === GaragePayer::Manager && $this->isCommission();
    }

    /** Договор собираем мы: ДКП собственника или ПРАЙМ (в том числе с менеджером гаражной); «страховой» — нет. */
    public function hasContract(): bool
    {
        return $this->isDkp() || ($this->isPrime() && (! $this->isGarage() || $this->isGarageManager() || $this->namesBuyer()));
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
     * Почему этапу оплаты нечем платить: `buyer` — ПРАЙМ, а менеджер ещё не указал, кому счёт (ход его); `price` —
     * гаражной Совкомбанка не названа цена продажи (ход его); `commission` — ей не назначено вознаграждение (ход наш);
     * `share` — гаражной не вписана наша доля (ход наш); `passport` — у покупателя-физлица нет паспорта со сканом (ход
     * менеджера); `invoice` — счёт не встал сам, выставить руками. Есть счёт — null.
     */
    public function invoiceGap(): ?string
    {
        if ($this->hasManagerInvoice()) {
            return null;
        }

        return match (true) {
            // Гаражная Совкомбанка: цену продажи и покупателя называет менеджер («Продаю»), вознаграждение — мы.
            $this->namesBuyer() && $this->amount === null => 'price',
            $this->isGarageManager() && ! $this->share => 'share',
            $this->isPrime() && (! $this->isGarage() || $this->namesBuyer()) && ! $this->contract?->buyer_user_id => 'buyer',
            $this->namesBuyer() && $this->commission === null => 'commission',
            // Р.О.С. без реквизитов (пришлёт владелец) — счёт выставить не от кого.
            $this->seller() === Seller::Ros && ! Party::seller(Seller::Ros)->billable() => 'ros_requisites',
            // Покупатель-физлицо без паспорта со сканом (11.10.2026): счёта ПРАЙМ нет, ход менеджера.
            $this->isPrime() && ! $this->passportReady() => 'passport',
            default => 'invoice',
        };
    }

    /** Счёта нет, и ход за менеджером: не указал покупателя (ПРАЙМ), цену продажи (гаражная Совкомбанка) или паспорт покупателя. */
    public function managerGap(): bool
    {
        return in_array($this->invoiceGap(), ['buyer', 'price', 'passport'], true);
    }

    /**
     * Паспорт покупателя по договору готов со сканом (`Party::readyAsBuyer`). Гаражной «платит менеджер» покупатель —
     * сам менеджер; ПРАЙМ без договора — нечего проверять.
     */
    public function passportReady(): bool
    {
        $buyer = $this->isGarage() && ! $this->namesBuyer() ? ($this->isGarageManager() ? $this->buyer : null) : $this->contract?->buyer;

        return ! $buyer || Party::forUser($buyer)->readyAsBuyer();
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

    /**
     * Разовые оплаты по ссылке, засчитанные в сделку (09.10.2026, владелец: «чтобы если что привязать эту оплату к
     * сделке»): ручной счёт `Service` с машиной этой сделки. Своих `deal_id` у них нет — их деньги ложатся в счета сделки
     * зачётом (`ApplyDealCredits`), что сверх — доплата (`creditExtra`).
     */
    public function credits(): HasMany
    {
        return $this->hasMany(Invoice::class, 'credit_deal_id')->where('kind', ChargeKind::Service)->where('direction', 'issued')
            ->where('state', '!=', InvoiceState::Void)->orderBy('id');
    }

    /** Сколько по засчитанным разовым оплатам пришло денег (заявки и неоплаченное — не в счёт). */
    public function credited(): float
    {
        return round((float) ($this->relationLoaded('credits') ? $this->credits->sum('paid') : $this->credits()->sum('paid')), 2);
    }

    /**
     * Сколько менеджер должен нам по ссылке, до засчитанного: у гаражной «платит менеджер» — наша доля, у ДКП и
     * «страховой напрямую» — разница минус собственнику и вознаграждение; у ПРАЙМ — ноль (там счёт за машину).
     */
    public function selectionDue(): int
    {
        return match (true) {
            $this->isGarageManager() => (int) $this->share,
            $this->paysSelection() => (int) $this->selectionBase() - (int) $this->commission,
            default => 0,
        };
    }

    /** Сколько денег разовых ссылок уже лежит в счетах сделки зачётом. */
    public function creditApplied(): float
    {
        return round((float) Payment::whereIn('credit_invoice_id', $this->credits()->select('billing_invoices.id'))
            ->where('source', PaymentSource::Credit)->whereNull('voided_at')->where('state', PaymentState::Confirmed)->sum('amount'), 2);
    }

    /**
     * Деньги разовых ссылок, которые сделке класть некуда: её счета оплачены (доплата сверх сделки — владелец: «вдруг там
     * дополнительные затраты») или счёта ещё нет (ляжут, когда выставим — тогда это ещё не доплата: `agreed`).
     */
    public function creditExtra(): float
    {
        return max(0, round($this->credited() - $this->creditApplied(), 2));
    }

    /** Обязательство перед менеджером — вознаграждение к выплате. */
    public function agentFee(): HasOne
    {
        // Условия — внутри ofMany: снаружи они ложатся после подзапроса «последний счёт сделки», и счёт за хранение,
        // выставленный позже, прятал вознаграждение — `IssueAgentFee` начислял его заново (06.10.2026).
        return $this->hasOne(Invoice::class)->ofMany(['id' => 'max'], fn ($q) => $q->where('direction', 'owed')->where('kind', ChargeKind::AgentFee)->where('state', '!=', InvoiceState::Void));
    }

    /** Машина в гараже по этой сделке: пока сделка идёт, бумаги со страховой ведёт её маршрут. */
    public function garageCar(): HasOne
    {
        return $this->hasOne(GarageCar::class);
    }

    /** Наценка над закупочной поставщика для CRM (`Margin`): у идущей сделки с ценой подтверждения. */
    public function marginMark(): ?array
    {
        return $this->state === DealState::Active && ! $this->isGarage() ? Margin::of($this->amount, $this->supplierCost()) : null;
    }

    /** Наша закупка у поставщика — только админу (маржа, оплата поставщику); новой не было — закреплённая `cost`. */
    public function supplierCost(): ?int
    {
        return $this->supplier_cost ?? $this->cost;
    }

    /** Сколько скинул поставщик после подтверждения менеджера: остаётся нам (только админу). */
    public function supplierGain(): int
    {
        return $this->supplier_cost !== null && $this->cost !== null ? $this->cost - $this->supplier_cost : 0;
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

    /** Сделки в разделе «Сделки» сайта: админу все, менеджеру — его; админ, который ещё и менеджер, на сайте менеджер. */
    public function scopeOf(Builder $q, User $user): Builder
    {
        return $user->isAdmin() && ! $user->isManager() ? $q : $q->where('buyer_id', $user->id);
    }

    /** Сделка в CRM: админ ведёт её в редакторе предложения (`Admin\DealController::show`). */
    public function crmUrl(): string
    {
        return Surface::Crm->url('/offers/'.$this->offer->number);
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

        return ! ($this->isPrime() && (! $this->isGarage() || $this->namesBuyer()) && $this->contract?->buyer_user_id && ! $this->contract->managerPays());
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
            && $i->payments->every(fn ($p) => $p->source->isSoft()));
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
