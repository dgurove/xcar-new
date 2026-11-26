<?php

namespace App\Offers\Actions;

use App\Billing\Actions\IssueInvoice;
use App\Billing\Actions\VoidInvoice;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Party;
use App\Billing\PaymentSource;
use App\Billing\Seller;
use App\Notifications\Notify;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Счета сделки ставятся сами по её схеме (05.10.2026, владелец: «что такое "Выставите счёт"?» — у каждой схемы известно,
 * кому и какой счёт). Два места:
 * — подбор (`Selection`) менеджеру со ссылкой: у ДКП и «страховой напрямую» — цена − собственнику/страховой −
 *   вознаграждение (850 − 750 − 20 = 80 000) одной строкой; у гаражной «платит менеджер» — наша доля;
 * — ПРАЙМ / Р.О.С. (`Sale`) без ссылки: одной строкой «Транспортное средство» на цену минус вознаграждение менеджера
 *   (18.11.2026, начальник: вознаграждения мы не выплачиваем никогда — из того, что приходит нам, закупочная за ТС, остальное
 *   — услуги подбора; владелец: «лучше одной» строкой) тому, кого менеджер указал плательщиком; гаражной «платит менеджер»
 *   — менеджеру на закупочную.
 * Счёт, что уже стоит как надо, не трогаем; другой, за который не платили, — аннулируем и ставим заново; оплаченный —
 * история, его не меняем. Не нужен схеме — не трогаем вовсе. Пока сделка согласуется с поставщиком (`Deal::agreed`),
 * счетов нет вовсе (09.10.2026): первые ставит `AgreeDeal`.
 * Разовые оплаты по ссылке с машиной сделки (`Deal::credits`, 09.10.2026, владелец: «пусть это будет зачёт суммы
 * сделки») ложатся в её счета зачётом (`ApplyDealCredits`) — счёт остаётся на свою сумму, ссылка сделки встаёт на остаток;
 * ручная ссылка при этом живёт дальше («пусть обе ссылки работают»).
 */
final class SyncDealInvoices
{
    // Срок оплаты менеджером — 14 дней (27.10.2026, владелец: «5 дней мало, обычно 14», и «у остальных тоже»).
    private const DAYS = 14;

    public function __construct(private IssueInvoice $issue, private VoidInvoice $void, private ApplyDealCredits $credits) {}

    public function __invoke(Deal $deal, User $by): void
    {
        // Параллельная (07.11.2026) счетов не имеет: они встанут, когда её возьмут в работу (`TakeParallel`).
        if ($deal->isParallel()) {
            return;
        }
        $this->adopt($deal);
        // Счёт — сразу, как есть реквизиты, и до ответа поставщика (16.11.2026, владелец по сделке 412: «счёт должен сразу
        // формироваться, даже если этап оплаты ещё не наступил»; до того ждали `agreed()`). Менеджер его не видит, пока
        // сотрудник не нажмёт «Отправить менеджеру» (`ShowToManager`), срока до шага оплаты нет (`dueFor`).
        $deal->loadMissing(['offer', 'buyer', 'contract.buyer']);
        // Цена договора ПРАЙМ идёт за счётом — плательщик, цена и вознаграждение меняются по ходу (гаражная Совкомбанка
        // 27.10.2026, продажа — 16.11.2026: платит покупатель — минус вознаграждение; 18.11.2026 — и минус наличные мимо счёта).
        if (($deal->namesBuyer() || $deal->isPrime() || $deal->forGarageBuyer()) && $deal->contract && $deal->contract->price !== ($price = DealContract::priceFor($deal))) {
            $deal->contract->update(['price' => $price]);
        }
        DB::transaction(function () use ($deal, $by) {
            $this->dropPendingSelection($deal, $by);
            $this->slot($deal, $by, ChargeKind::Selection, $this->selection($deal));
            $this->slot($deal, $by, ChargeKind::Sale, $this->prime($deal));
        });
        ($this->credits)($deal, $by);
        $this->syncTurn($deal);
    }

