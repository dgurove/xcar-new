<?php

namespace App\Billing;

use App\Billing\Acquiring\PayLink;
use App\Billing\Acquiring\PayLinkState;
use App\Garage\Car as GarageCar;
use App\Live\Publisher;
use App\Live\Topics;
use App\Mail\Message;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Park\City;
use App\Park\Vehicle;
use App\Search\Term;
use App\Support\Demo\HidesDemo;
use App\Support\FastAttributes;
use App\Support\Sort;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Счёт. `issued` — нам должны, `owed` — должны мы (перечисление вендору по
 * договору комиссии). Остаток и просрочка считаются, не хранятся; PDF — снимок
 * в момент выставления, коллекция `file` на закрытом диске. `seller` — кто из нас
 * выставил: реквизиты в документах и свой ряд номеров. Реестр (`isRegistry`) — счёт парковки плательщику за месяц
 * без своей ТС: машины — строками хранения со своим `vehicle_id`, долг машины — её доля (`Ledger::shareOf`).
 */
#[Fillable(['seller', 'park', 'direction', 'year', 'number', 'code', 'revision', 'external_no', 'kind', 'month', 'party_id', 'vehicle_id', 'deal_id', 'offer_id', 'issued_at', 'due_at', 'vat', 'vat_rate', 'vat_on_top', 'total', 'paid', 'state', 'paid_at',
    'overdue_at', 'reminded_at', 'sent_at', 'sent_by', 'sent_message_id', 'voided_at', 'void_reason', 'notes', 'created_by', 'car_title', 'credit_deal_id', 'shown_at', 'shown_by',
    'act_no', 'act_at', 'basis', 'line_title', 'requested_at', 'requested_by'])]
class Invoice extends Model implements HasMedia
{
    use FastAttributes;
    use HidesDemo;
    use InteractsWithMedia;

    protected $table = 'billing_invoices';

    /** Управляющий с городом видит счета только своего города (`Park\City`). */
    protected static function booted(): void
    {
        static::addGlobalScope(City::SCOPE, fn (Builder $q) => ($city = City::viewing()) ? City::invoices($q, $city) : null);
        // Счёт выставили, оплатили, аннулировали — «Оплаты» у всех, кто их открыл, перечитываются (18.10.2026: у других
        // строка висела в «Проверить» / «Ждём»). После коммита.
        static::saved(fn (self $i) => $i->wasRecentlyCreated || $i->wasChanged(['state', 'paid', 'due_at', 'shown_at']) ? $i->refreshLists() : null);
    }

    /** Перечитать списки «Оплат» своей стороны: парковки или CRM. */
    public function refreshLists(): void
    {
        if ($this->park) {
            app(Publisher::class)->refreshSoon(Topics::PARK, ['/money']);

            return;
        }
        // И страница человека в «Оплатах» (18.11.2026, `MoneyController::person`): менеджер счёта, у выплаты — получатель.
        $person = $this->isOwed() ? User::where('party_id', $this->party_id)->orderBy('id')->value('id') : $this->manager()?->id;
        app(Publisher::class)->refreshSoon(Topics::STAFF, ['/work/money', '/work/money/managers/'.($person ?? 'none')]);
    }

    public const VAT = 20;

    protected function casts(): array
    {
        return ['seller' => Seller::class, 'kind' => ChargeKind::class, 'state' => InvoiceState::class, 'month' => 'date', 'issued_at' => 'date', 'due_at' => 'date', 'paid_at' => 'date', 'act_at' => 'date', 'vat' => 'bool', 'vat_rate' => 'int', 'vat_on_top' => 'bool', 'park' => 'bool',
            'total' => 'float', 'paid' => 'float', 'revision' => 'int', 'overdue_at' => 'datetime', 'reminded_at' => 'datetime', 'sent_at' => 'datetime', 'voided_at' => 'datetime', 'shown_at' => 'datetime', 'requested_at' => 'datetime'];
    }

