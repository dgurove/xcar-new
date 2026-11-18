<?php

namespace App\Http\Admin;

use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Live\Rows;
use App\Offers\CarWindow;
use App\Offers\OfferNumber;
use App\Support\Detail;
use App\Support\Facets\Common;
use App\Support\Facets\Facets;
use App\Support\ListPrefs;
use App\Support\Table\ColRole;
use App\Support\Table\Column;
use App\Support\Table\Table;
use Illuminate\Http\Request;

/**
 * «Оплата за подбор» — вкладка колеса «Предложений» (18.11.2026, владелец: «пусть отображаются сделки параллельно с тем, как они в
 * других вкладках; здесь, как и в разделе оплаты, — по которым выставлены, но не оплачены ссылки (они тихие), и по которым ссылки не
 * выставлены, но у них этап оплата (вот они — красный счётчик)»). Счета те же, что в «Оплатах → Комиссии» (`MoneyController::of`),
 * только с машиной; сделка остаётся и в своей вкладке этапа — как «Гараж». Группы и строка — как у страницы менеджера в «Оплатах»
 * (`x-money.person-row`): «Не выставлены» с «Выставить», «Выставлены» с тем, сколько ждём; менеджер — столбцом.
 */
class SelectionController
{
    /** Машина счёта: своя у счёта, иначе сделки, иначе гаража (`Invoice::carOffer`). */
    private const OFFER_SQL = 'coalesce(billing_invoices.offer_id, (select d.offer_id from deals d where d.id = billing_invoices.deal_id),
        (select g.offer_id from garage_cars g where g.invoice_id = billing_invoices.id limit 1))';

    /** Открытые счета подбора с машиной. */
    private static function open(?Facets $facets = null)
    {
        return MoneyController::of('fees', $facets)->where('state', InvoiceState::Issued)->whereRaw(self::OFFER_SQL.' is not null');
    }

    /** Машины вкладки — серое число колеса (`OfferController::scopeFor`). @return list<int> */
    public static function ids(): array
    {
        return self::open()->selectRaw(self::OFFER_SQL.' as offer_id')->distinct()->pluck('offer_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Машины, где ссылку ещё не выставили, а сделка на шаге оплаты, — красный счётчик. Неотправленный счёт в «Комиссиях» и есть
     * только такой (`Invoice::ON_PAY_STEP_SQL`). @return list<int>
     */
    public static function unsentIds(): array
    {
        return self::open()->whereNotNull('deal_id')->whereNull('shown_at')->where('paid', '<=', 0)
            ->selectRaw(self::OFFER_SQL.' as offer_id')->distinct()->pluck('offer_id')->map(fn ($id) => (int) $id)->all();
    }

    public function tab(Request $request)
    {
        $detail = Detail::of($request, fn (string $key) => CarWindow::of($request, OfferNumber::find($key)));
        if ($detail->framed()) {
            return $detail->response();
        }
        $facets = Facets::for($request, 'crm-selection',
            Common::manager(MoneyController::MANAGER),
            Common::vendor('(select o.vendor_id from offers o where o.id = '.self::OFFER_SQL.')'),
        )->saving('offers');
        ListPrefs::sync($request, 'crm-selection', keep: $facets->keys());

        $q = self::open()->with(['party', 'charges', 'payments', 'offer', 'deal.offer', 'deal.buyer.media', 'garageCar.manager.media', 'garageCar.offer', 'payLinks.attempts', 'claims']);
        // Лупа — по машине, мимо чипов (как у остальных вкладок колеса).
        if ($facets->searching()) {
            $term = trim((string) $request->query('q'));
            $q->whereIn('billing_invoices.id', MoneyController::of('fees')->search($term)->select('billing_invoices.id'));
        } else {
            $facets->apply($q);
        }
        $bills = $q->orderBy('billing_invoices.id')->get();
        $total = $bills->count();
        $bills = Rows::filter($bills, fn (Invoice $i) => $i->carOffer()?->number);
        // Не выставлены — дольше ждущие выше; выставлены — раньше отправленные выше (дольше ждём оплату).
        [$issued, $unsent] = $bills->partition(fn (Invoice $i) => $i->isShownToManager());
        // Менеджер сообщил, что оплатил (наличными, переводом) — своей группой «Подтвердить оплату» (18.11.2026, владелец).
        [$claimed, $issued] = $issued->partition(fn (Invoice $i) => $i->claims->isNotEmpty());
        $user = $request->user();

        return view('admin.selection.index', [
            'detail' => $detail,
            'unsent' => $unsent->sortBy(fn (Invoice $i) => $i->created_at?->getTimestamp() ?? 0)->values(),
            'claimed' => $claimed->sortBy(fn (Invoice $i) => $i->claims->min('paid_at')?->getTimestamp() ?? 0)->values(),
            'issued' => $issued->sortBy(fn (Invoice $i) => ($i->shown_at ?? $i->created_at)?->getTimestamp() ?? 0)->values(),
            'total' => $total,
            'facets' => $facets,
            'presets' => OfferController::presets($user),
            'sections' => OfferController::sections($user),
            'counts' => OfferController::counts($user),
        ]);
    }

    /** Столбцы — как у страницы менеджера в «Оплатах», плюс менеджер. */
    public static function table(): Table
    {
        return Table::of(new Column('title', 'Марка, модель', grow: true), new Column('status', 'Статус', from: 'sm', role: ColRole::State, rem: 12),
            new Column('manager', 'Менеджер', from: 'sm', role: ColRole::Who, rem: 9),
            new Column('amount', 'Сумма', num: true, cell: 'nums', rem: 7), new Column('since', 'Выставлено', from: 'sm', num: true, role: ColRole::When, cell: 'nums', rem: 7.5));
    }
}
