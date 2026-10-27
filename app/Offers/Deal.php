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
use App\Logistics\Channel;
use App\Park\Pass;
use App\Park\VehicleState;
use App\Support\Demo\HidesDemo;
use App\Support\Surface;
use App\Users\User;
use App\Vendors\DealFormat;
use App\Workflow\Outcome;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
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
#[Fillable(['offer_id', 'bid_id', 'buyer_id', 'amount', 'cost', 'supplier_cost', 'owner_price', 'share', 'commission', 'commission_mode', 'scheme', 'garage_payer', 'state', 'notes', 'closed_at', 'agreed_at', 'prepaid_at', 'supplier_paid_at',
    'receiver_user_id', 'receiver_name', 'receiver_phone', 'receiver_at', 'auction_at', 'buyer_party_id'])]
class Deal extends Model
{
    use HidesDemo;

    protected function casts(): array
    {
        return ['state' => DealState::class, 'amount' => 'int', 'cost' => 'int', 'supplier_cost' => 'int', 'owner_price' => 'int', 'share' => 'int', 'commission' => 'int', 'commission_mode' => CommissionMode::class, 'scheme' => DealScheme::class, 'garage_payer' => GaragePayer::class, 'closed_at' => 'datetime', 'agreed_at' => 'datetime', 'prepaid_at' => 'datetime', 'supplier_paid_at' => 'datetime', 'receiver_at' => 'datetime', 'auction_at' => 'datetime'];
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
        // Сделка — покупатель есть: тревога «нет покупателя» гаснет; отменили — может загореться (`Binding`).
        static::saved(function (self $deal) {
            if ($deal->wasRecentlyCreated || $deal->wasChanged('state')) {
                Binding::touch(Offer::find($deal->offer_id));
            }
        });
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /**
     * Продана на аукционе (Совкомбанк, 20.10.2026): менеджера нет, покупатель аукциона — контрагент `auctionBuyer`, платит
     * нам по счёту ПРАЙМ одной строкой, без вознаграждения, паспорта и ДКП (`SellAtAuction`).
     */
    public function isAuction(): bool
    {
        return $this->auction_at !== null;
    }

    /** Состоявшиеся продажи на аукционе. */
    public function scopeAuctionSold(Builder $q): Builder
    {
        return $q->where('deals.state', DealState::Done)->whereNotNull('deals.auction_at');
    }

    public function auctionBuyer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'buyer_party_id');
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