    public function registerMediaCollections(): void
    {
        // Прежние PDF счетов (до 17.11.2026); новые не пишутся — счёт рисуется при открытии (`InvoicePdf`).
        $this->addMediaCollection('file')->useDisk('private')->singleFile();
        // Акт: у счёта на ТС — один PDF, у реестра — файлы прежней формы страховой (у РГС — акт и приложение).
        $this->addMediaCollection('act')->useDisk('private');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** Кто отправил документ (письмом или «Отправили сами»). */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** Письмо, которым документ ушёл; без него — отметили отправленным сами. */
    public function sentMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'sent_message_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /** Счёт-реестр за месяц: ТС у строк, не у счёта. */
    public function isRegistry(): bool
    {
        return $this->month !== null && $this->vehicle_id === null;
    }

    /** За что счёт словами в строках списков: ТС, реестр за месяц, сделка или вид. */
    public function what(): string
    {
        return match (true) {
            (bool) $this->vehicle => $this->vehicle->titleWithYear(),
            $this->isRegistry() => 'реестр за '.mb_strtolower($this->month->translatedFormat('F')),
            (bool) $this->deal_id => 'сделка',
            default => $this->kind->label(),
        };
    }

    /**
     * Ждёт письма в бухгалтерию: выставлен страховой стороне (реестр, вендор или страхователь) и ещё не уходил. Счёт
     * покупателю письмом не шлют — его берут на выдаче, в «Отправить» его нет.
     */
    /**
     * Поиск счёта — одна дверь для «Оплат» CRM и парковки и шапок: номер («счёт 12», «№ 12», голое число) и чужой номер,
     * плательщик, текст услуги, менеджер, ТС предложения (`Offer::search`) и ТС парковки, в том числе любая машина
     * реестра (`Vehicle::search`).
     */
    public function scopeSearch(Builder $q, string|Term $term): Builder
    {
        $t = Term::of($term);
        if ($t->blank()) {
            return $q;
        }
        $like = Term::like($t->raw);
        $name = fn ($u) => $u->where('name', 'ilike', $like);
        $car = fn ($v) => $v->search($t);

        return $q->where(fn ($s) => $s
            ->when($t->invoice, fn ($x, $n) => $x->orWhere('billing_invoices.number', $n))
            ->orWhere('billing_invoices.code', 'ilike', $like)
            ->orWhere('billing_invoices.external_no', 'ilike', $like)
            ->orWhere('billing_invoices.car_title', 'ilike', $like)
            ->orWhereHas('party', $name)
            ->orWhereHas('charges', fn ($c) => $c->where('kind', ChargeKind::Service->value)->where('title', 'ilike', $like))
            ->orWhereHas('deal.buyer', $name)
            ->orWhereHas('garageCar.manager', $name)
            ->orWhereHas('garagePayoutCar.manager', $name)
            ->orWhereHas('offer', $offer = fn ($o) => $o->searchCrm($t))
            ->orWhereHas('deal.offer', $offer)
            ->orWhereHas('garageCar.offer', $offer)
            ->orWhereHas('vehicle', $car)
            // Реестр находится по любой своей машине.
            ->orWhereHas('charges.vehicle', $car));
    }

    public function scopeToSend(Builder $q): Builder
    {
        return $q->where('direction', 'issued')->where('state', InvoiceState::Issued)->whereNull('sent_at')
            ->where(fn ($w) => $w->where(fn ($r) => $r->whereNull('vehicle_id')->whereNotNull('month'))
                ->orWhereIn('party_id', Vendor::whereNotNull('party_id')->select('party_id'))
                ->orWhereIn('party_id', Vehicle::whereNotNull('owner_party_id')->select('owner_party_id')));
    }

    /** Счета, где есть эта ТС: свои (`vehicle_id`) и реестры, в строках которых она есть. */
    public function scopeForVehicle(Builder $q, int $vehicleId): Builder
    {
        return $q->where(fn ($w) => $w->where('vehicle_id', $vehicleId)
            ->orWhere(fn ($r) => $r->whereNull('vehicle_id')->whereNotNull('month')->whereIn('id', Charge::where('vehicle_id', $vehicleId)->whereNotNull('invoice_id')->select('invoice_id'))));
    }

    /** ТС реестра — по строкам, в порядке строк. @return \Illuminate\Support\Collection<int, Vehicle> */
    public function registryVehicles()
    {
        $this->loadMissing('charges.vehicle');

        return $this->charges->pluck('vehicle')->filter()->unique('id')->values();
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** Сделка, в которую засчитана разовая оплата (ручная ссылка с машиной), — не сделка счёта. */
    public function creditDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'credit_deal_id');
    }

