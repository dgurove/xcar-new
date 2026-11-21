<?php

namespace App\Http\Admin;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\MoneyStage;
use App\Garage\GaragePayer;
use App\Offers\Deal;
use App\Support\Facets\Facets;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;
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
    /** Счёт раздела по алиасу `billing_invoices`: «ДКП» — счёт за ТС от ПРАЙМ и Р.О.С., «Комиссии» — остальное наше. */
    public static function tabSql(string $tab, string $i = 'billing_invoices'): string
    {
        return $tab === 'dkp'
            ? "({$i}.kind = 'sale' and {$i}.seller in ('prime', 'ros'))"
            : "({$i}.kind <> 'sale' or {$i}.seller not in ('prime', 'ros'))";
    }

    /** Наш неаннулированный счёт сделки этого раздела (`bi`); `$open` — ещё не оплаченный, `$extra` — ещё условие на него. */
    public static function hasTabInvoice(string $tab, bool $open = false, string $extra = ''): string
    {
        return "exists (select 1 from billing_invoices bi where bi.deal_id = deals.id and bi.direction = 'issued' and bi.state "
            .($open ? "= 'issued'" : "<> 'void'").' and '.self::tabSql($tab, 'bi').($extra !== '' ? ' and ('.$extra.')' : '').')';
    }

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

    /** Сделки раздела: со счётом раздела или идущие без счетов, которым он положен (`awaits`, точно — `expects`). */
    public static function deals(string $tab, User|null|false $person, string $search = ''): Builder
    {
        return Deal::query()->where('deals.parallel', false)
            ->when($person !== false, fn ($q) => $person ? $q->where('deals.buyer_id', $person->id) : $q->whereNull('deals.buyer_id'))
            // Лупа — по машине: номер, убыток, VIN, марка (`Offer::searchCrm`).
            ->when($search !== '', fn ($q) => $q->whereHas('offer', fn ($o) => $o->searchCrm($search)))
            ->where(fn ($w) => $w->whereRaw(self::hasTabInvoice($tab))->orWhereRaw(self::awaits($tab)));
    }

    /** Чип «Статус» раздела, умолчание — после «Беру в работу» и не отменённые; лупа и адрес умолчание снимают. */
    public static function facets(\Illuminate\Http\Request $request): Facets
    {
        // Умолчание в чипе словами — «Взятые в работу», а не «Кроме Не взял в работу, Сделка отменена».
        $default = fn () => in_array((string) $request->query('stage', MoneyStage::DEFAULT), [MoneyStage::DEFAULT, '!'.MoneyStage::CANCELLED.','.MoneyStage::UNTAKEN], true);
        $facets = Facets::for($request, 'crm-money', MoneyStage::facet()->caption(fn () => $default() ? 'Взятые в работу' : null));
        if (! $request->query->has('stage') && trim((string) $request->query('q')) === '') {
            $facets->implied('stage', MoneyStage::DEFAULT);
        }

        return $facets;
    }

    private static function with(string $tab): array
    {
        return ['offer', 'offer.vendor', 'buyer', 'contract', 'garageCar',
            'invoices' => fn ($q) => $q->where('direction', 'issued')->whereRaw(self::tabSql($tab))->with(['payments', 'claims', 'payLinks.attempts', 'party'])];
    }

    /**
     * Открытые строки раздела — целиком (идущих сделок сотни): сделки с неоплаченным счётом раздела и идущие без счетов,
     * которым он положен; ручные ссылки без сделки, что ждут денег. Чипы — на сделки.
     *
     * @return Collection<int, MoneyRow>
     */
    public static function open(string $tab, User|null|false $person, ?Facets $facets = null, string $search = ''): Collection
    {
        $q = self::deals($tab, $person, $search)->where(fn ($w) => $w->whereRaw(self::hasTabInvoice($tab, open: true))->orWhereRaw(self::awaits($tab)));
        $facets?->applyTo($q);
        $rows = $q->select('deals.*')->selectRaw(MoneyStage::sql().' as money_stage')->with(self::with($tab))->get()
            ->map(fn (Deal $d) => self::row($d, $tab))->filter();

        return $rows->concat($tab === 'fees' ? self::services($person, open: true)->search($search)->get()->map(fn (Invoice $i) => MoneyRow::service($i)) : [])->values();
    }

    /**
     * Оплаченные сделки раздела (все счета раздела оплачены) — запросом, для ленты порциями: свежая оплата сверху.
     */
    public static function paid(string $tab, User|null|false $person, ?Facets $facets = null, string $search = ''): Builder
    {
        $q = self::deals($tab, $person, $search)->whereRaw(self::hasTabInvoice($tab))->whereRaw('not '.self::hasTabInvoice($tab, open: true));
        $facets?->applyTo($q);

        return $q->select('deals.*')->selectRaw(MoneyStage::sql().' as money_stage')->with(self::with($tab))
            ->orderByRaw("(select max(bi.paid_at) from billing_invoices bi where bi.deal_id = deals.id and bi.direction = 'issued' and bi.state = 'paid' and ".self::tabSql($tab, 'bi').') desc nulls last')
            ->orderByDesc('deals.id');
    }

    /** Строки оплаченных сделок порции. @return Collection<int, MoneyRow> */
    public static function paidRows(iterable $deals): Collection
    {
        return collect($deals)->map(fn (Deal $d) => MoneyRow::deal($d, $d->invoices))->values();
    }

    /** Сделки с неоплаченным счётом раздела — числа разделов (без строк «счёта ещё нет»: денег по ним пока не ждём). */
    public static function openDeals(string $tab, User|null|false $person, ?Facets $facets = null): Builder
    {
        $q = self::deals($tab, $person)->whereRaw(self::hasTabInvoice($tab, open: true));
        $facets?->applyTo($q);

        return $q;
    }

    /**
     * Ручные ссылки без сделки (разовые оплаты, `Invoice::isService`, не засчитанные в сделку) этого человека.
     */
    public static function services(User|null|false $person, ?bool $open = null): Builder
    {
        return Invoice::query()->where('kind', ChargeKind::Service->value)->where('direction', 'issued')->whereNull('deal_id')->whereNull('vehicle_id')
            ->whereNull('credit_deal_id')->where('state', '!=', InvoiceState::Void->value)
            ->when($open !== null, fn ($q) => $q->where('state', $open ? InvoiceState::Issued->value : InvoiceState::Paid->value))
            ->when($person !== false, fn ($q) => $person ? $q->where('manager_id', $person->id) : $q->whereNull('manager_id'))
            ->with(['payments', 'claims', 'payLinks.attempts', 'party', 'offer', 'assignedManager'])
            ->orderByRaw($open === false ? 'paid_at desc nulls last' : 'billing_invoices.id');
    }

    /**
     * Строка сделки: счета раздела есть — она; счетов нет — только если ей положен счёт этого раздела, со словом, чего не
     * хватает (`Deal::invoiceGap`).
     */
    private static function row(Deal $deal, string $tab): ?MoneyRow
    {
        if ($deal->invoices->isNotEmpty()) {
            return MoneyRow::deal($deal, $deal->invoices);
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

        return $tab === 'dkp' ? $deal->isPrime() || $deal->forGarageBuyer() : ! $deal->isPrime() && $deal->selectionDue() > 0;
    }
}
