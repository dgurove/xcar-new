{{-- Поступления по нашему счёту сделки — строками под документом в «Продаже» (`admin.deals.money-bill`, 23.11.2026, начальник:
     «видно, что пришло и сколько осталось»; владелец: «как принято — „Оплата от 12.11.2026 (ООО «ПРАЙМ», Сбербанк)“, и нажимается»).
     Строка: «Оплата от дд.мм.гггг», под ней — откуда: перевод — наше юрлицо счёта и его банк (Р.О.С. — Альфа-Банк), по ссылке (и
     ручной, засчитанной в сделку) — «по ссылке, Сбербанк» (ЮKassa перечисляет на счёт юрлица), наличные, вознаграждение. Нажатие —
     сама операция выписки в «Настройки → Выписка» (у ссылки — перечисление ЮMoney, куда она вошла); операции нет — окно счёта (у
     засчитанной ссылки — её счёт). Остаток — словом подписи группы, здесь не повторяется. Только подтверждённые деньги
     (`Invoice::payments`); заявки менеджера — своими строками с ответами ниже. --}}
@props(['invoice'])
@php
    use App\Billing\PaymentSource; use App\Support\Money;
    $payments = $invoice->payments;
    $ids = $payments->pluck('id');
    // Операции выписки и перечисления ЮMoney — одним запросом на счёт.
    $txs = $ids->isEmpty() ? collect() : \App\Billing\Bank\Transaction::whereIn('payment_id', $ids)->pluck('id', 'payment_id');
    $payouts = $ids->isEmpty() ? collect() : \App\Billing\Acquiring\AcquiringPayment::whereIn('payment_id', $ids)->whereNotNull('payout_tx_id')->pluck('payout_tx_id', 'payment_id');
    // Засчитанная ручная ссылка: её деньги пришли своим платежом — операция выписки или перечисление ЮMoney этой ссылки.
    $links = $payments->where('source', PaymentSource::Credit)->pluck('credit_invoice_id')->filter()->unique();
    $linkTx = collect();
    $linkPay = collect();
    if ($links->isNotEmpty()) {
        // Как и когда пришли деньги ссылки (перевод 31.10, а в счёт сделки легли зачётом 12.11) — её первая настоящая оплата.
        $linkAll = \App\Billing\Payment::whereIn('invoice_id', $links)->whereNull('voided_at')->where('state', \App\Billing\PaymentState::Confirmed)
            ->orderBy('paid_at')->get()->groupBy('invoice_id');
        // Ссылку оплатили частями — дата последней части, способ словом «частями», нажатие — окно самой ссылки (все части там).
        $linkParts = $linkAll->map->count();
        $linkPay = $linkAll->map->last();
        $own = $linkAll->flatten()->mapWithKeys(fn ($lp) => [$lp->id => $lp->invoice_id]);
        $byPay = \App\Billing\Bank\Transaction::whereIn('payment_id', $own->keys())->pluck('id', 'payment_id')
            ->union(\App\Billing\Acquiring\AcquiringPayment::whereIn('payment_id', $own->keys())->whereNotNull('payout_tx_id')->pluck('payout_tx_id', 'payment_id'));
        $linkTx = $byPay->mapWithKeys(fn ($tx, $pid) => [$own[$pid] => $tx]);
    }
    // Настоящий платёж строки: у зачёта ручной ссылки — платёж самой ссылки.
    $real = fn ($p) => $p->source === PaymentSource::Credit ? ($linkPay[$p->credit_invoice_id] ?? $p) : $p;
    $seller = $invoice->seller?->config() ?? [];
    $bank = (string) ($seller['bank'] ?? '');
    $bank = match (true) {
        $bank === '' => null,
        str_contains(mb_strtoupper($bank), 'СБЕР') => 'Сбербанк',
        str_contains(mb_strtoupper($bank), 'АЛЬФА') => 'Альфа-Банк',
        default => trim(preg_replace('/^(ПАО|АО|ООО)\s+/u', '', str_replace(['«', '»', '"'], '', $bank))),
    };
    $from = fn ($p) => implode(', ', array_filter([match ($real($p)->source) {
        PaymentSource::Acquiring, PaymentSource::Credit => 'по ссылке',
        PaymentSource::Cash => 'наличными',
        PaymentSource::Offset => 'вознаграждением менеджера',
        default => $seller['name'] ?? null,
    }, in_array($real($p)->source, [PaymentSource::Cash, PaymentSource::Offset], true) ? null : $bank,
        $p->source === PaymentSource::Credit && ($linkParts[$p->credit_invoice_id] ?? 1) > 1 ? 'частями' : null]));
    // Куда ведёт строка: операция выписки (`?peek=`), у засчитанной ссылки без операции — её счёт; наличные и вознаграждение
    // смотреть негде — строка не нажимается.
    // Выписка — в Настройках CRM: из XCAR Pay (сделка шторкой) — адресом CRM.
    $bankAt = fn ($id) => \App\Support\Surface::Crm->url('/settings/bank/statement?preset=all&peek='.$id);
    $href = fn ($p) => match (true) {
        $txs->has($p->id) => $bankAt($txs[$p->id]),
        $payouts->has($p->id) => $bankAt($payouts[$p->id]),
        $p->source === PaymentSource::Credit && ($linkParts[$p->credit_invoice_id] ?? 1) === 1 && $linkTx->has($p->credit_invoice_id) => $bankAt($linkTx[$p->credit_invoice_id]),
        $p->source === PaymentSource::Credit && $p->credit_invoice_id => '/work/money/invoices/'.$p->credit_invoice_id,
        default => null,
    };
@endphp
@foreach ($payments as $p)
    @php $to = $href($p); @endphp
    <{{ $to ? 'a' : 'div' }} @if ($to) href="{{ $to }}" data-turbo-frame="_top" @endif class="row deal-receipt">
        <span class="min-w-0 flex-1">
            <span class="block">Оплата{{ ($at = $real($p)->paid_at ?? $real($p)->created_at) ? ' от '.$at->format('d.m.Y') : '' }}</span>
            <span class="row-sub truncate">{{ $from($p) }}</span>
        </span>
        <span class="nums shrink-0">{{ Money::rub((float) $p->amount) }}</span>
        @if ($to)<x-ui.chevron/>@else<x-ui.chevron-space/>@endif
    </{{ $to ? 'a' : 'div' }}>
@endforeach