    /** Машина счёта: своя у разовой оплаты, сделки или гаража. */
    public function carOffer(): ?Offer
    {
        return $this->offer ?? $this->deal?->offer ?? ($this->relationLoaded('garageCar') ? $this->garageCar : GarageCar::ofInvoice($this))?->offer;
    }

    /** Машина словами для ссылки и строк: «Toyota Camry, № 2610041229», у ТС не из системы — как вписали. */
    public function carLine(): ?string
    {
        if ($offer = $this->carOffer()) {
            return $offer->title().(OfferNumber::isPublic($offer) ? ', № '.$offer->number : '');
        }

        return $this->car_title ?: null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class, 'invoice_id')->orderBy('id');
    }

    /** Принятые оплаты — те, что в `paid`. Заявленные и не поступившие — отдельно. */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id')->whereNull('voided_at')->where('state', PaymentState::Confirmed)->orderBy('paid_at');
    }

    /** Заявленные менеджером: платёжка приложена, сотрудник ещё не подтвердил. */
    public function claims(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id')->where('state', PaymentState::Claimed)->orderBy('id');
    }

    /** Все оплаты для ленты: принятые, заявленные, не поступившие; отменённые — нет. */
    public function allPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id')->whereNull('voided_at')->orderBy('id');
    }

    /** Сколько заявлено и ждёт подтверждения. */
    /**
     * Заявлено к оплате. Экраны берут уже загруженные заявки (списки «Денег», положение) — без запроса на каждый счёт;
     * действия, которые по этому числу решают (новая заявка, ссылка на оплату), просят `fresh` — из базы.
     */
    public function claimed(bool $fresh = false): float
    {
        return round((float) (! $fresh && $this->relationLoaded('claims') ? $this->claims->sum('amount') : $this->claims()->sum('amount')), 2);
    }

    public function scopeOfSeller($q, Seller $seller)
    {
        return $q->where('seller', $seller->value);
    }

    /**
     * Счета парковки — по ТС и реестры, от какого бы продавца ни были: Питер выставляет от ПРАЙМ
     * (`vendors.park_seller`), поэтому продавец ИП парковку больше не определяет.
     */
    public function scopeOfPark($q)
    {
        return $q->where($q->getModel()->qualifyColumn('park'), true);
    }

    /** Ссылки на оплату; открытая у счёта одна. */
    public function payLinks(): HasMany
    {
        return $this->hasMany(PayLink::class, 'invoice_id')->orderBy('id');
    }

    public function openLink(): ?PayLink
    {
        return $this->relationLoaded('payLinks') ? $this->payLinks->first->isOpen() : $this->payLinks()->where('state', PayLinkState::Open)->first();
    }

    /** Долг за перевозку из логистики: партнёру или перевозчику (`Logistics\Actions\SettleCost`). */
    public function isTransport(): bool
    {
        return $this->direction === 'owed' && $this->kind === ChargeKind::Tow && ! $this->park;
    }

    public function isAgentFee(): bool
    {
        return $this->direction === 'owed' && $this->kind === ChargeKind::AgentFee;
    }

    /**
     * Что видит менеджер: счета его контрагенту и счета по его сделкам (плательщик —
     * его покупатель). Одна дверь для списка, страницы, PDF и заявки об оплате. Счёт вендору (вознаграждение от
     * поставщика) — никогда: в нём его название, реквизиты и деньги со страховой (15.10.2026).
     */
    public function scopeVisibleToManager($q, User $user)
    {
        return $q->where(fn ($k) => $k->whereNull('billing_invoices.kind')->orWhere('billing_invoices.kind', '!=', ChargeKind::Reward->value))->where(fn ($w) => $w
            ->when($user->party_id, fn ($x) => $x->where('party_id', $user->party_id), fn ($x) => $x->whereRaw('false'))
            ->orWhereHas('deal', fn ($d) => $d->where('buyer_id', $user->id)))
            // Покупателю с гаражом без галки цены — ни одного гаражного счёта, и своим плательщиком тоже (21.10.2026).
            ->when(! $user->seesGaragePrice(), fn ($x) => $x->where(fn ($g) => $g->whereNull('billing_invoices.deal_id')->orWhereHas('deal', fn ($d) => $d->whereNull('garage_payer'))))
            ->shownToManager();
    }

    public function isVisibleToManager(User $user): bool
    {
        return $this->kind !== ChargeKind::Reward
            && (($user->party_id && $this->party_id === $user->party_id) || ($this->deal_id && $this->deal?->buyer_id === $user->id))
            && ($user->seesGaragePrice() || ! $this->deal?->isGarage())
            && $this->isShownToManager();
    }

    /**
     * Счёт сделки менеджеру — только после «Отправить менеджеру» в CRM (16.11.2026, владелец: сотрудники проверяют, потом
     * отправляют; до того у него «Счёт готовится»). Счета без сделки (парковка, вендору) и выплаты ему — как были.
     */
    public function scopeShownToManager($q)
    {
        // Деньги — настоящие: зачёт удержанного вознаграждения (`Offset`) оплатой не считается.
        return $q->where(fn ($w) => $w->whereNull('billing_invoices.deal_id')->orWhere('billing_invoices.direction', '!=', 'issued')->orWhereNotNull('billing_invoices.shown_at')
            ->orWhereRaw("billing_invoices.paid > coalesce((select sum(p.amount) from billing_payments p where p.invoice_id = billing_invoices.id and p.source = 'offset'), 0) + 0.005"));
    }

    /**
     * Сделка счёта стоит на шаге оплаты (`Stage::isPayStep`: у менеджера выход «Платёжное поручение…» / «Оплатил, документ…»).
     * Неотправленный счёт до этого шага — ещё не долг (18.11.2026, владелец: «по многим поставщики даже не согласовали реализацию, а
     * ты предлагаешь отправить 53 ссылки — только на этапе „Оплата подбора“»): в «Оплатах» его нет.
     */
    public const ON_PAY_STEP_SQL = "exists (select 1 from deals d join offer_positions p on p.offer_id = d.offer_id and p.track = 'sale'
        join workflow_exits e on e.stage_id = p.stage_id and e.actor = 'manager'
        where d.id = billing_invoices.deal_id and (lower(e.label) like 'платёжное поручение%' or lower(e.label) like 'оплатил, документ%'))";

    /** Наш счёт сделки (`issued`) — после отправки или первой оплаты (деньги пришли — проверять поздно); выплата ему и счета без сделки — сразу. */
    public function isShownToManager(): bool
    {
        return ! $this->deal_id || $this->direction !== 'issued' || $this->shown_at !== null || $this->paidMoney() > 0;
    }

    /**
     * Куда ведёт счёт в CRM — на машину в сделке или гараже (17.11.2026, владелец: «там должна быть кнопка на сделку, а не на
     * страницу счёта, которая вообще не нужна — удали её везде»); счёт без машины (разовая услуга) — его карточка в «Оплатах».
     * Путь без хоста; для Telegram, писем и других хостов — `Surface::Crm->url(...)`.
     */
    public function crmHref(): string
    {
        return $this->carOffer()?->crmPath() ?? \App\Http\Admin\MoneyController::url($this);
    }

    /** Есть ли у счёта документ (PDF рисуется на лету, `InvoicePdf`): счёт, выставленный нами, — не выплата нашего долга. */
    public function hasPdf(): bool
    {
        return ! $this->isOwed() && $this->isDocument();
    }

    /**
     * Комиссия ПРАЙМ — только ссылкой или наличными (18.11.2026, владелец: «счёт на комиссию мы больше не формируем, только либо
     * по ссылке либо наличкой»; «PDF вообще не нужен»): у подбора от ПРАЙМ нет ни номера, ни PDF, ни «Счёт № N». Подбор Р.О.С.
     * (ссылки у неё нет) — счётом, как был.
     */
    public static function feeByLink(ChargeKind $kind, ?Seller $seller): bool
    {
        return $kind === ChargeKind::Selection && $seller === Seller::Prime;
    }

    /** Счёт как документ — номер, PDF, «Счёт № N»: у комиссии ПРАЙМ — только когда счёт запросили («Запросить счёт…»). */
    public function isDocument(): bool
    {
        return ! self::feeByLink($this->kind, $this->seller) || $this->requested_at !== null;
    }

    /** Чем назвать в тексте: «счёт № 197» или, у комиссии без счёта, «подбор» (уведомления, Telegram). */
    public function noun(): string
    {
        return $this->isDocument() ? 'счёт '.$this->label() : 'подбор';
    }

    /** «по счёту № 197» / «за подбор» — к оплате: «Оплата 88 190 ₽ за подбор принята». */
    public function byWhat(): string
    {
        return $this->isDocument() ? 'по счёту '.$this->label() : 'за подбор';
    }

    /** «Счёт № 197, ООО «СТАР»» / «Подбор» — кто платит, только у документа: у комиссии плательщика нет (18.11.2026). */
    public function nounWithParty(): string
    {
        return $this->isDocument() ? 'Счёт '.$this->label().', '.$this->party->name : 'Подбор';
    }

    public function isOwed(): bool
    {
        return $this->direction === 'owed';
    }

    /**
     * Сортировка списков счетов (CRM «Оплаты», «Деньги» парковки): по умолчанию новые сверху — владелец 06.10.2026: по
     * сроку счета без срока уходили в конец и порядок выглядел случайным.
     */
    public const SORTS = ['fresh' => ['Дата счёта', 'desc'], 'due' => ['Срок оплаты', 'asc'], 'amount' => ['Сумма', 'desc'], 'rest' => ['Остаток', 'desc']];

    public function scopeSorted(Builder $q, Sort $sort): Builder
    {
        $dir = $sort->dir();
        match ($sort->key) {
            'due' => $q->orderByRaw("billing_invoices.due_at {$dir} nulls last"),
            'amount' => $q->orderBy('billing_invoices.total', $dir),
            'rest' => $q->orderByRaw("(case when billing_invoices.state = 'void' then 0 else greatest(billing_invoices.total - billing_invoices.paid, 0) end) {$dir}"),
            default => $q->orderBy('billing_invoices.issued_at', $dir),
        };

        return $q->orderBy('billing_invoices.id', $dir);
    }

    public function remaining(): float
    {
        return $this->state === InvoiceState::Void ? 0 : max(0, round($this->total - $this->paid, 2));
    }

    public function isOverdue(): bool
    {
        return $this->state === InvoiceState::Issued && $this->remaining() > 0 && $this->due_at && $this->due_at->isPast() && ! $this->due_at->isToday();
    }

    /** Сколько полных дней прошло после срока. */
    public function overdueDays(): int
    {
        return $this->due_at ? (int) $this->due_at->startOfDay()->diffInDays(now()->startOfDay()) : 0;
    }

    /**
     * Просрочка словами — одна на приложение (16.10.2026: семь мест писали её сами, то «просрочен на 3 дн», то
     * «просрочен 3 дн»): «просрочен на 3 д», как часы шагов, в начале фразы — с большой буквы.
     */
    public function overdueLabel(bool $capital = false): string
    {
        return ($capital ? 'Просрочен' : 'просрочен').' на '.$this->overdueDays().'д';
    }

    /**
     * Срока ещё нет (подбор до шага оплаты, `SyncDealInvoices::dueFor`): у ДКП — после договора с покупателем, у
     * «страховой напрямую» (Альфа) договора с покупателем в маршруте нет — срок встаёт вместе с оплатой за ТС (18.10.2026).
     */
    public function laterLabel(bool $long = false): string
    {
        return match ($this->deal?->scheme) {
            DealScheme::OwnerDkp => $long ? 'после договора с покупателем' : 'после договора',
            DealScheme::Insurer => 'вместе с оплатой за ТС',
            default => 'без срока',
        };
    }

    public function isPartial(): bool
    {
        return $this->state === InvoiceState::Issued && $this->paidMoney() > 0 && $this->remaining() > 0;
    }

    /**
     * Оплачено деньгами: без зачёта удержанного вознаграждения (`PaymentSource::Offset`) — это не оплата, а строка счёта,
     * которую менеджер оставил себе. Иначе счёт «удерживает сам» с порога горел «Частично».
     */
    public function paidMoney(): float
    {
        $offset = $this->relationLoaded('payments')
            ? $this->payments->where('source', PaymentSource::Offset)->sum('amount')
            : ($this->paid > 0 ? (float) $this->payments()->where('source', PaymentSource::Offset)->sum('amount') : 0);

        return max(0, round((float) $this->paid - $offset, 2));
    }

    /** Светофор: зелёный — оплачен, жёлтый — срок в три дня, красный — просрочен, серый — аннулирован, без тона — ждём. */
    public function light(): ?string
    {
        return match (true) {
            $this->state === InvoiceState::Void => 'closed',
            $this->state === InvoiceState::Paid => 'open',
            $this->isOverdue() => 'danger',
            $this->due_at?->lte(now()->addDays(3)) => 'urgent',
            default => null,
        };
    }

    /** Разовая оплата наших услуг из CRM — без сделки и машины (`IssueServiceInvoice`). */
    public function isService(): bool
    {
        return $this->kind === ChargeKind::Service && ! $this->deal_id && ! $this->vehicle_id;
    }

    /** Наименование позиции в чеке ЮKassa: у разовой оплаты — её услуга, у сделки — «Подбор ТС марка модель». */
    public function receiptTitle(): string
    {
        if ($this->isService()) {
            return (string) ($this->relationLoaded('charges') ? $this->charges->first()?->title : $this->charges()->value('title'));
        }

        return trim('Подбор ТС '.($this->offer?->titleWithYear() ?? ''));
    }

    /**
     * Счета раздела «Оплаты» в CRM: по сделкам, по машинам гаража (счёт и выплата менеджеру — без сделки, но в
     * «Расчётах с менеджерами» они есть, 06.10.2026) и разовые оплаты услуг.
     */
    public function scopeCrmMoney($q)
    {
        return $q->where(fn ($w) => $w->whereNotNull('deal_id')
            ->orWhereIn('id', fn ($g) => $g->select('invoice_id')->from('garage_cars')->whereNotNull('invoice_id'))
            ->orWhereIn('id', fn ($g) => $g->select('payout_invoice_id')->from('garage_cars')->whereNotNull('payout_invoice_id'))
            ->orWhere(fn ($s) => $s->where('kind', ChargeKind::Service->value)->whereNull('vehicle_id'))
            // Перевозка из логистики, не парковочная: долг партнёру или перевозчику.
            ->orWhere(fn ($s) => $s->where('direction', 'owed')->where('kind', ChargeKind::Tow->value)->where('park', false)));
    }

    /** Машина гаража, чей это счёт или выплата (у гаражных сделки нет). */
    public function garageCar(): HasOne
    {
        return $this->hasOne(GarageCar::class, 'invoice_id');
    }

    public function garagePayoutCar(): HasOne
    {
        return $this->hasOne(GarageCar::class, 'payout_invoice_id');
    }

    /** Менеджер, с которым этот счёт: покупатель сделки или держатель машины гаража. */
    public function manager(): ?User
    {
        return $this->deal?->buyer ?? ($this->garageCar ?? $this->garagePayoutCar)?->manager;
    }

    /** Номер для документа без «№»: «20261108-01» у парковки, иначе сквозной. */
    public function docNo(): string
    {
        return (string) ($this->code ?? $this->number);
    }

    /**
     * Номер акта (17.11.2026, владелец: счёт Альфе от 17.11, акт — № 20261109-01 от 09.11): свой, если вписали (`act_no`,
     * шторка «Текст счёта» парковки), иначе номер счёта. Его же — строка счёта, отчёт-акт и акты по ТС.
     */
    public function actNo(): string
    {
        return filled($this->act_no) ? (string) $this->act_no : $this->docNo();
    }

    /** «№ 20261109-01» — свой номер акта, иначе подпись счёта (с редакцией). */
    public function actLabel(): string
    {
        return filled($this->act_no) ? '№ '.$this->act_no : $this->label();
    }

    /** Дата акта: своя, иначе дата счёта. */
    public function actDate(): ?\Carbon\CarbonInterface
    {
        return $this->act_at ?? $this->issued_at;
    }

    /** Договор вендора реестра словами: «Договор № E240020/06/03/22 от 16.02.2022 г.», у вывоза — договор эвакуации. */
    public function contractText(): ?string
    {
        if (! $this->isRegistry()) {
            return null;
        }
        $vendor = $this->registryVehicles()->first()?->vendor;
        $agreement = $this->kind === ChargeKind::Tow && $vendor?->tow_agreement_number ? [$vendor->tow_agreement_number, $vendor->tow_agreement_date] : [$vendor?->agreement_number, $vendor?->agreement_date];

        return $agreement[0] ? 'Договор № '.$agreement[0].($agreement[1] ? ' от '.$agreement[1]->format('d.m.Y').' г.' : '') : null;
    }

    /** «Основание» реестра: своё (`basis`), иначе договор, иначе услуга словами. */
    public function basisText(): string
    {
        return filled($this->basis) ? (string) $this->basis
            : ($this->contractText() ?? ($this->kind === ChargeKind::Tow ? 'Эвакуация транспортных средств' : 'Хранение транспортных средств'));
    }

    /**
     * Строка реестра одной позицией (владелец 08.10.2026; слова — как в прежних счетах страховым): своя (`line_title`), иначе
     * «Услуги по хранению автотранспорта за октябрь 2026 г. на основании Договора № … согласно акту № … от …» — номер и дата
     * акта из `actNo` / `actDate`: поправили акт — строка следует сама.
     */
    public function registryLine(): string
    {
        return filled($this->line_title) ? (string) $this->line_title : $this->defaultRegistryLine();
    }

    /** Строка реестра, собранная по счёту (без своей) — с какой сравнить вписанную. */
    public function defaultRegistryLine(): string
    {
        $contract = $this->contractText();

        return ($this->kind === ChargeKind::Tow ? 'Услуги по эвакуации транспортных средств' : 'Услуги по хранению автотранспорта')
            .($this->month ? ' за '.mb_strtolower($this->month->translatedFormat('F Y')).' г.' : '')
            .($contract ? ' на основании '.str_replace('Договор', 'Договора', $contract) : '')
            .' согласно акту № '.$this->actNo().' от '.$this->actDate()?->format('d.m.Y');
    }

    /** Номер в имени файла акта: свой номер акта как есть, иначе номер счёта с редакцией (`fileNo`). */
    public function actFileNo(): string
    {
        return filled($this->act_no) ? str_replace(['/', '\\'], '-', (string) $this->act_no) : $this->fileNo();
    }

    /** Номер в имени файла: исправленный — «20261108-01 (ред 1)», как в архиве. */
    public function fileNo(): string
    {
        return $this->docNo().($this->revision ? ' (ред '.$this->revision.')' : '');
    }

    public function label(): string
    {
        // Наш счёт сделки до «Отправить менеджеру» номера не имеет (`Numbering::assign`): «Счёт без номера».
        return $this->code ? '№ '.$this->code.($this->revision ? ', ред. '.$this->revision : '') : ($this->number ? '№ '.$this->number : ($this->external_no ? $this->external_no
            : ($this->direction === 'issued' && $this->deal_id ? 'без номера' : $this->kind->label())));
    }

    /**
     * Ставка НДС счёта — ставка продавца, записанная при выставлении (`vat_rate`). Старые счета без неё —
     * прежняя галка «С НДС» и 20 %.
     */
    public function vatRate(): ?int
    {
        return $this->vat_rate ?: ($this->vat ? self::VAT : null);
    }

    /** НДС счёта: в сумме — выделенный из итога, сверху — то, что прибавлено к строкам. */
    public function vatAmount(): float
    {
        $rate = $this->vatRate();
        if (! $rate) {
            return 0;
        }

        return $this->vat_on_top ? round($this->total - $this->net(), 2) : Vat::inside($this->total, $rate);
    }

    /** Сумма строк без НДС сверху. */
    public function net(): float
    {
        return $this->vat_on_top ? round((float) $this->charges->sum('amount'), 2) : $this->total;
    }

    /** «В том числе НДС 5 %», «НДС 5 %» (сверху) или «Без НДС». */
    public function vatLabel(): string
    {
        $rate = $this->vatRate();

        return match (true) {
            ! $rate => 'Без НДС',
            (bool) $this->vat_on_top => 'НДС '.$rate.' %',
            default => 'В том числе НДС '.$rate.' %',
        };
    }
}