    /**
     * Этап оплаты без счёта: чей ход — по тому, чего не хватает (`Deal::managerGap`). Гаражная Совкомбанка проходит
     * «цена» → «покупатель» (ход менеджера) → «вознаграждение» (ход наш), пока счёт не встанет.
     */
    private function syncTurn(Deal $deal): void
    {
        $position = Position::where('offer_id', $deal->offer_id)->where('track', Track::Sale->value)->with('stage.exits')->first();
        if (! $deal->isActive() || ! $position?->stage->isPayStep() || $deal->hasManagerInvoice()) {
            return;
        }
        // Платят поставщику сами по его счёту («Поставщику», ДКП, и гаражная «платит менеджер», 19.11.2026) — ход менеджера, как
        // при входе в шаг (`EnterStage`): наш счёт подбора (или его отсутствие) оплату страховой не держит.
        $who = $deal->managerGap() || $deal->paysSupplierOn($position->stage) ? WaitsFor::Manager : WaitsFor::Us;
        if ($position->waits_for !== $who) {
            // Ход менеджера («Укажите покупателя» после «Продаю») — часы оплаты (`Deal::payDue`), наш — без часов.
            $due = $who === WaitsFor::Manager && in_array($deal->invoiceGap(), ['buyer', 'passport'], true) ? $deal->payDue() : null;
            $position->update(['waits_for' => $who, 'deadline_at' => $due, 'reminded_at' => null, 'overdue_at' => null]);
            // Менеджер назвал цену и покупателя — дело за нами: без уведомления сделка молча ждала бы вознаграждение.
            if ($who === WaitsFor::Us) {
                app(Notify::class)->invoiceNeeded($deal, $position->gap());
            }
        }
    }

    /**
     * Ручная ссылка с машиной этой сделки, взятая до сделки или без привязки (Тужиков 07.10.2026: оплатил 53 000 ручной
     * ссылкой, сделка назавтра выставила ему 58 000), — засчитывается в неё. Привязанную к другой сделке не трогаем.
     */
    private function adopt(Deal $deal): void
    {
        if (! $deal->isActive()) {
            return;
        }
        Invoice::where('offer_id', $deal->offer_id)->where('kind', ChargeKind::Service)->whereNull('deal_id')->whereNull('vehicle_id')
            ->whereNull('credit_deal_id')->where('state', '!=', InvoiceState::Void)->update(['credit_deal_id' => $deal->id]);
        // Засчитанная в сделку, которую потом отменили (и вернули в продажу), а выиграл тот же человек: его деньги — в
        // новую сделку, а не висеть на отменённой (иначе новой выставлялась полная сумма).
        $party = $deal->buyer?->party_id;
        if ($party) {
            Invoice::where('offer_id', $deal->offer_id)->where('kind', ChargeKind::Service)->whereNull('deal_id')->whereNull('vehicle_id')
                ->where('party_id', $party)->where('state', '!=', InvoiceState::Void)
                ->whereIn('credit_deal_id', Deal::where('offer_id', $deal->offer_id)->cancelled()->select('id'))
                ->update(['credit_deal_id' => $deal->id]);
        }
    }

    /**
     * Вознаграждение не назначено (`Deal::rewardPending`) — подбор неизвестен: системный счёт подбора, что менеджеру ещё не
     * отправлен, без денег и заявок, гасится (22.11.2026, владелец: «нельзя писать, что он нам якобы должен 80 тыщ»). Назначили —
     * встанет заново на долю минус вознаграждение. Отправленный, оплаченный и выставленный руками («Ещё счёт» — сумма не та, что
     * считала система без вознаграждения) остаются. Её же зовёт миграция `selection_waits_reward` — без остальной синхронизации.
     */
    public function dropPendingSelection(Deal $deal, User $by): void
    {
        if (! $deal->rewardPending() || ! ($deal->paysSelection() || $deal->splitsSelection())) {
            return;
        }
        // Сколько система ставила в счёт подбора, пока пустое вознаграждение считалось нулём.
        $auto = $deal->splitsSelection() ? (int) $deal->linkDue() : (int) $deal->selectionBase();
        Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('kind', ChargeKind::Selection)->where('state', InvoiceState::Issued)
            ->whereNull('shown_at')->whereNull('requested_at')->where('paid', '<=', 0)->with(['payments', 'claims', 'charges'])->get()
            ->filter(fn (Invoice $i) => $i->claims->isEmpty() && $i->payments->whereNull('voided_at')->isEmpty()
                && abs((float) $i->charges->whereNull('voided_at')->sum(fn ($c) => $c->price * $c->qty) - $auto) < 0.01)
            ->each(fn (Invoice $i) => ($this->void)($i, $by, 'Вознаграждение не назначено'));
    }