    /**
     * Ход менеджера в SQL — для «Ваш ход» в «Сделках» сайта и бейджа (`Nav::asks`): открытая просьба или этап продажи ждёт его
     * (ПРАЙМ без покупателя — без просьбы). То же правило в PHP — `managerTask()`.
     */
    public const MANAGER_TURN_SQL = "(exists (select 1 from requirements r where r.deal_id = deals.id and r.done_at is null)
        or exists (select 1 from offer_positions p join workflow_stages s on s.id = p.stage_id where p.offer_id = deals.offer_id
            and p.track = 'sale' and coalesce(p.waits_for, s.waits_for) = 'manager'))";

    /**
     * Что сделать менеджеру по идущей сделке (27.10.2026, владелец: «глянь так же с точки зрения менеджера»): просьба шага,
     * а без неё — ход его словами («Укажите покупателя», имя шага для менеджера). Просьба «Оплатите», а счета уже
     * оплачены (маршрут не догнал), — не дело. Одно место для строки, раздела «Ваш ход» и бейджа.
     *
     * @return array{title: string, due: ?\Illuminate\Support\Carbon}|null
     */
    public function managerTask(): ?array
    {
        if (! $this->isActive()) {
            return null;
        }
        $position = $this->offer?->position();
        if ($req = $this->openRequirement) {
            return $position?->stage->isPayStep() && $this->fullyPaid() ? null : ['title' => $req->title, 'due' => $req->due_at];
        }
        if (! $position || $position->waitsFor() !== WaitsFor::Manager || $position->awaitsInvoice()) {
            return null;
        }
        $gap = $position->stage->isPayStep() && $this->hasContract() ? $this->invoiceGap() : null;

        return ['title' => match ($gap) {
            'buyer' => 'Укажите покупателя',
            'passport' => 'Приложите паспорт покупателя',
            'price' => 'Укажите цену продажи',
            default => $position->stage->managerTitle(),
        }, 'due' => $position->deadline_at];
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
     * Шаг оплаты, где за ТС платят не нам, а поставщику или собственнику («Страховой напрямую», «Страхователю по ДКП»
     * у маршрута со счётом поставщика — Альфа; 18.10.2026, прогон): менеджер платит сам и прикладывает платёжку, её
     * подтверждает поставщик. Наш подбор этот шаг не двигает — Т-Страхованию (проверка подбора — наш ход) не мешает.
     */
    /**
     * Поставщику заплачено — срок обязывающего предложения сделке больше не грозит (`Offers\Binding`, 19.10.2026): сделка
     * закрыта, мы оплатили («Поставщику оплачено», перевод с Р.О.С. — `supplier_paid_at`, ставит `EnterStage`) или
     * поставщик с оплатой до вывоза (Альфа) сам подтвердил оплату (`prepaid_at`).
     */
    public function supplierPaid(): bool
    {
        return $this->state === DealState::Done || $this->supplier_paid_at !== null
            || ($this->prepaid_at !== null && (bool) $this->offer?->vendor?->pay_before_pickup);
    }

    public function paysSupplierOn(?Stage $stage): bool
    {
        return $stage !== null && $this->paysSelection() && $stage->isPayStep()
            && $stage->payExit($this)?->to?->waits_for === WaitsFor::Supplier;
    }

    /**
     * Почему сделку рано закрывать: автомобиль ещё не забрали (18.10.2026, владелец: «без „Забрал“ не закрывать» — у Альфы
     * «Документы переданы» закрывали сделку, а вывоз висел). Гаражная закрывается, пока машину везут, — ей не мешает.
     */
    public function closeBlocker(): ?string
    {
        // Машина стоит на нашей парковке — её выдают по пропуску сделки (18.10.2026). «Ждём на парковке» не держит: страховая
        // бывает и не привозит, а сделку тогда было бы не закрыть никогда.
        $park = $this->isGarage() ? null : $this->offer?->parkVehicle()->first();
        if ($park?->state === VehicleState::Stored) {
            return 'Сначала заберите автомобиль с парковки';
        }
        $pickup = $this->isGarage() ? null : $this->offer?->pickup();
        if (! $pickup || $pickup->taken()) {
            return null;
        }

        return $pickup->channel === Channel::Manager ? 'Сначала отметьте «Автомобиль забрал»' : 'Автомобиль ещё не забрали';
    }

    /**
     * Кто заберёт машину (18.10.2026): выбранный в сделке, иначе по умолчанию — исполнитель вывоза, если везёт логистика;
     * у гаражной — её менеджер; иначе покупатель по договору, если это не сам менеджер; иначе менеджер сделки.
     */
    public function receiver(): ?Receiver
    {
        $at = $this->receiver_at;
        if ($this->receiver_user_id && ($u = User::find($this->receiver_user_id))) {
            return new Receiver($u->name, $u->phone, $u, $u->id === $this->buyer_id ? 'manager' : 'buyer', $at);
        }
        if (filled($this->receiver_name)) {
            return new Receiver($this->receiver_name, $this->receiver_phone, null, 'person', $at);
        }
        $pickup = $this->offer?->pickup();
        if ($pickup?->executor && $pickup->executor_id !== $this->buyer_id && $pickup->isOpen()) {
            return new Receiver($pickup->executor->name, $pickup->executor->phone, $pickup->executor, 'executor', $at);
        }
        $garage = $this->isGarage() ? $this->offer?->garageCar()->with('manager')->first()?->manager : null;
        if ($garage) {
            return new Receiver($garage->name, $garage->phone, $garage, 'manager', $at);
        }
        $buyer = $this->contract?->buyer;
        if ($buyer && $buyer->id !== $this->buyer_id) {
            return new Receiver($buyer->name, $buyer->phone, $buyer, 'buyer', $at);
        }

        return $this->buyer ? new Receiver($this->buyer->name, $this->buyer->phone, $this->buyer, 'manager', $at) : null;
    }

    /**
     * Можно забирать (одно правило для выдачи с нашей парковки, 18.10.2026): сделка идёт, держать нечем (`pickupHold`:
     * Альфа — оплачено, Каркаде — согласовано) и поставщик уже согласовал — у обычной `Handover::open`, у гаражной (у неё
     * `open` нет) — `agreed_at`: иначе гаражную выдавали бы прямо с «Принять», до ответа поставщика.
     */
    public function releasable(): bool
    {
        return $this->isActive() && $this->pickupHold() === null && ($this->isGarage() ? $this->agreed() : Handover::for($this)->open);
    }

    /** Пропуска на нашу парковку по этой сделке (`SyncParkRelease`). */
    public function passes(): HasMany
    {
        return $this->hasMany(Pass::class);
    }

    /** Исход ведёт в конец сделки «Сделка закрыта» (оффер — «Выдан»). */
    public static function closes(?Outcome $exit): bool
    {
        return $exit?->to?->offer_state === OfferState::Delivered;
    }

    /** Сколько менеджер платит поставщику или собственнику на таком шаге — подписью «Оплатите страховой N ₽». */
    public function supplierPayment(): ?int
    {
        return $this->owner_price ?? $this->cost;
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
        // Покупатель с гаражом — только счёт на его цену, договора мы не собираем (20.10.2026).
        if ($this->forGarageBuyer()) {
            return false;
        }

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

    /**
     * Сколько скинул поставщик после подтверждения менеджера: остаётся нам (только админу). У ДКП и «страховой напрямую»
     * собственнику платит покупатель сам — скидка через нас не идёт, её нет.
     */
    public function supplierGain(): int
    {
        return $this->supplier_cost !== null && $this->cost !== null && ! $this->paysSelection() ? $this->cost - $this->supplier_cost : 0;
    }

    /** Гаражная сделка: менеджер забирает машину себе на подготовку, цены у неё нет. */
    public function isGarage(): bool
    {
        return $this->garage_payer !== null;
    }

    /**
     * Гаражная покупателя с гаражом по тумблеру (20.10.2026): поставщику платим мы, он платит нам свою цену
     * (`garage_cars.buyer_price`) счётом ПРАЙМ — долгом, вне зависимости от вывоза; расчёта и вознаграждения нет.
     */
    public function forGarageBuyer(): bool
    {
        return $this->isGarage() && (bool) $this->buyer?->holdsAsBuyer();
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

    /**
     * Срок оплаты менеджером (27.10.2026, владелец: «на оплату вне зависимости, указал он покупателя или нет»; 14 дней — «5 дней мало, обычно 14»): вход
     * на шаг оплаты (у Совкомбанка — «Забрал») плюс срок этапа. Одна дата для счёта (`SyncDealInvoices::dueFor`), часов шага
     * (`EnterStage`, `PassTurnOnInvoice`) и долга в «Сделках» (`DealDebt`). Не на шаге оплаты — null. Гаражная Совкомбанка
     * стоит на шаге оплаты с «Забрал», а платить ей нечего до продажи — срок от «Продаю», не продана — null.
     */
    public function payDue(): ?\Illuminate\Support\Carbon
    {
        $position = $this->offer?->position();
        if (! $position?->entered_at || ! $position->stage->isPayStep()) {
            return null;
        }
        $from = $position->entered_at;
        if ($this->namesBuyer()) {
            $sold = $this->garageCar?->sold_at;
            if (! $sold) {
                return null;
            }
            $from = $sold->max($from);
        }

        return $position->stage->deadlineFor($this->offer, $from);
    }

    /** Сделки в разделе «Сделки» сайта: админу все, менеджеру — его; админ, который ещё и менеджер, на сайте менеджер. */
    public function scopeOf(Builder $q, User $user): Builder
    {
        // Продажи на аукционе — только в CRM: на сайте у них нет ни менеджера, ни страницы.
        return $user->isAdmin() && ! $user->isManager() ? $q->whereNull('deals.auction_at') : $q->where('buyer_id', $user->id);
    }

    /**
     * Переоформленная (17.10.2026, Макаров: «почему отменено написано?»): отменённая, за которой тому же покупателю по той
     * же машине открыли новую сделку — админ отменил и тут же принял снова (поправить вознаграждение). Менеджеру это не
     * отмена: в архиве её нет, ссылка ведёт на новую (`successor`).
     */
    public function scopeNotSuperseded(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->where('deals.state', '!=', DealState::Cancelled->value)->orWhereNotExists(fn ($n) => $n->selectRaw('1')
            ->from('deals as later')->whereColumn('later.offer_id', 'deals.offer_id')->whereColumn('later.buyer_id', 'deals.buyer_id')
            ->whereColumn('later.id', '>', 'deals.id')));
    }

    /** Сделка, что заменила эту отменённую у того же покупателя; null — отмена настоящая. */
    public function successor(): ?self
    {
        return $this->state === DealState::Cancelled && $this->buyer_id
            ? self::where('offer_id', $this->offer_id)->where('buyer_id', $this->buyer_id)->where('id', '>', $this->id)->orderByDesc('id')->first()
            : null;
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
     * Автомобиль у страхователя забирает сам менеджер сделки (04.10.2026): он исполнитель вывоза в логистике (17.10.2026).
     * Админ решает это при принятии подтверждения (`BidController::accept`) или в окне «Кто вывозит». Ветка продажи
     * `Outcome::BUYER_PICKS` — его шаг «Заберите автомобиль».
     */
    public function buyerPicksUp(): bool
    {
        // Гаражная «платит менеджер» идёт путём обычной сделки: везёт сам — его шаг «Заберите автомобиль».
        if ($this->buyer_id === null || $this->isGarageUs() || ! $this->offer) {
            return false;
        }
        $pickup = $this->offer->pickup();

        return $pickup?->channel === Channel::Manager && $pickup->executor_id === $this->buyer_id;
    }

    /**
     * Машину везёт не менеджер сделки: вывоз открыт, а исполнитель — логист, наши, другой менеджер или ещё никто (17.10.2026,
     * прогон этапов). Тогда шаг «Менеджер забирает автомобиль» ждёт нас: просьбы «Заберите» и часов у менеджера нет
     * (`EnterStage`, `SyncHandover::turn`), в сделке — «Везёт …» (`Handover`). Вывоз читается из связи `pickupTask`: кто
     * только что его завёл или назначил, снимает её до вызова.
     */
    public function carried(): bool
    {
        return (bool) $this->offer?->pickup()?->isOpen() && ! $this->buyerPicksUp();
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
     * Гаражная Совкомбанка, платит покупатель (27.10.2026, владелец): счёт ПРАЙМ ему — цена продажи минус вознаграждение
     * менеджера, вознаграждение покупатель отдаёт менеджеру сам, мы его не выплачиваем.
     */
    public function buyerPaysReward(): bool
    {
        return $this->namesBuyer() && $this->contract?->buyer_user_id && ! $this->contract->managerPays();
    }

    /**
     * Менеджер удерживает вознаграждение. У ПРАЙМ — только когда по счёту платит он сам: платит покупатель — удерживать
     * не из чего, вознаграждение выплачиваем (режим, что выбрал админ, при этом не теряется). Гаражная Совкомбанка с
     * покупателем-плательщиком — всегда: вознаграждение он берёт с покупателя (`buyerPaysReward`).
     */
    public function withholds(): bool
    {
        if ($this->buyerPaysReward()) {
            return true;
        }
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
