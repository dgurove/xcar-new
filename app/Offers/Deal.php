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
use App\Garage\Payer;
use App\Logistics\Channel;
use App\Mail\Template;
use App\Notifications\ExtraAskDecisionNotice;
use App\Offers\Documents\PaymentProofPdf;
use App\Offers\Documents\SignedContractPdf;
use App\Park\Pass;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Demo\HidesDemo;
use App\Support\FastAttributes;
use App\Support\Money;
use App\Support\Surface;
use App\Users\User;
use App\Vendors\DealFormat;
use App\Workflow\Actor;
use App\Workflow\Asks;
use App\Workflow\Outcome;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Сделка: чьё подтверждение принято и почём. Деньги — `cost` (закупочная, какой её видел менеджер, когда подтверждал,
 * — закреплена навсегда, 15.10.2026: по ней счета, ДКП и всё, что видит менеджер; новую от поставщика пишет
 * `supplier_cost`, только админу — `supplierCost()`) и `commission` — вознаграждение менеджера. Его считаем мы (доля
 * `users.deal_share`, у гаража Совкомбанка — `garage_share`), а получает он его не от нас (18.11.2026, начальник: «мы
 * вознаграждения менеджерам никогда не выплачиваем»): наши счёт и ДКП — цена минус вознаграждение, из них закупочная — за ТС,
 * остальное — услуги подбора (`ours`). Выплат менеджеру, режима «выплачиваем» и зачёта «удержано» в системе нет.
 */
#[Fillable(['manager_sees', 'offer_id', 'bid_id', 'buyer_id', 'amount', 'cost', 'supplier_cost', 'owner_price', 'share', 'commission', 'scheme', 'garage_payer', 'parallel', 'notes', 'closed_at', 'cancelled_at', 'close_reason', 'close_note', 'agreed_at', 'buyer_asked_at', 'signing_slots', 'signing_address', 'signing_at', 'signing_asked_at', 'signing_wish', 'signing_settlement_id', 'manager_done_at', 'prepaid_at', 'supplier_paid_at', 'contract_signed_at', 'contract_signed_by', 'paid_in_at', 'dates_by_hand',
    'receiver_user_id', 'receiver_name', 'receiver_phone', 'receiver_at', 'auction_at', 'buyer_party_id', 'payer_party_id', 'fee_seller', 'shadow_stage_id', 'shadow_at', 'reissue_at'])]
class Deal extends Model implements HasMedia
{
    use FastAttributes;
    use HidesDemo;
    use InteractsWithMedia;

    /**
     * Подписанный договор, сделанный вне системы (15.11.2026, владелец: «ОБЯЗАТЕЛЬНО возможность прикрепить подписанный
     * договор сразу, без покупателя»): файлы у самой сделки, а не у просьбы шага — приложить можно на любом шаге
     * (`Actions\AttachSignedContract`).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('signed_contract')->useDisk('private');
        // Исправленный договор (19.11.2026, `Offers\ContractEdit`): файл, что приложил сотрудник (Word или PDF), и его PDF.
        $this->addMediaCollection('dkp_edited')->useDisk('private')->singleFile();
        $this->addMediaCollection('dkp_edited_pdf')->useDisk('private')->singleFile();
    }

    protected function casts(): array
    {
        return ['scheme_at' => 'datetime', 'parallel' => 'bool', 'amount' => 'int', 'cost' => 'int', 'supplier_cost' => 'int', 'owner_price' => 'int', 'share' => 'int', 'commission' => 'int', 'scheme' => DealScheme::class, 'garage_payer' => GaragePayer::class, 'closed_at' => 'datetime', 'cancelled_at' => 'datetime', 'agreed_at' => 'datetime', 'buyer_asked_at' => 'datetime', 'signing_slots' => 'array', 'signing_at' => 'datetime', 'signing_asked_at' => 'datetime', 'signing_wish' => 'array', 'prepaid_at' => 'datetime', 'supplier_paid_at' => 'datetime', 'contract_signed_at' => 'datetime', 'paid_in_at' => 'datetime', 'dates_by_hand' => 'array', 'receiver_at' => 'datetime', 'auction_at' => 'datetime', 'shadow_at' => 'datetime', 'reissue_at' => 'datetime', 'manager_sees' => 'array', 'manager_done_at' => 'datetime'];
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
            // Вознаграждение по доле менеджера идёт за деньгами (13.11.2026): поменялись цена или закупочная (переоценка,
            // контрпредложение, «Цена за менеджера»), а вознаграждение равно доле от прежних чисел — доля от новых. Вписанное
            // руками (не равное доле) не трогается.
            if ($deal->exists && $deal->isDirty(['amount', 'cost']) && ! $deal->isDirty('commission') && $deal->commission !== null) {
                $was = $deal->defaultCommission($deal->getOriginal('amount'), $deal->getOriginal('cost'));
                if ($was !== null && $was === $deal->commission && ($now = $deal->defaultCommission()) !== null) {
                    $deal->commission = $now;
                }
            }
            // Кто и когда выбрал схему (03.11.2026, владелец: «а кто нажал, что ПРАЙМ, я не понял») — любая дверь:
            // «Принять», «Отдать», «Схема» в CRM. Без человека (часы, консоль) — пусто, «автоматически».
            if (! $deal->exists || $deal->isDirty('scheme')) {
                $deal->scheme_by = auth()->id();
                $deal->scheme_at = now();
            }
        });
        // Прежняя колонка `deals.state` живёт одну выкладку (16.11.2026, переход без простоя): старые контейнеры, что ещё
        // работают, пока идёт выкладка, читают её — пишем туда выведенное состояние. Следующая выкладка колонку снимает.
        static::saving(function (self $deal) {
            if (self::$legacyState ??= Schema::hasColumn('deals', 'state')) {
                $deal->attributes['state'] = $deal->state->value;
            }
        });
        static::saved(function (self $deal) {
            $moved = $deal->wasRecentlyCreated || $deal->wasChanged(['closed_at', 'cancelled_at', 'parallel']);
            // Идущие условия — текущие у машины (`offers.deal_id`): новая сделка, переоткрытая, взятая в работу параллельная.
            if ($moved && $deal->isActive()) {
                self::pointer($deal->offer_id)->where(fn ($q) => $q->whereNull('deal_id')->orWhere('deal_id', '!=', $deal->id))->update(['deal_id' => $deal->id]);
                $deal->pointOffer($deal->id);
            }
            // Сделка — покупатель есть: тревога «нет покупателя» гаснет; отменили — может загореться (`Binding`).
            if ($moved) {
                Binding::touch(Offer::find($deal->offer_id));
            }
            // Сделка ушла — дело «Укажите покупателя» гаснет (10.11.2026, `AskBuyer`).
            if ($moved && $deal->buyer_asked_at && ! $deal->isActive()) {
                $deal->forceFill(['buyer_asked_at' => null])->saveQuietly();
            }
        });
    }

    /** Есть ли ещё прежняя колонка `deals.state` (снимается следующей выкладкой). */
    private static ?bool $legacyState = null;

    /**
     * Состояние условий — не своё поле, а факты (16.11.2026, начальник: «сделка — лишь состояние предложения»; миграция
     * `deal_state_into_offer`): закрыта ли (`closed_at`), сорвалась ли (`cancelled_at`), параллельная ли (`parallel`).
     * Идёт — не закрыта и не параллельная; завершена — закрыта и не сорвалась (по ней машину продали); отменена —
     * сорвалась. Текущие условия машины — `offers.deal_id` (идущие или те, по которым продали). Слово и сравнения —
     * `DealState`, как раньше; писать — только дверями ниже (`finish`, `cancel`, `activate`, `makeParallel`).
     */
    protected function state(): Attribute
    {
        return Attribute::get(fn () => match (true) {
            $this->cancelled_at !== null => DealState::Cancelled,
            $this->closed_at !== null => DealState::Done,
            (bool) $this->parallel => DealState::Parallel,
            default => DealState::Active,
        });
    }

    /** SQL «сделка идёт» — у таблицы `deals` и у её псевдонима `d`. */
    public const ACTIVE_SQL = '(deals.closed_at is null and not deals.parallel)';

    public const ACTIVE_D = '(d.closed_at is null and not d.parallel)';

    /**
     * Указатель машины (`offers.deal_id`) — запросом мимо области демо-записей: часы и консоль (`demo:seed`) их не видят,
     * а указатель демо-машине нужен так же.
     */
    private static function pointer(int $offerId): Builder
    {
        return Offer::withoutGlobalScopes()->whereKey($offerId);
    }

    /** Указатель машины в памяти — вслед за базой (у загруженного предложения), не делая его несохранённым. */
    private function pointOffer(?int $id): void
    {
        if ($this->relationLoaded('offer') && $this->offer) {
            $this->offer->setAttribute('deal_id', $id)->syncOriginalAttribute('deal_id');
        }
    }

    /** Продали по этим условиям: закрыть, оставив текущими (`closed_at` — дата продажи, если её знают). */
    public function finish(?\DateTimeInterface $at = null): void
    {
        DB::transaction(function () use ($at) {
            $this->forceFill(['closed_at' => $at ?? $this->closed_at ?? now(), 'cancelled_at' => null, 'parallel' => false])->save();
            self::pointer($this->offer_id)->update(['deal_id' => $this->id]);
        });
        $this->pointOffer($this->id);
    }

