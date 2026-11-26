<?php

namespace App\Billing;

use App\Garage\GaragePayer;
use App\Http\Admin\MoneyController;
use App\Http\Admin\MoneyRows;
use App\Offers\Deal;
use App\Support\Facets\Facets;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Строки «Комиссий» и «ДКП» по сделкам (22.11.2026, владелец: «в оплатах — ВСЕ сделки, со статусом этапа», «несколько
 * поступлений по тачке — сумма, а не две строки»). Сделка — в разделе, если у неё есть счёт раздела; счетов ещё нет — по
 * схеме (`expects`): ПРАЙМ и Р.О.С. — «ДКП», подбор — «Комиссии», гаражная «платит менеджер» — обе. Ручная ссылка, засчитанная
 * в сделку (`credit_deal_id`), своей строки не имеет: её деньги — зачётом в счетах сделки. Без сделки — своей строкой у
 * назначенного менеджера (`manager_id`) или в «Без менеджера».
 * `$person`: человек, `null` — «Без менеджера», `false` — все (список людей раздела).
 */
final class MoneyBook
{
    /**
     * Счёт раздела по алиасу `billing_invoices`: «ДКП» — счёт за ТС от ПРАЙМ и Р.О.С., «Комиссии» — остальное наше. `$split` —
     * «Оплаты» сотрудника (23.11.2026): счёт за ТС с подбором внутри (`splitSql`) — и в «Комиссиях», своей долей (`InvoiceSplit`).
     */
    public static function tabSql(string $tab, string $i = 'billing_invoices', bool $split = false): string
    {
        return $tab === 'dkp'
            ? "({$i}.kind = 'sale' and {$i}.seller in ('prime', 'ros'))"
            : "({$i}.kind <> 'sale' or {$i}.seller not in ('prime', 'ros')".($split ? ' or '.self::splitSql($i) : '').')';
    }

    /** Закупочная сделки счёта — доля машины в счёте ПРАЙМ / Р.О.С. */
    private static function costSql(string $i): string
    {
        return "(select dc.cost from deals dc where dc.id = {$i}.deal_id)";
    }

    /** Счёт за ТС ПРАЙМ / Р.О.С. с подбором внутри — как `InvoiceSplit::splits`: сумма больше закупочной сделки. */
    public static function splitSql(string $i = 'billing_invoices'): string
    {
        return "({$i}.kind = 'sale' and {$i}.seller in ('prime', 'ros') and {$i}.total > ".self::costSql($i).' + 0.005)';
    }

    /**
     * Наш неаннулированный счёт сделки этого раздела (`bi`); `$open` — ещё не оплаченный, `$extra` — ещё условие на него. `$split` —
     * по долям: машина счёта ПРАЙМ в «ДКП» оплачена, когда пришла закупочная (сначала машина), подбор — когда счёт оплачен целиком.
     */
    public static function hasTabInvoice(string $tab, bool $open = false, string $extra = '', bool $split = false): string
    {
        $state = match (true) {
            ! $open => "<> 'void'",
            $split && $tab === 'dkp' => "= 'issued' and bi.paid + 0.005 < least(bi.total, coalesce(".self::costSql('bi').', bi.total))',
            default => "= 'issued'",
        };

        return "exists (select 1 from billing_invoices bi where bi.deal_id = deals.id and bi.direction = 'issued' and bi.state "
            .$state.' and '.self::tabSql($tab, 'bi', $split).($extra !== '' ? ' and ('.$extra.')' : '').')';
    }