    /** @return array{party: Party, lines: list<array>, vat?: ?int}|null */
    private function selection(Deal $deal): ?array
    {
        $due = $deal->selectionDue();
        if ($due <= 0 || ! $deal->buyer) {
            return null;
        }
        // Подбор отдельно от счёта за ТС (выбором, `Deal::splitsSelection`) — вместе с ним, не раньше: иначе счёт
        // подбора закрывал бы ход менеджера «Укажите покупателя».
        if ($deal->splitsSelection() && ! $this->prime($deal)) {
            return null;
        }
        // Услуги подбора от Р.О.С. (`Deal::feeSeller`) без его реквизитов — счёт с прочерками не выставляем, как у продажи.
        if ($deal->feeSeller() !== Seller::Prime && ! Party::seller($deal->feeSeller())->billable()) {
            return null;
        }

        // Плательщика у комиссии нет (18.11.2026, владелец: «плательщик указывается только для выставления договора счёта (ДКП),
        // для комиссий нет»): сторона счёта — менеджер, внутри, нигде не показывается; платит по ссылке кто угодно, на `/pay` — почта.
        return ['party' => Party::forUser($deal->buyer),
            'lines' => [['title' => 'Услуги подбора ТС', 'qty' => 1, 'unit' => 'pc', 'price' => (float) $due, 'kind' => ChargeKind::Selection->value]]];
    }