    /** Условия сорвались: закрыть отменой и снять с машины (параллельную — просто закрыть). */
    public function cancel(): void
    {
        DB::transaction(function () {
            $this->forceFill(['closed_at' => now(), 'cancelled_at' => now()])->save();
            self::pointer($this->offer_id)->where('deal_id', $this->id)->update(['deal_id' => null]);
        });
        if ($this->relationLoaded('offer') && (int) $this->offer?->deal_id === (int) $this->id) {
            $this->pointOffer(null);
        }
    }

    /** Снова идёт: открыть и сделать текущими условиями машины (переоткрыть, взять параллельную в работу). */
    public function activate(array $fill = []): void
    {
        $this->forceFill(['closed_at' => null, 'cancelled_at' => null, 'parallel' => false, ...$fill])->save();
    }

    /** Стала параллельной (главную переключили на другого менеджера, `TakeParallel`). */
    public function makeParallel(array $fill = []): void
    {
        $this->forceFill(['closed_at' => null, 'cancelled_at' => null, 'parallel' => true, ...$fill])->save();
    }

    /** Идущие: не закрыты и не параллельные. */
    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('deals.closed_at')->where('deals.parallel', false);
    }

    /** Живые для менеджера: идущие и параллельные. */
    public function scopeLive(Builder $q): Builder
    {
        return $q->whereNull('deals.closed_at');
    }

    /** Параллельные, что идут. */
    public function scopeParallel(Builder $q): Builder
    {
        return $q->whereNull('deals.closed_at')->where('deals.parallel', true);
    }

    /** Завершённые: закрыты и не сорвались. */
    public function scopeDone(Builder $q): Builder
    {
        return $q->whereNotNull('deals.closed_at')->whereNull('deals.cancelled_at');
    }

    /** Отменённые. */
    public function scopeCancelled(Builder $q): Builder
    {
        return $q->whereNotNull('deals.cancelled_at');
    }

    /** Не отменённые: идущие, параллельные и завершённые. */
    public function scopeNotCancelled(Builder $q): Builder
    {
        return $q->whereNull('deals.cancelled_at');
    }

    /** Идущие или завершённые — прежнее `state in ('active', 'done')`. */
    public function scopeHeld(Builder $q): Builder
    {
        return $q->whereNull('deals.cancelled_at')->where(fn ($w) => $w->whereNotNull('deals.closed_at')->orWhere('deals.parallel', false));
    }

    /** Кто выбрал схему (`scheme_by`, 03.11.2026). */
    public function schemeAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheme_by');
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
        return $q->done()->whereNotNull('deals.auction_at');
    }

    public function auctionBuyer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'buyer_party_id');
    }

    /** Плательщик, выбранный своей стороной (`SetDealPayer`); пусто — по умолчанию, см. `payerParty`. */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'payer_party_id');
    }

    /**
     * Кто платит по счёту сделки за ТС (ДКП ПРАЙМ / Р.О.С., гараж): выбранная сторона (`deals.payer_party_id`), иначе менеджер или
     * покупатель по договору (`DealContract::managerPays`, `$sale`). У подбора плательщика нет (18.11.2026). Без менеджера — null.
     */
    public function payerParty(bool $sale = false): ?Party
    {
        if ($this->payer_party_id) {
            return $this->payer;
        }
        $user = $this->payerUser($sale);

        return $user ? Party::forUser($user) : null;
    }

    /**
     * Плательщик по умолчанию человеком (без выбранной стороны): у счёта за ТС ПРАЙМ — покупатель по договору, если платит не
     * менеджер, иначе менеджер. У подбора плательщика нет (18.11.2026, владелец: «только для выставления договора счёта (ДКП)»):
     * его сторона — менеджер (`SyncDealInvoices::selection`).
     */
    public function payerUser(bool $sale = false): ?User
    {
        if ($this->payer_party_id || ! $this->buyer) {
            return null;
        }
        $contract = $this->contract;

        return $sale && $contract?->buyer && ! $contract->managerPays() ? $contract->buyer : $this->buyer;
    }

    /** Кому платят услуги подбора — ООО «ПРАЙМ» (по умолчанию) или ООО «Р.О.С.» (`deals.fee_seller`, `SetFeeSeller`, 16.11.2026). */
    public function feeSeller(): Seller
    {
        return Seller::tryFrom((string) $this->fee_seller) ?? Seller::Prime;
    }

    /** Платит сам менеджер (его сторона), а не его покупатель. */
    public function managerPays(): bool
    {
        if ($this->payer_party_id) {
            return $this->buyer !== null && $this->payer_party_id === $this->buyer->party_id;
        }

        return ! $this->contract?->buyer_user_id || $this->contract->managerPays();
    }

    /** Сделка супер-покупателя: он покупает сам, без вознаграждения; покупатель и плательщик по договору — он же. */
    public function isDirect(): bool
    {
        return (bool) $this->buyer?->isSuperBuyer();
    }

    /** Что админ показал менеджеру из писем страховой (`Disclosure`), новое сверху: блок сделки и контакт получения. */
    public function disclosures(): HasMany
    {
        return $this->hasMany(Disclosure::class)->orderByDesc('id');
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
    // Доп. просьба (18.11.2026, `ExtraAsk`): пока ждём поставщика или показываем ответ — хода его нет; показали — его ход.
    public const MANAGER_TURN_SQL = "(exists (select 1 from reprice_asks a where a.deal_id = deals.id and a.state in ('pending', 'countered'))
        or ((not ".ExtraAsk::DEAL_BEFORE_SQL.') and (('.self::ACTIVE_SQL." and deals.signing_asked_at is not null and deals.signing_at is null and coalesce(jsonb_array_length(deals.signing_wish->'slots'), 0) = 0)
        or deals.buyer_asked_at is not null or exists (select 1 from requirements r where r.deal_id = deals.id and r.done_at is null)
        or (".self::ACTIVE_SQL." and exists (select 1 from offer_positions p join workflow_stages s on s.id = p.stage_id where p.offer_id = deals.offer_id
            and p.track = 'sale' and coalesce(p.waits_for, s.waits_for) = 'manager'))))
        or (".self::ACTIVE_SQL.' and '.ExtraAsk::DEAL_MANAGER_SQL.'))';

    /**
     * Что сделать менеджеру по идущей сделке (27.10.2026, владелец: «глянь так же с точки зрения менеджера»): просьба шага,
     * а без неё — ход его словами («Укажите покупателя», имя шага для менеджера). Просьба «Оплатите», а счета уже
     * оплачены (маршрут не догнал), — не дело. Одно место для строки, раздела «Ваш ход» и бейджа.
     *
     * `kind` — чем ответить (`ManagerAsk`): `ask` — просьба шага, `reprice` — цена, `buyer` / `passport` / `price` — пробел
     * счёта, `step` — шаг без просьбы. Оплата поставщику самим менеджером — словами задачи шага: «Оплатить страховой 890 000 ₽».
     *
     * @return array{title: string, due: ?Carbon, kind: string}|null
     */
    public function managerTask(): ?array
    {
        // Параллельная — ход только её просьбой: шаг машины у неё чужой (`Parallels`).
        if ($this->isParallel()) {
            return ($req = $this->openRequirement) ? ['title' => $req->title, 'due' => $req->due_at, 'kind' => 'ask'] : null;
        }
        if (! $this->isActive()) {
            return null;
        }
        $position = $this->offer?->position();
        // Выросла закупочная — сначала ответ про цену (28.10.2026, `RepriceAsk`).
        if ($due = RepriceAsk::dealDue()->get($this->offer_id)) {
            // Гаражная — согласие на новую закупочную, без цены (`RepriceAsk::agreesOnly`, 17.11.2026).
            return ['title' => $this->isGarage() ? 'Ответить про новую закупочную' : 'Назвать новую цену', 'due' => $due, 'kind' => 'reprice'];
        }
        // Доп. просьба поставщику держит сделку (18.11.2026, `ExtraAsk`): до показа ответа — ждём, показали — решить, берёт ли.
        if ($ask = ExtraAsk::holding($this)) {
            return $ask->asks_manager && $ask->phase() === ExtraAsk::MANAGER
                ? ['title' => ExtraAskDecisionNotice::question($ask), 'due' => null, 'kind' => 'extra'] : null;
        }
        $req = $this->openRequirement;
        if ($req) {
            if ($position?->stage->isPayStep() && $this->payStepPaid()) {
                return null;
            }
            // Просит подписанный договор, а договора ещё нет — не выбран покупатель или нет его данных (11.11.2026, владелец по
            // Tucson Макарова: «приложите подписанный договор, а он даже реквизиты не указал — что подписывать»): дело — покупатель.
            if ($gap = $this->contractBuyerTask($req)) {
                return $gap + ['due' => $req->due_at];
            }
            // Поставщик не смог в названное время и предложил своё (11.11.2026, `Signing`) — словами, что выбирать.
            if ($this->signing_slots && Signing::picks($req->stage)) {
                return ['title' => 'Поставщик предложил другое время для подписания', 'due' => $req->due_at, 'kind' => 'ask'];
            }
            $pays = $req->stage && $this->paysSupplierOn($req->stage) && ($label = $this->schemeOf()->payLabel());

            // Дело — действием, как заголовок шага: «Оплатить поставщику 890 000 ₽» (16.11.2026, без «Оплатите»).
            $label = $pays ? $label : null;

            return ['title' => $pays ? trim($label.' '.($this->supplierPayment() ? Money::rub($this->supplierPayment()) : '')) : $req->title,
                'due' => $req->due_at, 'kind' => 'ask'];
        }
        // Покупатель — с согласия (10.11.2026, владелец: «реквизиты требовать сразу, как взял в работу, а не когда страховая
        // запросила»): шаг ждёт поставщика, а дело менеджера — покупатель (`AskBuyer`, `buyer_asked_at`).
        if ($this->buyer_asked_at) {
            // Оформляет на себя (13.11.2026) — не хватает только его паспорта.
            return $this->contract?->buyer_user_id && $this->selfBuy()
                ? ['title' => 'Приложить свой паспорт', 'due' => null, 'kind' => 'passport']
                : ['title' => 'Указать покупателя', 'due' => null, 'kind' => 'buyer'];
        }
        // Поставщик готовит договор — когда покупатель сможет подписать, день и время (11.11.2026, `Signing::wishDue`).
        if (Signing::wishDue($this)) {
            return ['title' => 'Когда покупатель сможет подписать договор?', 'due' => null, 'kind' => 'signing'];
        }
        // Гаражная — дело только просьбой (как бейдж «Гаража», `Nav::asks`): «Укажите цену продажи» у неё — сама продажа
        // из гаража, она идёт неделями и в «Ваш ход» не встаёт (02.11.2026: 16 таких заслоняли настоящие дела).
        if ($this->isGarage()) {
            return null;
        }
        if (! $position || $position->waitsFor() !== WaitsFor::Manager || $position->awaitsInvoice()) {
            return null;
        }
        $gap = $position->stage->isPayStep() && $this->hasContract() ? $this->invoiceGap() : null;

        return ['title' => match ($gap) {
            'buyer' => 'Указать покупателя',
            'passport' => $this->selfBuy() ? 'Заполнить свои данные' : 'Нужны реквизиты',
            'price' => 'Указать цену продажи',
            default => $position->stage->managerTitle($this),
        }, 'due' => $position->deadline_at, 'kind' => in_array($gap, ['buyer', 'passport', 'price'], true) ? $gap : 'step'];
    }

    /**
     * Шаг просит подписанный договор (документом), а договора без покупателя нет: что сначала — `title` и `kind`
     * (`buyer` / `passport`, чем ответить — `ManagerAsk`). Иначе null.
     *
     * @return array{title: string, kind: string}|null
     */
    public function contractBuyerTask(?Requirement $req = null): ?array
    {
        $req ??= $this->openRequirement;
        if (! $req || $req->asks !== Asks::Document || ! $this->hasContract()
            || ! preg_match('/договор/iu', $req->stage?->name.' '.$req->title)) {
            return null;
        }

        return match ($this->buyerGap()) {
            null => null,
            'Покупатель не выбран' => ['title' => 'Указать покупателя', 'kind' => 'buyer'],
            'Нет скана паспорта покупателя' => ['title' => 'Приложить паспорт покупателя', 'kind' => 'passport'],
            'Нет паспорта менеджера', 'Нет скана паспорта менеджера' => ['title' => 'Приложить свой паспорт', 'kind' => 'passport'],
            default => ['title' => 'Нужны реквизиты', 'kind' => 'buyer'],
        };
    }

    /** Договор купли-продажи: собственника (ДКП) или ПРАЙМ с покупателем менеджера (`DealContract`). */
    /**
     * С какого момента идёт эта продажа машины: начало первой сделки цепочки переприёмов (`from_deal_id`, `CarryDeal`), иначе своё.
     * Переоценка, разобранная при прежней сделке, — и этой (26.11.2026).
     */
    public function since(): \Illuminate\Support\Carbon
    {
        $first = $this;
        for ($i = 0; $first->from_deal_id && $i < 10 && ($up = self::find($first->from_deal_id)); $i++) {
            $first = $up;
        }

        return $first->created_at;
    }

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
     * Покупателя уже спрашиваем у менеджера (14.11.2026, владелец: 2611053661 — у Совкомбанка блока «Покупатель» не было): после
     * согласования, а у вендора по комиссии (Совкомбанк) — сразу: согласовывать с поставщиком нечего, `agreed_at` у него
     * встаёт лишь после «Автомобиль забрал» (`invoiceNotYet`).
     */
    public function buyerOpen(): bool
    {
        return $this->agreed() || $this->isCommission();
    }

    /**
     * Счёт сделки встал, а менеджеру его ещё не отправили (`ShowToManager`, 16.11.2026) — менеджеру «Счёт готовится». До того
     * (с 09.10.2026) счёт ждал ответа поставщика, и фраза говорила, после чего встанет; теперь он встаёт сразу с реквизитами.
     */
    public function invoiceNotYet(): ?string
    {
        return $this->hasManagerInvoice(sent: false) && ! $this->hasManagerInvoice() ? 'готовится' : null;
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

    public const HOLD_PAY = 'вывоз после оплаты';

    public const HOLD_AGREE = 'вывоз после согласия поставщика';

    /**
     * Почему забирать рано — словом для пути и вывоза: «вывоз после согласия поставщика», «вывоз после оплаты» (17.11.2026,
     * владелец: «непонятно, что значит „после оплаты“»); null — можно.
     */
    public function pickupHold(): ?string
    {
        $vendor = $this->offer?->vendor;
        if ($vendor?->deal_format === DealFormat::Wholesale && $this->agreed_at === null) {
            return self::HOLD_AGREE;
        }
        if ($this->prepaid_at !== null || ! $vendor?->pay_before_pickup) {
            return null;
        }

        // Маршрута продажи у вендора нет (Альфа СПб завели без него) — `prepaid_at` ставить некому: забирать, когда
        // счета сделки оплачены, а не никогда.
        return ! $vendor->workflow(Track::Sale)?->is_active && $this->fullyPaid() ? null : self::HOLD_PAY;
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
        return self::active()
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
     * Покупатель менеджера платит закупочную страхователю сам по ДКП, менеджер нам — подбор: разницу минус своё
     * вознаграждение (05.10.2026, Т-Страхование).
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

    /**
     * Сколько покупатель платит нашему юрлицу по счёту за ТС и ДКП (ПРАЙМ / Р.О.С. не гаража) — вписывается (25.11.2026, владелец по
     * 7806/046/03674/26: «должен быть ввод цены, которая на счёт, а не строго закупочная»; Р.О.С. 1 700 000 при закупочной 1 671 000).
     * Цена продажи = счёт + подбор ссылкой + вознаграждение + наличные мимо счёта, ссылка — остаток (`linkDue`). Не вписано
     * (`deal_contracts.car_price` пусто) — умолчание: Р.О.С. — закупочная, ПРАЙМ — всё в счёте (24.11.2026, владелец: «по умолчанию
     * счёт единый»; у Совкомбанка ссылка — только через «Вы уверены?»). Вписанное — не меньше закупочной и не больше цены минус
     * вознаграждение и наличные (цену уменьшили позже — ссылка не уходит в минус). null — денег не знаем.
     */
    public function carPrice(): ?int
    {
        if (! $this->moneyKnown()) {
            return null;
        }
        $set = $this->contract->car_price;
        if ($set === null) {
            return $this->carDefault();
        }

        return max((int) $this->cost, min($this->carCap(), (int) $set));
    }

    /** Счёт за ТС по умолчанию: Р.О.С. — закупочная, ПРАЙМ — цена минус вознаграждение и наличные. */
    public function carDefault(): int
    {
        return $this->seller() === Seller::Ros ? (int) $this->cost : $this->carCap();
    }

    /** Сколько покупатель платит нам всего, кроме вознаграждения менеджера и наличных мимо счёта: счёт за ТС и ссылка подбора. */
    public function carCap(): int
    {
        return max(0, (int) $this->amount - (int) $this->commission - (int) $this->contract?->cash_part);
    }

    /** Подбор ссылкой у ПРАЙМ / Р.О.С. — что не легло в счёт за ТС; null — денег не знаем. */
    public function linkDue(): ?int
    {
        $car = $this->carPrice();

        return $car === null ? null : max(0, $this->carCap() - $car);
    }

    /** Подбор отдельной ссылкой: у ПРАЙМ / Р.О.С. в счёт за ТС легло не всё (`carPrice`). */
    public function splitsSelection(): bool
    {
        return ($this->linkDue() ?? 0) > 0;
    }

    /** Счёт за ТС можно делить с подбором (шкала «в счёт | ссылкой» в шторке денег): ПРАЙМ или Р.О.С., не гараж, деньги известны. */
    public function canSplitSelection(): bool
    {
        return $this->moneyKnown();
    }

    /** Счёт ПРАЙМ / Р.О.С. за ТС не гаража с известными ценой, закупочной и договором — его можно делить с подбором. */
    private function moneyKnown(): bool
    {
        return $this->isPrime() && ! $this->isGarage() && $this->cost !== null && $this->amount !== null && $this->contract !== null;
    }

    /**
     * Доп. запрос до «Беру в работу» (20.11.2026, владелец: Корабельников по всем грузовым Альфы требует запросить ПТС и осмотр до
     * вопроса, берёт ли он): у менеджера признак `consent_precheck`, ТС грузовое (и прицеп), вендор Альфа, этап — перед «Согласием
     * менеджера», доп. запросов по сделке ещё не было. Тогда в шаге вместо «Спросить менеджера» — «Отправить доп. запрос» и «Сразу
     * спросить менеджера».
     */
    public function needsPrecheck(?Stage $stage = null): bool
    {
        if (! $this->isActive() || ! $this->buyer?->consent_precheck || ! $this->offer?->vendor?->pay_before_pickup || ! $this->offer->isHeavy()) {
            return false;
        }
        $stage ??= $this->offer->position(Track::Sale)?->stage;
        if (! $stage || ! $stage->exitsFor(Actor::Staff, $this)->contains(fn (Outcome $x) => $x->to?->asksConsent())) {
            return false;
        }

        return ! ExtraAsk::where('deal_id', $this->id)->exists();
    }

    /** Галки доп. запроса до согласия: «Запросить ПТС» и «Согласовать осмотр» — ид шаблонов по названию, `&pick[]=…`. */
    public static function precheckPicks(): string
    {
        return collect(Template::where('kind', 'request')->whereIn('name', ['Запросить ПТС', 'Согласовать осмотр'])->pluck('id'))
            ->map(fn ($id) => '&pick[]='.$id)->implode('');
    }

    /** Менеджер платит нам подбор по ссылке — у ДКП и «страховой напрямую»; гаражная — не по цене, а долей (`share`). */
    public function paysSelection(): bool
    {
        return ! $this->isGarage() && $this->schemeOf()->paysSelection();
    }

    /**
     * Менеджер сам платит поставщику или собственнику по их счёту — «Поставщику» и ДКП, и у гаражной «платит менеджер»
     * (19.11.2026, владелец по 7925/046/01102/26: Сергей берёт себе, Альфа прислала счёт — в шаге «Счёт не выставлен» и
     * «Вписать услуги подбора», счёт Альфы ему не показать). Подбор у гаражной — долей (`share`), не `paysSelection`.
     */
    public function paysSupplierSelf(): bool
    {
        return $this->schemeOf()->paysSelection() && (! $this->isGarage() || $this->isGarageManager());
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

    /**
     * Договор поставщик отдаёт только после оплаты (16.11.2026, владелец: «по Альфе договор не скидывают, пока счёт не
     * оплатят — нет смысла писать, что ждём договор, и прикладывать подписанный»): вендор с оплатой до вывоза, а поставщик
     * оплату ещё не подтвердил (`prepaid_at`, тот же признак, что `pickupHold`).
     */
    public function contractAfterPay(): bool
    {
        return $this->prepaid_at === null && (bool) $this->offer?->vendor?->pay_before_pickup;
    }

    /**
     * Единственный ход менеджера на шаге — его берёт платёжка сама (16.11.2026, владелец: «как-то очень удобно, менеджеры не
     * соображают, заколебали в чат платёжки кидать»): приложил файл на шаге оплаты — шаг сделан, второй кнопки «Оплатил,
     * документ приложен» нет. Так же «Это чек об оплате» под файлом в чате.
     */
    /**
     * Ответ, который делает приложенная платёжка на шаге оплаты (16.11.2026, владелец: «сразу должна считаться платёжкой, без
     * дебильного этапа»): «Оплатил, документ приложен» (`PaymentProofPdf::EXITS`), иначе единственный ход менеджера.
     */
    public function proofMove(?Stage $stage): ?Outcome
    {
        if (! $stage?->isPayStep()) {
            return null;
        }

        return $this->managerExits($stage)->first(fn (Outcome $e) => in_array($e->label, PaymentProofPdf::EXITS, true)) ?? $this->soleMove($stage);
    }

    public function soleMove(?Stage $stage): ?Outcome
    {
        $moves = $this->managerExits($stage)->reject(fn (Outcome $e) => $e->isRetreat() || self::closes($e))->values();

        return $moves->count() === 1 ? $moves->first() : null;
    }

    /**
     * Платёжка ушла, поставщик (собственник) оплату ещё не подтвердил: просьба шага оплаты, с которой сделка перешла на
     * нынешний этап. В неё до подтверждения можно доложить файл и убрать лишний (`Cabinet\DealController::upload`), на странице
     * — карточка «Оплачено поставщику» (`cabinet.deals.step`).
     */
    public function sentPaymentProof(): ?Requirement
    {
        if (! $this->isLive() || ! $this->paysSupplierSelf() || $this->prepaid_at !== null || $this->openRequirement) {
            return null;
        }
        $stage = $this->offer?->position(Track::Sale)?->stage;
        $proof = PaymentProofPdf::requirement($this);

        return $stage && $proof?->stage?->isPayStep() && $proof->stage->exits->contains(fn (Outcome $e) => $e->to_stage_id === $stage->id) ? $proof : null;
    }

    /**
     * Для менеджера сделка закрыта (25.11.2026, владелец по 891799/2026: «для Армена всё, сделка закрыта считай; оплата поставщику —
     * наша задача»): идёт, а у менеджера в ней ничего не осталось — ни текущий шаг, ни один шаг впереди до закрытия (`FastForward`
     * главной дорогой) не ждёт его, нет его дела и просьбы, наши счета оплачены, машину забрал или её ему не везут. На сайте — как
     * закрытая; у нас идёт, пока не пройдём свои шаги.
     */
    public function doneForManager(): bool
    {
        if (! $this->isActive() || ! $this->buyer_id || $this->isParallel() || $this->isAuction()) {
            return false;
        }
        $stage = $this->offer?->unsetRelation('positions')->position(\App\Workflow\Track::Sale)?->stage;
        if (! $stage || $stage->awaitsManager($this) || $this->openRequirement()->exists() || $this->managerTask() !== null) {
            return false;
        }
        $run = FastForward::from($this, $stage, fn () => false);
        if (! $run->closes() || collect($run->passed)->contains(fn (Stage $s) => $s->awaitsManager($this))) {
            return false;
        }

        // Договор с ним есть, а подписанного нет — приложить его тоже он.
        if ($this->hasContract() && ! $this->contractSigned() && \App\Offers\Documents\SignedContractPdf::files($this)->isEmpty()) {
            return false;
        }
        // Оригиналы у него (Р.О.С. или ПРАЙМ, шаг «Оригиналы», 26.11.2026) — прислать их тоже он.
        if (\App\Offers\Originals::managerOwes($this)) {
            return false;
        }
        // Машину забирает он сам и ещё не забрал — его дело, и у гаражной тоже (`closeBlocker` гаражную не держит).
        $pickup = $this->offer?->pickup();
        if ($pickup && ! $pickup->taken() && $pickup->channel === Channel::Manager) {
            return false;
        }

        return \App\Offers\Actions\MarkSoldDeal::unpaid($this)->isEmpty() && $this->closeBlocker() === null;
    }

    /** Отметка `manager_done_at` по `doneForManager`: ставится и снимается. Да — изменилась. */
    public function syncManagerDone(): bool
    {
        $done = $this->doneForManager();
        if ($done === ($this->manager_done_at !== null)) {
            return false;
        }
        \Illuminate\Support\Facades\DB::table('deals')->where('id', $this->id)->update(['manager_done_at' => $done ? now() : null]);
        $this->manager_done_at = $done ? now() : null;

        return true;
    }

    /** Идёт и для менеджера тоже: сайт («Сделки», счётчик) — без закрытых для него (`manager_done_at`). */
    public function scopeLiveForManager(Builder $q): Builder
    {
        return $q->whereNull('deals.closed_at')->whereNull('deals.manager_done_at');
    }

    /** Город подписания договора у поставщика (25.11.2026, `signing_settlement_id`). */
    public function signingCity(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Cars\Settlement::class, 'signing_settlement_id');
    }

    /** Подписание в Москве — по записи; город не указан — как раньше, тоже по записи. Остальные города — без записи. */
    public function signsByAppointment(): bool
    {
        return $this->signing_settlement_id === null || $this->signing_settlement_id === \App\Park\City::main();
    }

    /**
     * Нужен ли город подписания (25.11.2026, владелец: у Альфы запись только в Москве, в других городах — в рабочее время без
     * записи; «на этапе, когда прикладывают документ об оплате, обязательное указание города, без него не отправляется»): в маршруте
     * есть запись на подписание (`Signing::awaitsSlots`), сделка на оплате или ещё до записи, договор не подписан, город не указан.
     * `$stage` — шаг, на котором спрашиваем (по умолчанию текущий).
     */
    public function needsSigningCity(?Stage $stage = null): bool
    {
        if ($this->signing_settlement_id !== null || ! $this->isActive() || $this->contractSigned() || $this->isAuction() || $this->isGarage()) {
            return false;
        }
        $stage ??= $this->offer?->position(\App\Workflow\Track::Sale)?->stage;
        if (! $stage || ! ($stage->isPayStep() || \App\Offers\Signing::beforeBooking($stage))) {
            return false;
        }

        return \App\Offers\Signing::routeBooks($stage);
    }

    /** Документ об оплате без города подписания не принимается (25.11.2026): шаг оплаты, а город нужен — текст отказа. */
    public function signingCityGap(?Stage $stage): ?string
    {
        return $stage?->isPayStep() && $this->needsSigningCity($stage) ? 'Сначала укажите город подписания' : null;
    }

    public function paysSupplierOn(?Stage $stage): bool
    {
        return $stage !== null && $this->paysSupplierSelf() && $stage->isPayStep()
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

    /**
     * Пропуск на выдачу этой машины с парковки (страница сделки менеджера и выдача в CRM — одна выборка, 11.11.2026):
     * живой, а без него — погашенный самой парковкой (покупатель отказался, выдача снята), чтобы сказать почему; погашенный
     * сменой получателя (`SyncParkRelease::CHANGED`) не в счёт — новый выдан или будет.
     *
     * @return array{0: ?Pass, 1: ?Pass} живой, погашенный парковкой
     */
    public function releasePass(Vehicle $vehicle): array
    {
        $passes = $this->passes()->where('vehicle_id', $vehicle->id);
        $pass = (clone $passes)->whereNull('revoked_at')->latest('id')->first();

        return [$pass, $pass ? null : $passes->whereNotNull('revoked_at')
            ->where('revoke_reason', '!=', Actions\SyncParkRelease::CHANGED)->latest('id')->first()];
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
            return $this->garage_payer === GaragePayer::Manager ? 'без оплаты, счёт '.$this->seller()->short().' при продаже' : 'поставщику платим мы';
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
    /**
     * Гаражная «платит менеджер» ещё не продана (17.11.2026, владелец: в «Менеджер должен нам» стояли 44 такие — «ещё не платили»,
     * а платить им не за что до продажи): цену продажи он не назвал, сделка на шаге оплаты. Её место — «Гараж» (`DealTabs`),
     * строка — «продаёт» (`Position::status`). Продал («Продаю») — счёт, срок и «Менеджер должен нам», как было.
     */
    public function sellsFromGarage(?Stage $stage = null): bool
    {
        // Деньги за неё уже пришли (`paid_in_at`) — значит продал, просто продажу не записали: «Деньги пришли: записать продажу».
        if (! $this->namesBuyer() || $this->amount !== null || $this->paid_in_at !== null) {
            return false;
        }
        $stage ??= $this->offer?->position(Track::Sale)?->stage;

        return (bool) $stage?->isPayStep();
    }

    public function namesBuyer(): bool
    {
        // «Взяли под себя» (платим мы, менеджера нет) — не она: там машину оплачиваем и оформляем на ПРАЙМ сами.
        return $this->garage_payer === GaragePayer::Manager && $this->isCommission();
    }

    /** Договор собираем мы: ДКП собственника или ПРАЙМ (в том числе с менеджером гаражной); «страховой» — нет. */
    public function hasContract(): bool
    {
        // Покупатель с гаражом (20.10.2026 — только счёт на его цену, договора не было): с 24.11.2026 у ПРАЙМ — договор на его
        // клиента (владелец по Voyah Free 779934/2026: «счёт и договор должен быть не на Ананьева, у него свой покупатель»), цена —
        // его цена гаража (`DealContract::priceFor`); данные клиента вносим мы в CRM, держателя не спрашиваем (`needsBuyer`).
        if ($this->forGarageBuyer()) {
            return $this->isPrime() || $this->isDkp();
        }

        return $this->isDkp() || ($this->isPrime() && (! $this->isGarage() || $this->isGarageManager() || $this->namesBuyer()));
    }

    /**
     * Шаг просит у менеджера подписанный договор, а наш договор ему ещё не отправлен (27.11.2026, владелец по 495181/2026:
     * «почему написали „Нужно приложить договор“, если он нами ещё не отправлен?»): подписывать нечего — ход наш, просьбы нет,
     * её заведёт «Отправить менеджеру» (`PassTurnOnContract`). Как этап оплаты без отправленного счёта.
     */
    public function awaitsOurContract(Stage $stage): bool
    {
        return $stage->asks === Asks::Document && $stage->aboutContract() && $this->isActive() && $this->hasContract()
            && ! $this->contractSigned() && ! DealContract::for($this)->shownToManager() && SignedContractPdf::files($this)->isEmpty();
    }

    /**
     * Собираем ли покупателя по договору: договор есть (`hasContract`) или сделка стоит на шаге «Данные покупателя»
     * (`Asks::Buyer`, 03.11.2026, Альфа «страховой напрямую»: счёт поставщик выставит на покупателя — договора у нас нет,
     * а данные нужны). Одна дверь для формы покупателя у менеджера и в CRM.
     */
    public function collectsBuyer(): bool
    {
        // С согласия — и у «страховой напрямую» (10.11.2026): покупатель нужен поставщику, когда тот попросит реквизиты.
        // С открытия сделки (24.11.2026, владелец: блок покупателя «изначально, как только подтверждение приняли»; до того — с согласия).
        return $this->hasContract() || $this->asksBuyerData() || $this->buyerAhead();
    }

    /**
     * Покупателя дать менеджеру (10.11.2026): договор собираем мы (ДКП, ПРАЙМ) или впереди шаг «Нет реквизитов» (`buyerAhead`).
     * Супер-покупателю, аукциону и параллельной — нет: покупатель у них свой или его нет; гаражной — только «платит менеджер» с
     * шагом покупателя впереди.
     */
    public function needsBuyer(): bool
    {
        // Договор подписан вне системы (`contractSigned`) — покупатель и его данные больше не нужны.
        if (! $this->isActive() || $this->contractSigned() || $this->forGarageBuyer() || $this->isDirect() || $this->isAuction() || $this->isParallel()) {
            return false;
        }
        // Гаражная «платит менеджер» у «страховой напрямую» (13.11.2026, Сергей Николаев берёт себе, Альфе платит сам): счёт
        // поставщик выставит на покупателя — его указывают так же; «платим мы» — на наше юрлицо, покупатель не нужен.
        if ($this->isGarage()) {
            return $this->isGarageManager() && ! $this->hasContract() && $this->buyerAhead();
        }

        return $this->hasContract() || $this->buyerAhead();
    }

    /**
     * Договор с покупателем подписан вне системы (14.11.2026, «Договор уже подписан», «Уже продана…»): покупателя, его данных
     * и паспорта не просим — `needsBuyer`, `buyerAhead`, а за ними `buyerGap`, `contractBuyerTask`, `AskBuyer`.
     */
    public function contractSigned(): bool
    {
        return $this->contract_signed_at !== null;
    }

    /** «Страховой напрямую» (Альфа): впереди по маршруту шаг `Asks::Buyer` — счёт поставщик выставит на покупателя. */
    public function buyerAhead(): bool
    {
        if ($this->contractSigned() || $this->hasContract() || $this->schemeOf()->paysCar() || $this->isGarageUs()) {
            return false;
        }
        $stage = $this->offer?->stage();
        // Письмо с реквизитами (с 18.11.2026 — сразу после запроса поставщика, без шага «Нет реквизитов», 1e0877a1): покупатель — в
        // самом шаге, его данные и идут в письмо. Без этого на шаге письма покупатель «не нужен», и дело менеджера гасло.
        if ($stage?->isRequisitesStep()) {
            return true;
        }
        $buyer = $stage ? Parallels::buyerStage($stage) : null;

        return $buyer && ($buyer->is($stage) || Parallels::rank($buyer) > Parallels::rank($stage));
    }

    /**
     * Чего не хватает от менеджера по покупателю — словами, null — всё есть или не нужно (10.11.2026): выбран ли, данные для
     * договора, скан паспорта (у ИП — банк). То же, что `requisitesGap` и `DealContract::missing` по покупателю.
     */
    public function buyerGap(): ?string
    {
        if (! $this->needsBuyer()) {
            return null;
        }
        $party = $this->contract?->buyer?->party;
        // Оформляет на себя (13.11.2026) — нужен его же паспорт, а не «покупатель».
        if ($this->contract?->buyer && $this->selfBuy()) {
            return match (true) {
                ! $party?->readyForContract() => 'Нет паспорта менеджера',
                ! $party->readyAsBuyer() => $party->buyerGap() === 'нужен банк ИП' ? 'Нет банка ИП менеджера' : 'Нет скана паспорта менеджера',
                default => null,
            };
        }

        return match (true) {
            ! $this->contract?->buyer => 'Покупатель не выбран',
            ! $party?->readyForContract() => 'Нет данных покупателя',
            ! $party->readyAsBuyer() => $party->buyerGap() === 'нужен банк ИП' ? 'Нет банка ИП покупателя' : 'Нет скана паспорта покупателя',
            default => null,
        };
    }

    /** Сделка стоит на шаге, что просит данные покупателя (`Asks::Buyer`). */
    public function asksBuyerData(): bool
    {
        // Параллельная (07.11.2026) — по своему теневому шагу.
        if ($this->isParallel()) {
            return $this->shadowStage?->asks === Asks::Buyer;
        }

        return $this->isActive() && $this->offer?->stage()?->asks === Asks::Buyer;
    }

    /**
     * На кого поставщик выставит счёт за ТС (03.11.2026, реквизиты Альфе): платим мы — ПРАЙМ, Р.О.С., гараж «платим мы» —
     * наше юрлицо; иначе («страховой напрямую») — покупатель по договору.
     */
    public function supplierInvoicee(): ?Party
    {
        if ($this->schemeOf()->paysCar() || $this->isGarageUs()) {
            return Party::seller($this->seller());
        }

        return $this->contract?->buyer?->party;
    }

    /**
     * Чего не хватает письму с реквизитами для счёта поставщика (03.11.2026, владелец: «почему кнопка „Написать поставщику“,
     * если покупатель так и не заполнен»): платим мы — реквизиты нашего юрлица; иначе — покупатель с данными и сканом. null — можно писать.
     */
    public function requisitesGap(): ?string
    {
        if ($this->schemeOf()->paysCar() || $this->isGarageUs()) {
            return Party::seller($this->seller())->filled() ? null : 'Нет реквизитов '.$this->seller()->short();
        }
        $party = $this->contract?->buyer?->party;
        // Оформляет на себя (13.11.2026) — нужен его же паспорт, а не «покупатель».
        if ($this->contract?->buyer && $this->selfBuy()) {
            return match (true) {
                ! $party?->readyForContract() => 'Нет паспорта менеджера',
                ! $party->readyAsBuyer() => $party->buyerGap() === 'нужен банк ИП' ? 'Нет банка ИП менеджера' : 'Нет скана паспорта менеджера',
                default => null,
            };
        }

        return match (true) {
            ! $this->contract?->buyer => 'Покупатель не выбран',
            ! $party?->readyForContract() => 'Нет данных покупателя',
            ! $party->readyAsBuyer() => $party->buyerGap() === 'нужен банк ИП' ? 'Нет банка ИП покупателя' : 'Нет скана паспорта покупателя',
            default => null,
        };
    }

    /**
     * Реквизиты для письма поставщику (18.11.2026, владелец: три случая шага «Письмо с реквизитами»): `ready` — всё есть,
     * «Отправить реквизиты»; `partial` — что-то внесено (паспорт, скан, ИНН), но не всё — «Отправить» с переспросом; `none` —
     * покупателя нет или у него ничего не внесено — «Запросить реквизиты» у менеджера. Платим мы (Р.О.С., ПРАЙМ) — свои
     * реквизиты: `ready` или `none` (тогда их заполняем мы, `requisitesGap`).
     */
    public function requisitesState(): string
    {
        if ($this->requisitesGap() === null) {
            return 'ready';
        }
        if ($this->schemeOf()->paysCar() || $this->isGarageUs()) {
            return 'none';
        }
        $party = $this->contract?->buyer?->party;
        if (! $party) {
            return 'none';
        }
        $any = $party->readyForContract() || $party->getMedia('passport')->isNotEmpty() || filled($party->passport) || filled($party->reg_address)
            || filled($party->inn);

        return $any ? 'partial' : 'none';
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
        // Счёт сформирован, но менеджеру ещё не отправлен — проверить и отправить (16.11.2026).
        if ($this->hasManagerInvoice(sent: false)) {
            return 'send';
        }

        return match (true) {
            // Гаражная Совкомбанка: цену продажи и покупателя называет менеджер («Продаю»), вознаграждение — мы.
            $this->namesBuyer() && $this->amount === null => 'price',
            // Покупатель с гаражом (26.11.2026): счёт и договор — по цене продажи его клиенту; нет цены или клиента — ход его.
            $this->forGarageBuyer() && ($this->amount === null || $this->commission === null) => 'price',
            $this->forGarageBuyer() && ! $this->contract?->buyer_user_id && ! $this->payer_party_id => 'buyer',
            // Ноль — услуг подбора нет (19.11.2026), не «не вписаны».
            $this->isGarageManager() && $this->share === null => 'share',
            $this->isPrime() && (! $this->isGarage() || $this->namesBuyer()) && ! $this->contract?->buyer_user_id => 'buyer',
            ($this->namesBuyer() || $this->paysSelection()) && $this->rewardPending() => 'commission',
            // Р.О.С. без реквизитов (пришлёт владелец) — счёт выставить не от кого.
            $this->seller() === Seller::Ros && ! Party::seller(Seller::Ros)->billable() => 'ros_requisites',
            // У покупателя нет того, что счёт печатает (18.11.2026: ФИО и прописка, у ИП и организации — свои поля; скан и
            // паспорт — договору): счёта ПРАЙМ нет, ход менеджера. Деньги уже пришли — счёт встаёт и так (`SyncDealInvoices::prime`).
            // Паспорт приложен и дочитан, а нужного счёту в нём не нашлось (18.11.2026, владелец: «это уже НАШ ХОД») — `unread`.
            $this->isPrime() && ! $this->invoiceReady() && $this->credited() <= 0 => $this->invoiceUnread() ? 'unread' : 'passport',
            default => 'invoice',
        };
    }

    /** Счёта нет, и ход за менеджером: не указал покупателя (ПРАЙМ), цену продажи (гаражная Совкомбанка) или паспорт покупателя. */
    public function managerGap(): bool
    {
        return in_array($this->invoiceGap(), ['buyer', 'price', 'passport'], true);
    }

    /** Покупатель счёта (как у `passportReady`); нет — null. */
    public function invoiceBuyer(): ?User
    {
        return $this->isGarage() && ! $this->namesBuyer() ? ($this->isGarageManager() ? $this->buyer : null) : $this->contract?->buyer;
    }

    /**
     * Что не даёт сформировать счёт за ТС — поля покупателя, что счёт печатает (`Party::invoiceGaps`); «Сформировать без
     * прописки» у договора снимает прописку. Пусто — можно.
     *
     * @return array<string, string>
     */
    public function invoiceGaps(): array
    {
        $buyer = $this->invoiceBuyer();

        return $buyer ? Party::forUser($buyer)->invoiceGaps(withoutReg: $this->contract?->invoice_without_reg_at !== null) : [];
    }

    public function invoiceReady(): bool
    {
        return $this->invoiceGaps() === [];
    }

    /** Счёту не хватает того, что скан паспорта должен был дать, а паспорт приложен и дочитан (`Party::unreadFields`). */
    public function invoiceUnread(): bool
    {
        $buyer = $this->invoiceBuyer();

        return $buyer && array_intersect_key($this->invoiceGaps(), Party::forUser($buyer)->unreadFields()) !== [];
    }

    /**
     * Наше дело по документам покупателя, когда у шага своего нет (18.11.2026, владелец): паспорт приложен, а нужного счёту в нём
     * нет — «Данные не распознались» во «Отправить счёт» (`gap:unread`); счёту хватает, договору нет — та же группа во «Отправить
     * договор» (`contract:unread`); договор готов и не отправлен — «Счёт оплачен» (красным) или «Счёт не оплачен». Дело —
     * в виде `Position::todo`. Нет — null.
     *
     * @return array{key: string, title: string, rank: int, since: ?Carbon, button: ?array}|null
     */
    public function paperTodo(): ?array
    {
        if (! $this->isActive() || ! $this->hasContract() || ! $this->buyer_id || $this->isAuction() || ! $this->contract?->buyer_user_id) {
            return null;
        }
        $contract = $this->contract;
        $fill = ['label' => 'Внести данные', 'href' => '/offers/'.$this->offer->number.'?buyer=1#invoice-buyer'];
        $todo = fn (string $key, string $title, int $rank, ?array $button, $since = null) => ['key' => $key, 'title' => $title, 'rank' => $rank,
            'since' => $since ?? $contract->updated_at, 'button' => $button];
        if ($this->invoiceGap() === 'unread') {
            return $todo('gap:unread', 'Данные не распознались', 1, $fill);
        }
        if ($contract->buyer && Party::forUser($contract->buyer)->unreadFields()) {
            return $todo('contract:unread', 'Данные не распознались', 1, $fill);
        }
        if ($contract->shown_at || ! DealContract::for($this)->sendable() || SignedContractPdf::files($this)->isNotEmpty()) {
            return null;
        }
        $sale = $this->invoices->first(fn (Invoice $i) => $i->direction === 'issued' && $i->kind === ChargeKind::Sale && $i->state !== InvoiceState::Void);
        $paid = $sale?->state === InvoiceState::Paid;

        return $todo($paid ? 'contract:paid' : 'contract:unpaid', $paid ? 'Счёт оплачен' : 'Счёт не оплачен', $paid ? 2 : 3,
            ['label' => 'Отправить договор', 'post' => '/work/deals/'.$this->id.'/contract/show', 'confirm' => 'Отправить договор менеджеру?'],
            $contract->ready_at ?? $contract->updated_at);
    }

    /**
     * Чего не хватает счёту за ТС словами — для строки-заготовки счёта и шторки «Счёт невозможно сформировать, пока не указаны»
     * (18.11.2026, `x-deal.invoice-gaps`). Поля покупателя — ключами полей (их вводят тут же), остальное — своими ключами.
     *
     * @return array<string, string>
     */
    public function invoiceBlockers(): array
    {
        return match ($this->invoiceGap()) {
            'passport', 'unread' => $this->invoiceGaps(),
            'buyer' => ['buyer' => 'Покупатель'],
            'price' => ['price' => 'Цена продажи'],
            'share' => ['share' => 'Наша доля'],
            'commission' => ['commission' => 'Вознаграждение'],
            'ros_requisites' => ['ros_requisites' => 'Реквизиты Р.О.С.'],
            'invoice' => $this->amount === null ? ['amount' => 'Цена сделки'] : [],
            default => [],
        };
    }

    /**
     * Паспорт покупателя по договору готов со сканом (`Party::readyAsBuyer`). Гаражной «платит менеджер» покупатель —
     * сам менеджер; ПРАЙМ без договора — нечего проверять.
     */
    public function passportReady(): bool
    {
        $buyer = $this->invoiceBuyer();

        return ! $buyer || Party::forUser($buyer)->readyAsBuyer();
    }

    /** Живые счета по сделке в обе стороны, старые первыми. */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->where('state', '!=', InvoiceState::Void)->orderBy('id');
    }

    /** Связи счетов, что нужны окну и странице сделки: деньги, путь, плательщик, долг. */
    public const SHOWN_INVOICES = ['invoices.party', 'invoices.payments', 'invoices.claims.media', 'invoices.payLinks.attempts',
        'invoices.payLinks.creator', 'invoices.payLinks.payerUser'];

    /**
     * Живые счета сделки для показа — одна выборка на запрос (17.11.2026: окно сделки читало их семь раз — путь, «Продажа»,
     * схема, сумма договора, плательщик, долг, «деньги правятся»). На показ (GET в вебе) — загруженная связь `invoices` со
     * всем, что нужно видам; в действиях и очереди — из базы: счета меняются посреди запроса.
     *
     * @return Collection<int, Invoice>
     */
    public function shownInvoices(): Collection
    {
        if (app()->runningInConsole() || ! request()->isMethodSafe()) {
            return $this->invoices()->with(array_map(fn ($r) => substr($r, strlen('invoices.')), self::SHOWN_INVOICES))->get()
                ->each(fn (Invoice $i) => $i->setRelation('deal', $this));
        }
        $this->loadMissing(self::SHOWN_INVOICES);

        return $this->invoices->each(fn (Invoice $i) => $i->setRelation('deal', $this));
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
            // Вознаграждение не назначено — подбор неизвестен (22.11.2026, владелец: «пока явно не указали, что вознаграждение 0,
            // нельзя писать, что он нам якобы должен 80 тыщ»): счёта нет, в «Оплатах» — «назначить».
            $this->rewardPending() => 0,
            $this->splitsSelection() => (int) $this->linkDue(),
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

    /** Машина в гараже по этой сделке: пока сделка идёт, бумаги со страховой ведёт её маршрут. */
    public function garageCar(): HasOne
    {
        return $this->hasOne(GarageCar::class);
    }

    /** Наша доля навара по гаражу Совкомбанка, если у менеджера своей нет (`users.garage_share`, 12.11.2026). */
    public const GARAGE_SHARE = 60;

    /**
     * Вознаграждение менеджеру гаражной Совкомбанка по его доле (12.11.2026, владелец: считали на калькуляторе в WhatsApp —
     * Seltos «1000 − 905, 57 твои, 893 143 + 57 000 = 950 143»): навар — цена продажи минус закупочная минус его расходы по
     * машине, наша часть — доля навара (до тысячи вверх); счёт ему — закупочная плюс наша часть, остальное от продажи — его
     * (вознаграждение вместе с возвратом его расходов: счёт ПРАЙМ — продажа минус вознаграждение, `SyncDealInvoices`).
     * Одна дверь: «Продаю» (`MarkGarageSold`), подсказка в «Назначить вознаграждение», расчёт в шторке «Продаю»
     * (`commission_controller#suggested` — то же правило). Не Совкомбанк или без цены — null.
     */
    public function suggestedCommission(?int $sale = null): ?int
    {
        $sale ??= $this->amount;
        if (! $this->namesBuyer() || $sale === null || $this->cost === null) {
            return null;
        }

        return self::commissionFrom($sale - (int) $this->cost, $this->managerGarageCosts(), $this->ourGarageShare());
    }

    /** Расходы менеджера по машине гаража (эвакуатор, ремонт) — его, их он возвращает себе из продажи. */
    public function managerGarageCosts(): int
    {
        return (int) round((float) $this->garageCar?->extraSpent(Payer::Manager));
    }

    /**
     * Сколько пришло по сделке деньгами вне её счетов (выписка по VIN, разовые ссылки — `credited`) против того, что должно
     * прийти (12.11.2026: Scania — 210 000 из 403 291): цена продажи известна — сумма счёта (продажа минус удержанное
     * вознаграждение), нет — закупочная. Ничего не пришло или счёт уже встал (деньги в нём) — null.
     *
     * @return array{paid: float, due: int, diff: float, against: string}|null
     */
    public function arrivedBalance(): ?array
    {
        $paid = $this->credited();
        if ($paid <= 0 || $this->issuedInvoices()->where('kind', ChargeKind::Sale)->where('state', '!=', InvoiceState::Void)->exists()) {
            return null;
        }
        $split = $this->sellSplit();
        $due = $split ? $split['payable'] : (int) $this->cost;

        return ['paid' => $paid, 'due' => $due, 'diff' => round($paid - $due, 2), 'against' => $split ? 'invoice' : 'cost'];
    }

    /**
     * Расчёт продажи гаражной Совкомбанка — одна дверь цифр (12.11.2026): шторки «Продаю» и «Продана», деньги гаража
     * менеджера, «Деньги» CRM. Вознаграждение — записанное (при той же цене), иначе по доле (`suggestedCommission`); к оплате
     * XCAR — цена минус удержанное вознаграждение; пришло — зачёт по VIN; остаток — к оплате минус пришло. Цены нет — null.
     *
     * @return array{reward: int, payable: int, paid: float, rest: float}|null
     */
    public function sellSplit(?int $sale = null): ?array
    {
        $sale ??= $this->amount;
        if (! $this->namesBuyer() || $sale === null) {
            return null;
        }
        $recorded = $this->commission !== null && $sale === (int) $this->amount;
        $reward = $recorded ? (int) $this->commission : $this->suggestedCommission($sale);
        if ($reward === null) {
            return null;
        }
        // Наш счёт — цена минус вознаграждение (18.11.2026: вознаграждение менеджер получает не от нас).
        $payable = $sale - $reward;
        $paid = $this->credited();

        return ['reward' => $reward, 'payable' => $payable, 'paid' => $paid, 'rest' => round($payable - $paid, 2)];
    }

    /**
     * Вознаграждение по умолчанию — доля менеджера от разницы (13.11.2026, владелец: «у Сахиба по умолчанию 50:50 от разницы
     * закупочной и продажной», 1 480 000 − 1 275 000 → 102 500; `users.deal_share`): целыми рублями, без округления до тысячи.
     * Доли нет, цены или закупочной нет, гаражная и супер-покупателя (у них свои деньги) — null.
     */
    public function defaultCommission(?int $amount = null, ?int $cost = null): ?int
    {
        $share = $this->buyer?->deal_share;
        $amount ??= $this->amount;
        $cost ??= $this->cost;
        if ($share === null || $amount === null || $cost === null || $this->isGarage() || $this->isDirect()) {
            return null;
        }

        return self::shareOf($amount - $cost, $share);
    }

    /** Доля от разницы, целыми рублями; разницы нет — 0. Одна дверь с `commission_controller#share`. */
    public static function shareOf(int $margin, int $share): int
    {
        return max(0, (int) round($margin * $share / 100));
    }

    /**
     * Менеджер оформляет на себя (13.11.2026, владелец: Сахиб, 8192/046/06663/26 — «покупатель с другого региона, договор и
     * оплату на меня, он у меня заберёт»): покупатель по договору — сам менеджер, его покупателя в системе нет. Вознаграждение
     * тогда всегда вычитается из того, что он платит (`SaveDealContract`). Гаражная и супер-покупателя — не эта схема.
     */
    public function selfBuy(): bool
    {
        return $this->buyer_id !== null && $this->contract?->buyer_user_id === $this->buyer_id && ! $this->isGarage() && ! $this->isDirect();
    }

    /** Наша доля навара у менеджера сделки, %. */
    public function ourGarageShare(): int
    {
        return $this->buyer?->garage_share ?? self::GARAGE_SHARE;
    }

    /**
     * Разница (продажа − закупочная), его расходы и наша доля → вознаграждение менеджеру: всё, кроме нашей части навара
     * (то же правило — `commission_controller#suggested`).
     */
    public static function commissionFrom(int $margin, int $costs, int $share): int
    {
        $ours = $margin - $costs > 0 ? (int) (ceil(($margin - $costs) * $share / 100 / 1000) * 1000) : 0;

        return max(0, $margin - $ours);
    }

    /** Наценка над закупочной поставщика для CRM (`Margin`): у идущей сделки с ценой подтверждения. */
    public function marginMark(): ?array
    {
        return $this->isActive() && ! $this->isGarage() ? Margin::of($this->amount, $this->supplierCost()) : null;
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
    public function payDue(): ?Carbon
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
        return $q->where(fn ($w) => $w->whereNull('deals.cancelled_at')->orWhereNotExists(fn ($n) => $n->selectRaw('1')
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

    /** Сделка в CRM: админ ведёт её в редакторе предложения (`Admin\DealController::show`), гаражную — у «Гаража». */
    public function crmUrl(): string
    {
        return Surface::Crm->url('/offers/'.$this->offer->number.($this->isGarage() ? '#garage' : ''));
    }

    /** Куда ведёт сделка менеджера: гаражная живёт в гараже, а не в «Сделках». */
    public function href(): string
    {
        if ($this->isGarage() && ($car = $this->garageCar ?? GarageCar::where('offer_id', $this->offer_id)->first())) {
            return $car->url();
        }

        return '/deals/'.$this->id;
    }

    /**
     * Ссылка тому, кому отдали машину (28.10.2026, владелец: «чтобы формировалась сразу ссылка на страницу сделки — я ему
     * отправлю, и он сразу откроет»): его сделка или машина в гараже на сайте; не вошёл — вход, потом сюда же. Нет
     * человека (гараж «под себя», аукцион) или сделку отменили — ссылки нет.
     */
    public function personUrl(): ?string
    {
        if (! $this->buyer_id || $this->is_demo || $this->isAuction() || $this->state === DealState::Cancelled) {
            return null;
        }
        $href = $this->href();

        return str_starts_with($href, 'http') ? $href : Surface::Site->url($href);
    }

    /** Текст к ссылке для «Отправить»: машина и куда ведёт, адрес — следом. */
    public function personText(): string
    {
        return $this->offer->titleWithYear().($this->isGarage() ? ' — в вашем гараже XCAR:' : ' — ваша сделка в XCAR:');
    }

    /** Сделку закрыл отказ поставщика (28.10.2026, `DeclineBySupplier`, этап «Отказ поставщика»): причина — `close_note`. */
    public function declinedBySupplier(): bool
    {
        return $this->close_reason === 'supplier';
    }

    public function isActive(): bool
    {
        return $this->closed_at === null && ! $this->parallel;
    }

    /**
     * Деньги сделки правятся (22.11.2026, владелец: «в окошке оплат все те же функции, что на странице, — цену продажи,
     * вознаграждение»; и у «Выдан»): идущая и закрытая сделкой, не отменённая и не параллельная. Платили ли — `moneyEditable`.
     */
    public function moneyOpen(): bool
    {
        return $this->cancelled_at === null && ! $this->parallel;
    }

    /**
     * Поручена параллельно (07.11.2026, `Offers\Parallels`): менеджер ведёт её как обычную, маршрута, писем и счетов у неё
     * нет — шаг теневой (`shadowStage`). Действия по машине — только у идущей (`isActive`).
     */
    public function isParallel(): bool
    {
        return $this->closed_at === null && (bool) $this->parallel;
    }

    /**
     * Чем менеджер отвечает на шаг — одна дверь (11.11.2026: страница сделки, ответ из чата, поток файлов шага, кнопки чата
     * и Telegram выбирали каждый сам, и у параллельной поток файлов и чат рисовали кнопки шага основной машины): у
     * параллельной — исходы её теневого шага (`Parallels::exits`), у остальных — `Handover::managerExits`.
     *
     * @return \Illuminate\Support\Collection<int, Outcome>
     */
    public function managerExits(?Stage $stage): \Illuminate\Support\Collection
    {
        if (! $stage) {
            return collect();
        }

        return $this->isParallel() ? Parallels::exits($this, $stage) : Handover::managerExits($stage, $this);
    }

    /**
     * Была параллельной (поручили параллельно или переключили с неё на другого) — её путь теневой и после закрытия: шаги
     * машины этой сделке не показываются никогда (`Parallels::dress`, `Path::journal`).
     */
    public function wasParallel(): bool
    {
        return $this->isParallel() || once(fn () => OfferEvent::where('offer_id', $this->offer_id)->where('type', OfferEventType::Parallel)
            ->where(fn ($q) => $q->where(fn ($g) => $g->where('payload->do', 'given')->where('payload->deal', $this->id))
                ->orWhere(fn ($t) => $t->where('payload->do', 'taken')->where('payload->main', $this->id)))->exists());
    }

    /** Живая для менеджера: идущая или параллельная — его страница, строки и «Ваш ход» рисуют её одинаково. */
    public function isLive(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Сколько клиент покупателя с гаражом платит ПРАЙМ по счёту, и сумма договора (27.11.2026, владелец по Tiggo Ананьева: «продаёт
     * за 900 тысяч, вложил 123 — отнимаем, 50 его комиссия — отнимаем, остальное по счёту на ПРАЙМ»): цена продажи минус его
     * расходы в гараже (`Payer::Manager`) минус его вознаграждение. Нет цены или вознаграждения — null (счёта нет, ход его).
     */
    public function holderNet(): ?int
    {
        if (! $this->forGarageBuyer() || $this->amount === null || $this->commission === null) {
            return null;
        }
        $spent = (int) round((float) $this->garageCar?->extraSpent(\App\Garage\Payer::Manager));

        return max(0, (int) $this->amount - $spent - (int) $this->commission);
    }

    /**
     * Почему покупателя по договору уже не сменить (26.11.2026, владелец: «когда прикрепили покупателя, его невозможно убрать» —
     * смену открыли, а запрет — только где деньги или подпись уже на нём): подписанный договор приложен, по счёту за ТС платили
     * или сообщили об оплате (`SetDealPayer::locked`). Можно — null.
     */
    public function buyerLocked(): ?string
    {
        return match (true) {
            $this->contract_signed_at !== null || $this->getMedia('signed_contract')->isNotEmpty() => 'Договор подписан, покупателя не поменять',
            \App\Offers\Actions\SetDealPayer::locked($this) => 'По счёту уже платили, покупателя не поменять',
            default => null,
        };
    }

    /**
     * Держатель (менеджер или покупатель с гаражом) ещё выбирает покупателя по договору и правит его данные: сделка идёт — или
     * она закрыта (поставщику заплатили), а машина покупателя с гаражом ещё продаётся (26.11.2026: клиент у него появляется,
     * когда продаст, — с закрытием сделки выбор пропадал).
     */
    public function holderEditable(): bool
    {
        return $this->isLive() || ($this->state !== DealState::Cancelled && $this->forGarageBuyer()
            && ($car = $this->garageCar) && $car->state !== \App\Garage\CarState::Settled);
    }

    /** Параллельные той же машины (у главной): метка «+N» в «Сделках» CRM. */
    public function parallels(): HasMany
    {
        return $this->hasMany(self::class, 'offer_id', 'offer_id')->whereNull('closed_at')->where('parallel', true);
    }

    /** Теневой шаг параллельной — где она у менеджера, пока машину ведёт главная. */
    public function shadowStage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'shadow_stage_id');
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
     * прогон этапов). Тогда шаг «Забрать автомобиль» ждёт нас: просьбы «Заберите» и часов у менеджера нет
     * (`EnterStage`, `SyncHandover::turn`), в сделке — «Везёт …» (`Handover`). Вывоз читается из связи `pickupTask`: кто
     * только что его завёл или назначил, снимает её до вызова.
     */
    /**
     * Машина у нас (15.11.2026, 2610131943: привезли на парковку Шоссе Энтузиастов, а шаг «Забрать автомобиль» просил менеджера
     * связаться с владельцем): место «На нашей парковке» / «Стоит у нас» или ТС парковки стоит. Шаг получения тогда проходит сам
     * (`SyncHandover::passIfWithUs`).
     */
    public function carWithUs(): bool
    {
        $offer = $this->offer;

        return in_array($offer?->car_place, [CarPlace::Ours, CarPlace::WithUs], true)
            || $offer?->parkVehicle?->state === VehicleState::Stored;
    }

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
     * Услуги подбора — что остаётся нам сверх закупочной: ПРАЙМ и Р.О.С. — цена минус вознаграждение минус закупочная; ДКП и
     * страховой — подбор, который менеджер платит нам (взаимозачёт внутри); гаражная «платит менеджер» — наша доля.
     */
    /**
     * Вознаграждение менеджеру положено, но не назначено (`commission` пусто; ноль — назначено): не аукцион, не супер-покупатель,
     * не гаражная, кроме Совкомбанка. От него зависят услуги подбора — пока его нет, их суммы нет нигде (22.11.2026).
     */
    public function rewardPending(): bool
    {
        return $this->commission === null && ! $this->isAuction() && ! $this->isDirect() && (! $this->isGarage() || $this->namesBuyer());
    }

    public function ours(): ?int
    {
        if ($this->isGarageManager()) {
            return $this->share;
        }
        $margin = $this->paysSelection() ? $this->selectionBase() : $this->margin();

        return $margin === null ? null : $margin - (int) $this->commission;
    }

    /**
     * Есть ли у менеджера счёт, который платит он (или его покупатель): наш, живой, не вознаграждение от вендора и **отправленный
     * ему** кнопкой в CRM (`shown_at`, 16.11.2026). Без него этап оплаты ждёт нас — выставить или отправить счёт
     * (`Position::awaitsInvoice`, `invoiceGap` = `send`).
     */
    public function hasManagerInvoice(bool $sent = true): bool
    {
        // Списки грузят счета заранее (`DealController::index`) — по ним, без запроса на строку (16.11.2026).
        if ($this->relationLoaded('invoices')) {
            return $this->invoices->contains(fn (Invoice $i) => $i->direction === 'issued' && (! $sent || $i->isShownToManager()));
        }

        return $this->issuedInvoices()->when($sent, fn ($q) => $q->shownToManager())->exists();
    }

    /** Есть ли живой счёт продажи — по загруженным счетам, иначе запросом. */
    public function hasSaleInvoice(): bool
    {
        if ($this->relationLoaded('invoices')) {
            return $this->invoices->contains(fn (Invoice $i) => $i->kind === ChargeKind::Sale);
        }

        return Invoice::where('deal_id', $this->id)->where('kind', ChargeKind::Sale)->where('state', '!=', InvoiceState::Void)->exists();
    }

    public function hasInvoices(): bool
    {
        return $this->relationLoaded('invoices') ? $this->invoices->isNotEmpty() : $this->invoices()->exists();
    }

    /** Деньги сделки правятся, пока за наши счета не платили: счета, что ставятся сами, перевыставит `SyncDealInvoices`. */
    public function moneyEditable(): bool
    {
        $invoices = $this->shownInvoices();

        return $invoices->every(fn (Invoice $i) => $i->direction === 'issued' && $i->claims->isEmpty()
            && $i->payments->every(fn ($p) => $p->source->isSoft()));
    }

    /** Все наши счета по сделке оплачены, и хотя бы один есть. */
    /**
     * Шаг оплаты оплачен (26.11.2026): все счета сделки — или все, кроме ссылки за подбор, когда есть счёт за ТС и он оплачен. Подбор
     * с 25.11.2026 — отдельной ссылкой и своей вкладкой «Ждём оплату подбора»: до того шаг держал и деньги за ТС (Р.О.С. получил за ТС,
     * а «Перевод поставщику» Альфе ждал оплату подбора). Шаг, где платят только подбор (Т-Страхование), — по-прежнему всё.
     */
    public function payStepPaid(): bool
    {
        if ($this->fullyPaid()) {
            return true;
        }
        $issued = $this->issuedInvoices()->where('state', '!=', InvoiceState::Void)->get();
        $car = $issued->filter(fn (Invoice $i) => $i->kind === \App\Billing\ChargeKind::Sale);

        return $car->isNotEmpty() && $car->every(fn (Invoice $i) => $i->state === InvoiceState::Paid)
            && $issued->reject(fn (Invoice $i) => $i->state === InvoiceState::Paid)->every(fn (Invoice $i) => $i->kind === \App\Billing\ChargeKind::Selection && ! $i->isDocument());
    }

    public function fullyPaid(): bool
    {
        // Счёт вознаграждения вендору (наш доход от поставщика) — не оплата покупателя: неоплаченный, он держал шаг «Оплата»
        // сделки, где за машину заплатили целиком (аудит 17.11.2026).
        $issued = $this->issuedInvoices()->get();

        return $issued->isNotEmpty() && $issued->every(fn (Invoice $i) => $i->state === InvoiceState::Paid);
    }
}