    /** Счёт `bi` виден менеджеру — как `Invoice::scopeShownToManager`: отправлен или по нему пришли настоящие деньги (не зачёт). */
    // Как `Invoice::isShownToManager`: ссылка на подбор ПРАЙМ без запрошенного счёта, оплаченная наличными частью, — ещё не выставлена.
    private const SHOWN = "(bi.shown_at is not null or (bi.paid > coalesce((select sum(p.amount) from billing_payments p where p.invoice_id = bi.id and p.source = 'offset'), 0) + 0.005
        and (bi.kind <> 'selection' or bi.seller <> 'prime' or bi.requested_at is not null or bi.state = 'paid')))";

    private const NO_INVOICES = "not exists (select 1 from billing_invoices bi where bi.deal_id = deals.id and bi.direction = 'issued' and bi.state <> 'void')";

    /**
     * Идущая сделка без счетов, которой положен счёт раздела, — грубо, схемой (точно — `expects`): чтобы «Показать N» чипа и
     * числа разделов не считали сделок, строк которых не будет. Есть менеджер; не «Взяли под себя»; не гаражная Совкомбанка до
     * «Продаю»; «ДКП» — ПРАЙМ и Р.О.С., «Комиссии» — подбор; гаражная «платит менеджер» — обе.
     */
    private static function awaits(string $tab): string
    {
        $schemes = $tab === 'dkp' ? "('prime', 'ros')" : "('insurer', 'owner_dkp')";

        return "(deals.closed_at is null and deals.buyer_id is not null and deals.garage_payer is distinct from 'us' and ".self::NO_INVOICES."
            and not (deals.garage_payer = 'manager' and deals.amount is null and exists (select 1 from offers mo join vendors mv on mv.id = mo.vendor_id
                where mo.id = deals.offer_id and mv.deal_format = 'commission'))
            and (deals.garage_payer = 'manager' or deals.scheme in {$schemes}))";
    }

    /**
     * Сделки раздела: со счётом раздела или идущие без счетов, которым он положен (`awaits`, точно — `expects`). `$own` — экран
     * самого менеджера (`/account/money`, 22.11.2026): покупателю гаража без галки цены гаражных сделок нет (`Invoice::visibleToManager`).
     */
    public static function deals(string $tab, User|null|false $person, string $search = '', bool $own = false): Builder
    {
        return Deal::query()->where('deals.parallel', false)
            ->when($person !== false, fn ($q) => $person ? $q->where('deals.buyer_id', $person->id) : $q->whereNull('deals.buyer_id'))
            // Лупа — по машине: номер, убыток, VIN, марка (`Offer::searchCrm`).
            ->when($search !== '', fn ($q) => $q->whereHas('offer', fn ($o) => $o->searchCrm($search)))
            ->where(fn ($w) => $w->whereRaw(self::hasTabInvoice($tab, split: ! $own))->orWhereRaw(self::awaits($tab)));
    }

    /** Чип «Статус» раздела, умолчание — после «Беру в работу» и не отменённые; лупа и адрес умолчание снимают. */
    public static function facets(Request $request, string $key = 'crm-money'): Facets
    {
        // Умолчание — не выбор: чип серым «Статус ▾» (`x-ui.facet-chip`), в окне отмечены все, кроме «Не взял в работу» и отменённых.
        // Экран менеджера (`account-money`) чипа не рисует — умолчание действует молча.
        $facets = Facets::for($request, $key, MoneyStage::facet());
        if (! $request->query->has('stage') && trim((string) $request->query('q')) === '') {
            $facets->implied('stage', MoneyStage::DEFAULT);
        }

        return $facets;
    }

    /** Счета раздела строки; менеджеру — только отправленные ему (`Invoice::shownToManager`): неотправленный ещё проверяют. */
    private static function with(string $tab, bool $own = false): array
    {
        // Экрану менеджера — и то, чем строка говорит, что по сделке (`managerTask`, `Turn`): шаг машины и открытая просьба.
        $status = $own ? ['openRequirement', 'offer.positions.stage.block', 'offer.positions.stage.exits.to'] : [];

        return [...$status, 'offer', 'offer.vendor', 'buyer', 'contract', 'garageCar',
            'invoices' => fn ($q) => $q->where('direction', 'issued')->whereRaw(self::tabSql($tab, split: ! $own))->when($own, fn ($x) => $x->shownToManager())
                ->with(['payments', 'claims', 'payLinks.attempts', 'party'])];
    }

    /**
     * Открытые строки раздела — целиком (идущих сделок сотни): сделки с неоплаченным счётом раздела и идущие без счетов,
     * которым он положен; ручные ссылки без сделки, что ждут денег. Чипы — на сделки.
     *
     * @return Collection<int, MoneyRow>
     */
    public static function open(string $tab, User|null|false $person, ?Facets $facets = null, string $search = '', bool $own = false): Collection
    {
        $q = self::deals($tab, $person, $search, $own)->where(fn ($w) => $w->whereRaw(self::hasTabInvoice($tab, open: true, split: ! $own))->orWhereRaw(self::awaits($tab)));
        $facets?->applyTo($q);
        $rows = $q->select('deals.*')->selectRaw(MoneyStage::sql().' as money_stage')
            ->when($own, fn ($x) => $x->selectRaw(self::hasTabInvoice($tab, open: true).' as tab_open'))
            ->with(self::with($tab, $own))->get()
            ->map(fn (Deal $d) => $own ? self::ownRow($d, $tab) : self::row($d, $tab))->filter()
            // Доля раздела закрыта (машина ПРАЙМ оплачена, подбор нет) — строке тут не место: она в «Оплаченных».
            ->reject(fn (MoneyRow $r) => ! $own && $r->invoices->isNotEmpty() && $r->isPaid());
        // Ручные ссылки менеджеру не видны (`Invoice::visibleToManager`): их сторона — служебная. Засчитанная в сделку ПРАЙМ — и в
        // «ДКП» долей машины (`InvoiceSplit::servicePart`).
        $services = $own ? [] : self::serviceRows($tab, $person, open: true, search: $search);

        return $rows->concat($services)->values();
    }

    /**
     * Оплаченные сделки раздела (все счета раздела оплачены) — запросом, для ленты порциями: свежая оплата сверху.
     */
    public static function paid(string $tab, User|null|false $person, ?Facets $facets = null, string $search = '', bool $own = false): Builder
    {
        // Менеджеру — где есть видимый ему счёт: оплаченное старым зачётом вознаграждения (`Offset`) ему не показывается.
        $q = self::deals($tab, $person, $search, $own)->whereRaw(self::hasTabInvoice($tab, extra: $own ? self::SHOWN : '', split: ! $own))
            ->whereRaw('not '.self::hasTabInvoice($tab, open: true, split: ! $own));
        $facets?->applyTo($q);

        return $q->select('deals.*')->selectRaw(MoneyStage::sql().' as money_stage')->with(self::with($tab, $own))
            // По долям (`split`): машина ПРАЙМ оплачена, а счёт ещё нет — день последней оплаты, а не «в конец ленты».
            ->orderByRaw("(select max(coalesce(bi.paid_at, (select max(bp.paid_at) from billing_payments bp where bp.invoice_id = bi.id and bp.state = 'confirmed' and bp.voided_at is null)))
                from billing_invoices bi where bi.deal_id = deals.id and bi.direction = 'issued' and bi.state <> 'void' and bi.paid > 0 and ".self::tabSql($tab, 'bi', ! $own).') desc nulls last')
            ->orderByDesc('deals.id');
    }

    /** Строки оплаченных сделок порции; `$tab` — по долям раздела («Оплаты» сотрудника). @return Collection<int, MoneyRow> */
    public static function paidRows(iterable $deals, ?string $tab = null): Collection
    {
        return collect($deals)->map(fn (Deal $d) => MoneyRow::deal($d, $d->invoices, tab: $tab))->values();
    }

    /**
     * Ручные ссылки человека строками раздела (`services`): «Комиссии» — без сделки и подбор сверх засчитанного, «ДКП» — машина
     * засчитанной в сделку ПРАЙМ, пока закупочная не набрана (23.11.2026). Доли нет — строки нет. @return Collection<int, MoneyRow>
     */
    public static function serviceRows(string $tab, User|null|false $person, ?bool $open = null, string $search = '', bool $light = false): Collection
    {
        // `light` — только числа (пилюли): без ссылок, попыток и заявок, нужна лишь сделка зачёта.
        $links = self::services($person, $open)->search($search)->when($light, fn ($q) => $q->setEagerLoads(['creditDeal' => fn () => null]))->get();
        $cars = InvoiceSplit::carShares($links);

        return $links->map(fn (Invoice $i) => MoneyRow::service($i, $tab, $cars[$i->id] ?? 0.0))->filter(fn (MoneyRow $r) => $r->hasPart())->values();
    }

    /**
     * Сделки с неоплаченным счётом раздела — числа разделов (без строк «счёта ещё нет»: денег по ним пока не ждём); `$own` —
     * менеджеру: только с отправленным ему счётом.
     */
    public static function openDeals(string $tab, User|null|false $person, ?Facets $facets = null, bool $own = false): Builder
    {
        $q = self::deals($tab, $person, '', $own)->whereRaw(self::hasTabInvoice($tab, open: true, extra: $own ? self::SHOWN : '', split: ! $own));
        $facets?->applyTo($q);

        return $q;
    }

    /**
     * Сколько сделок менеджер должен оплатить — число его «Комиссий» и «ДКП» (сделки с отправленным ему неоплаченным счётом,
     * с «Беру в работу»): значок XCAR Pay и «Оплаты» кабинета сайта (`Nav`) — одно число с экраном.
     */
    public static function ownToPay(User $manager): int
    {
        $facets = self::facets(Request::create('/'), 'account-money');

        return self::openDeals('fees', $manager, $facets, own: true)->count() + self::openDeals('dkp', $manager, $facets, own: true)->count();
    }

    /** Чья ручная ссылка в SQL: покупатель засчитанной сделки, иначе назначенный менеджер (`Invoice::manager`). */
    public const SERVICE_PERSON = '(select cd.buyer_id from deals cd where cd.id = billing_invoices.credit_deal_id)';

    /**
     * Ручная ссылка, засчитанная в сделку, видна своей строкой, пока её деньги не легли в счета сделки целиком: ещё не оплачена
     * (её денег в сделке нет), сделку отменили (деньги, возможно, вернуть) или оплачено больше, чем засчитано (сверх сделки).
     */
    private const CREDITED = "coalesce((select sum(cp.amount) from billing_payments cp where cp.credit_invoice_id = billing_invoices.id
            and cp.source = 'credit' and cp.state = 'confirmed' and cp.voided_at is null), 0)";

    private const CREDIT_SHOWN = "(billing_invoices.credit_deal_id is null or billing_invoices.state = 'issued'
        or exists (select 1 from deals cd where cd.id = billing_invoices.credit_deal_id and cd.state = 'cancelled')
        or billing_invoices.paid > ".self::CREDITED.' + 0.005)';

    /**
     * Ручные ссылки (разовые оплаты, `Invoice::isService`) этого человека своими строками: без сделки и те, что засчитаны в сделку,
     * но её деньгами ещё не стали (`CREDIT_SHOWN`).
     */
    public static function services(User|null|false $person, ?bool $open = null): Builder
    {
        $who = 'coalesce('.self::SERVICE_PERSON.', billing_invoices.manager_id)';

        // Модератору — только засчитанные в сделку: счёт без сделки ему закрыт (`MoneyController::dealOnly`).
        return MoneyController::dealOnly(Invoice::query())->where('kind', ChargeKind::Service->value)->where('direction', 'issued')->whereNull('deal_id')->whereNull('vehicle_id')
            ->where('state', '!=', InvoiceState::Void->value)->whereRaw(self::CREDIT_SHOWN)
            ->select('billing_invoices.*')->selectRaw(self::CREDITED.' as credited')
            ->when($open !== null, fn ($q) => $q->where('state', $open ? InvoiceState::Issued->value : InvoiceState::Paid->value))
            ->when($person !== false, fn ($q) => $person ? $q->whereRaw($who.' = ?', [$person->id]) : $q->whereRaw($who.' is null'))
            ->with(['payments', 'claims', 'payLinks.attempts', 'party', 'offer', 'assignedManager', 'creditDeal.buyer'])
            ->orderByRaw($open === false ? 'paid_at desc nulls last' : 'billing_invoices.id');
    }

    /**
     * Строка сделки: счета раздела есть — она; счетов нет — только если ей положен счёт этого раздела, со словом, чего не
     * хватает (`Deal::invoiceGap`).
     */
    private static function row(Deal $deal, string $tab): ?MoneyRow
    {
        if ($deal->invoices->isNotEmpty()) {
            return MoneyRow::deal($deal, $deal->invoices, tab: $tab);
        }
        if (! self::expects($deal, $tab)) {
            return null;
        }
        $gap = $deal->invoiceGap();
        // Гаражная «платит менеджер»: доля — счёт «Комиссий»; в «ДКП» ждём данные покупателя для счёта за ТС.
        if ($gap === 'share' && $tab === 'dkp') {
            $gap = $deal->invoiceReady() ? 'invoice' : 'passport';
        }

        return $gap === null ? null : MoneyRow::deal($deal, collect(), MoneyRows::gap($gap));
    }

    /**
     * Строка экрана менеджера: есть отправленный ему неоплаченный счёт — к оплате (с оплаченными рядом — их итог не мешает);
     * нет — «Готовим счёт» без суммы: неотправленный ещё проверяют, а сделке без счетов он положен (как `row`).
     */
    private static function ownRow(Deal $deal, string $tab): ?MoneyRow
    {
        if ($deal->invoices->contains(fn (Invoice $i) => $i->state === InvoiceState::Issued)) {
            return MoneyRow::deal($deal, $deal->invoices);
        }
        if (! $deal->getAttribute('tab_open') && (! self::expects($deal, $tab) || ($deal->invoices->isEmpty() && $deal->invoiceGap() === null))) {
            return null;
        }

        return MoneyRow::deal($deal, collect());
    }

    /**
     * Кто есть в истории ручных ссылок раздела — SQL, без загрузки самих ссылок (их за всё время сотни): «Комиссии» — все
     * оплаченные, «ДКП» — засчитанные в сделку ПРАЙМ / Р.О.С. с закупочной (у них бывает доля машины, `InvoiceSplit`).
     */
    public static function servicePeople(string $tab): Collection
    {
        return self::services(false, open: false)->reorder()->setEagerLoads([])
            ->when($tab === 'dkp', fn ($q) => $q->whereHas('creditDeal', fn ($d) => $d->whereIn('scheme', ['prime', 'ros'])->whereNotNull('cost')))
            ->select([])->selectRaw('coalesce('.self::SERVICE_PERSON.', billing_invoices.manager_id) as pid')->distinct()->pluck('pid');
    }

    /**
     * Сделке без счетов положен счёт раздела: «ДКП» — ПРАЙМ / Р.О.С., «Комиссии» — подбор; гаражная «платит менеджер» — обе.
     * «Взяли под себя» (платим мы) и гаражная Совкомбанка до «Продаю» (цены продажи нет, `namesBuyer`) — платить нам не за что:
     * её место — «Гараж» (17.11.2026), а не 90 строк «нет цены продажи».
     */
    private static function expects(Deal $deal, string $tab): bool
    {
        if (! $deal->buyer_id || $deal->garage_payer === GaragePayer::Us || ($deal->namesBuyer() && $deal->amount === null)) {
            return false;
        }
        if ($deal->isGarageManager()) {
            return true;
        }

        // Вознаграждение не назначено — подбор ещё неизвестен, но строка стоит: «назначить» (22.11.2026).
        return $tab === 'dkp' ? $deal->isPrime() || $deal->forGarageBuyer() : ! $deal->isPrime() && ($deal->selectionDue() > 0 || ($deal->paysSelection() && $deal->rewardPending()));
    }
}