    /** @return array{party: Party, lines: list<array>, vat?: ?int}|null */
    private function prime(Deal $deal): ?array
    {
        // Покупатель с гаражом — одна строка на цену продажи (26.11.2026; прежде — на его цену гаража, а она часто закупочная),
        // какой бы ни была схема.
        if ($deal->forGarageBuyer()) {
            $price = $deal->amount;

            // Платит покупатель по договору (его клиент, 24.11.2026); пока клиента нет — счёта нет (26.11.2026, владелец: «ждать
            // клиента»; «Я сам» — тоже клиент). НДС — ставка договора (24.11.2026, владелец: «почему НДС в счёте, если Совком без
            // НДС»): ПРАЙМ продаёт по комиссии, налог с машины — по режиму собственника (ст. 156 НК РФ), а не 5 % ПРАЙМ.
            return $price && ($deal->payer_party_id || $deal->contract?->buyer_user_id) ? ['party' => $deal->payerParty(sale: true), 'vat' => DealContract::for($deal)->vatRate(),
                'lines' => [['title' => $deal->offer->invoiceTitle(), 'qty' => 1, 'unit' => 'pc', 'price' => (float) $price, 'kind' => ChargeKind::Sale->value]]] : null;
        }
        if (! $deal->isPrime() || $deal->cost === null || ! $deal->buyer) {
            return null;
        }
        $car = $deal->offer->invoiceTitle();
        // Р.О.С. без реквизитов — счёт с прочерками не выставляем: ход наш «Заполните реквизиты Р.О.С.» (`Deal::invoiceGap`).
        // До гаражной ветки: гаражная Альфы с НДС тоже от Р.О.С.
        if ($deal->seller() !== Seller::Prime && ! $deal->seller()->party()->billable()) {
            return null;
        }
        // Гаражная Совкомбанка (`namesBuyer`) — как обычная ПРАЙМ: счёт на цену продажи тому, кого назвал менеджер.
        if ($deal->isGarage() && ! $deal->namesBuyer()) {
            return $deal->isGarageManager() && $deal->invoiceReady()
                ? ['party' => $deal->payerParty(), 'lines' => [['title' => $car, 'qty' => 1, 'unit' => 'pc', 'price' => (float) $deal->cost, 'kind' => ChargeKind::Sale->value]]]
                : null;
        }
        $contract = $deal->contract;
        // Ставка НДС счёта за ТС — ставка договора (14.11.2026, блок «НДС» в «Деньгах», `DealContract::vatRate`).
        $vat = $contract ? $contract->vatRate() : null;
        // Гаражной Совкомбанка ещё и вознаграждение: его считаем мы, когда менеджер назвал цену продажи.
        if (! $contract?->buyer_user_id || $deal->amount === null || ($deal->namesBuyer() && $deal->commission === null)) {
            return null;
        }
        // Покупатель — только с тем, что счёт печатает (18.11.2026, владелец: «счёт невозможно сформировать, пока не указаны:
        // ФИО, адрес прописки»; до того требовали паспорт со сканом — 11.10.2026, — а он нужен договору). Уже выставленный
        // счёт не трогаем: `slot` без нужного счёта ничего не гасит.
        // Деньги уже пришли (зачёт по VIN или ручная оплата, 12.11.2026, владелец: «паспорт нужен только для выставления
        // счёта — если бабки пришли, всё равно на паспорт») — счёт встаёт без паспорта и закрывается зачётом; договору
        // паспорт по-прежнему нужен (`Deal::contractBuyerTask`).
        if (! $deal->invoiceReady() && $deal->credited() <= 0) {
            return null;
        }
        // Одной строкой на цену минус вознаграждение (18.11.2026): кто бы ни платил — менеджер, его покупатель, супер-покупатель
        // (у него вознаграждения нет), и минус наличные мимо счёта — та же сумма, что в договоре (`DealContract::priceFor`).
        // Закупочная внутри счёта не видна. Кто платит — одна дверь `Deal::payerParty`.
        $price = (int) DealContract::priceFor($deal);

        return ['party' => $deal->payerParty(sale: true), 'vat' => $vat,
            'lines' => [['title' => $car, 'qty' => 1, 'unit' => 'pc', 'price' => (float) $price, 'kind' => ChargeKind::Sale->value]]];
    }

    private function slot(Deal $deal, User $by, ChargeKind $kind, ?array $want): void
    {
        // Схеме этот счёт сейчас не нужен — что выставили руками («Ещё счёт»), не трогаем; при смене схемы прежний
        // гасит `UpdateDealMoney`.
        if (! $want) {
            return;
        }
        $current = Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('kind', $kind)->where('state', '!=', InvoiceState::Void)
            ->with(['payments', 'claims', 'charges'])->get();
        $total = $want ? round(array_sum(array_map(fn ($l) => $l['price'] * $l['qty'], $want['lines'])), 2) : null;
        // Сверяем строки, а не итог: у плательщика с «НДС сверху» итог больше строк.
        $seller = match (true) {
            $kind === ChargeKind::Sale && ! $deal->forGarageBuyer() => $deal->seller(),
            // Услуги подбора — продавцом, что выбран строкой «Плательщик платит» (16.11.2026); не тот — счёт перевыставляется (`$fits`).
            $kind === ChargeKind::Selection => $deal->feeSeller(),
            default => Seller::Prime,
        };
        // Ставка НДС, которой счёт должен стоять (`vat` — ставка договора, иначе продавца), как её хранит `IssueInvoice`.
        $rate = (($want['vat'] ?? null) ?? $seller->vatRate()) ?: null;
        $vatFits = fn (Invoice $i) => ($i->vat_rate ?: null) === $rate;
        // Сменили схему ПРАЙМ ↔ Р.О.С. — счёт другого продавца перевыставляется.
        // Сторона подбора — не повод перевыставлять (18.11.2026): у 13 счетов стоял выбранный раньше плательщик, их ссылки живут.
        $fits = fn (Invoice $i) => $want && $i->seller === $seller && ($kind === ChargeKind::Selection || $i->party_id === $want['party']->id) && abs((float) $i->charges->whereNull('voided_at')->sum(fn ($c) => $c->price * $c->qty) - $total) < 0.01
            // Зачёт «удержано агентское вознаграждение» был до 18.11.2026 — счёт с ним перевыставляется одной строкой.
            && $i->payments->where('source', PaymentSource::Offset)->whereNull('voided_at')->isEmpty();
        // Зачёт оплаты по ссылке — не оплата этого счёта: счёт перевыставляется, зачёт ляжет в новый (`ApplyDealCredits`).
        $paid = fn (Invoice $i) => $i->claims->isNotEmpty() || $i->payments->contains(fn ($p) => ! $p->source->isSoft());

        $keep = $current->first(fn (Invoice $i) => $fits($i) || $paid($i));
        // Запрошенный счёт за подбор («Запросить счёт…», `RequestFeeInvoice`) остаётся запрошенным и после перевыставления.
        $requested = $current->first(fn (Invoice $i) => $i->requested_at !== null);
        // Сменилась только ставка НДС, а не платили — тот же счёт с новой ставкой, без новой нумерации (14.11.2026). НДС
        // «сверху» меняет итог — такой перевыставляется.
        if ($keep && ! $paid($keep) && ! $vatFits($keep)) {
            if ($keep->vat_on_top || ($rate && $keep->party?->vat_on_top)) {
                $keep = null;
            } else {
                $keep->update(['vat' => (bool) $rate, 'vat_rate' => $rate]);
                $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Счёт '.$keep->label().': '.($rate ? 'НДС '.$rate.' %' : 'без НДС')]);
            }
        }
        foreach ($current as $invoice) {
            if ($invoice->isNot($keep) && ! $paid($invoice)) {
                ($this->void)($invoice, $by, 'Деньги сделки изменены');
            }
        }
        if ($keep) {
            return;
        }
        $invoice = ($this->issue)($want['party'], $by, 'issued', $kind, $this->dueFor($deal, $kind), lines: $want['lines'], dealId: $deal->id, offerId: $deal->offer_id, seller: $seller, vatRate: $want['vat'] ?? null);
        if ($requested && $kind === ChargeKind::Selection) {
            $invoice->forceFill(['requested_at' => $requested->requested_at, 'requested_by' => $requested->requested_by])->save();
        }
        $deal->offer->log(OfferEventType::Note, $by, ['text' => $invoice->isDocument() ? 'Счёт '.$invoice->label().' на '.Money::rub($invoice->total).' — '.$want['party']->name : 'Подбор к оплате: '.Money::rub($invoice->total)]);
    }

    /**
     * Срок счёта. До шага оплаты — без срока (16.11.2026, владелец: «по срокам ему мозг не выносить, в счёте без строки про
     * срок оплаты»; подбор так и жил с 06.10.2026): срок и строку «Оплатить до» ставит сам шаг (`StartInvoiceDue`). На шаге
     * оплаты — его часы; гаражная и маршрут без шага оплаты — 14 дней.
     */
    private function dueFor(Deal $deal, ChargeKind $kind): ?Carbon
    {
        $default = Carbon::now()->addDays(self::DAYS);
        if ($deal->forGarageBuyer()) {
            return $default;
        }
        $deal->offer->unsetRelation('positions');
        $position = $deal->offer->position(Track::Sale);
        // На шаге оплаты — срок шага от «Забрал» (`Deal::payDue`, 27.10.2026): счёт и строка «Сделок» говорят одну дату.
        if ($position?->stage->isPayStep()) {
            return $deal->payDue() ?? $position->deadline_at ?? $default;
        }
        // Гаражная: машину и долю менеджер платит за себя, срок — как был, 14 дней.
        if (! $position || $deal->isGarage()) {
            return $default;
        }

        return self::routePays($position) ? null : $default;
    }

    /** В маршруте позиции есть шаг оплаты — тогда срок счёту ставит он. */
    public static function routePays(Position $position): bool
    {
        return Stage::where('workflow_id', $position->stage->workflow_id)->with('exits')->get()->contains(fn (Stage $s) => $s->isPayStep());
    }
}
