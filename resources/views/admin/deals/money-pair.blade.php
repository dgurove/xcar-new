{{-- Счёт за ТС и ссылка на подбор — одной группой «Счёт и подбор» (25.11.2026, владелец: «объединить и у обоих одну кнопку
     „Отправить“, которая и то и то отправляет»; «„Отозвать“ — и то и то»; «„Скачать“ убери — по клику по строке и так могут»;
     «„Оплата получена…“ без галочки, там выбор, за что, в какой форме и сколько», «редактировать можно», «„Получено“ под кнопками»;
     «справа — что осталось, в подписи — полная»; «перед „Напомнить“ колокольчик»). Кусок `x-deal.money`: `deal`, `sale`, `selection`,
     `billRows` (заявки менеджера), `last` (живая ссылка счёта), `canEdit`. --}}
@php
    use App\Support\Money; use App\Billing\InvoiceState; use App\Billing\PaymentSource;
    $pair = ['sale' => $sale, 'selection' => $selection];
    $sent = $sale->isShownToManager() && $selection->isShownToManager();
    $open = collect($pair)->filter(fn ($i) => $i->state === InvoiceState::Issued && $i->remaining() > 0);
    $left = $open->sum(fn ($i) => $i->remaining());
    $claims = $sale->claims->isNotEmpty() || $selection->claims->isNotEmpty();
    $word = match (true) {
        ! $sent => ['не отправлены', 'bad'],
        $open->isEmpty() => ['оплачены', 'ok'],
        $claims => ['ждём подтверждения', 'muted'],
        $sale->paid > 0 || $selection->paid > 0 => ['осталось '.Money::rub($left), 'due'],
        default => ['отправлены '.\App\Support\Ago::day(max($sale->shown_at, $selection->shown_at)), 'ok'],
    };
    $link = $selection->state !== InvoiceState::Paid ? $last($selection, true) : null;
    $sub = function ($i, ?string $state = null) {
        $parts = [$i->paid > 0 ? 'из '.Money::rub((float) $i->total) : ($i->seller?->config()['name'] ?? null), $state];

        return implode(', ', array_filter($parts));
    };
    $pdf = $sale->hasPdf() ? '/work/money/invoices/'.$sale->id.'/pdf'.$sale->pdfStamp() : \App\Http\Admin\MoneyController::url($sale);
    $payments = collect($pair)->flatMap(fn ($i, $k) => $i->payments->map(fn ($p) => [$p, $k]))->sortBy(fn ($r) => [$r[0]->paid_at, $r[0]->id])->values();
    $recall = $canEdit && collect($pair)->contains(fn ($i) => \App\Offers\Actions\ShowToManager::canRecallInvoice($i));
    $nudge = $canEdit && collect($pair)->contains(fn ($i) => \App\Billing\Actions\NudgeInvoice::allows($i));
    $source = fn ($p) => match (true) {
        $p->source === PaymentSource::Cash => 'Наличными',
        $p->source === PaymentSource::Acquiring => 'По ссылке',
        $p->source === PaymentSource::Bank && ! \App\Http\Admin\DealContractController::manualPayment($p) => 'Из выписки',
        $p->source === PaymentSource::Bank => 'Безнал',
        default => $p->source->label(),
    };
@endphp
<x-deal.doc-cap label="Счёт и подбор" :state="$word[0]" :tone="$word[1]"/>
<div class="flex flex-col gap-2 mb-3 last:mb-0">
    <div class="list">
        <a href="{{ $pdf }}" target="_blank" data-doc="pdf" data-doc-name="Счёт за ТС" class="row pair-row">
            <x-ui.file-icon name="schet.pdf" mime="application/pdf"/>
            <span class="min-w-0 flex-1"><span class="block">Счёт за ТС</span>@if ($s = $sub($sale))<span class="row-sub">{{ $s }}</span>@endif</span>
            @if ($sale->state === InvoiceState::Paid)<span class="shrink-0 text-open">оплачен</span>@else<span class="nums shrink-0">{{ Money::rub($sale->remaining()) }}</span>@endif
        </a>
        <div class="row pair-row">
            <span class="bill-link-icon"><x-ui.icon name="link" class="size-5"/></span>
            <span class="min-w-0 flex-1"><span class="block">Подбор по ссылке</span>@if ($s = $sub($selection, $sent && $link ? \App\Http\Admin\MoneyLinks::short($link)[0] : null))<span class="row-sub">{{ $s }}</span>@endif</span>
            @if ($selection->state === InvoiceState::Paid)<span class="shrink-0 text-open">оплачен</span>@else<span class="nums shrink-0">{{ Money::rub($selection->remaining()) }}</span>@endif
        </div>
    </div>
    @if ($canEdit && $open->isNotEmpty())
        {{-- Реквизиты покупателя в счёте не проверены — «Проверить и отправить менеджеру» (26.11.2026, `x-deal.buyer-check`). --}}
        @if (! $sent && \App\Offers\Actions\CheckBuyer::onInvoice($deal, $sale) && $deal->contract?->needsBuyerCheck())
            <x-deal.buyer-check :deal="$deal" then="bills" class="w-full"/>
        @elseif (! $sent)
            <form method="post" action="/work/deals/{{ $deal->id }}/bills/send" class="contents">@csrf
                <button type="submit" class="btn btn-accent btn-s w-full gap-2"><x-ui.icon name="send" class="size-4"/>Отправить менеджеру</button>
            </form>
        @endif
        <div class="contents" data-controller="sheet">
            <button type="button" class="btn btn-quiet btn-s w-full" data-action="sheet#open" aria-controls="pay-new-{{ $deal->id }}">Оплата получена…</button>
            <x-deal.payment-sheet :deal="$deal" :bills="$pair" :id="'pay-new-'.$deal->id"/>
        </div>
        @if ($sent && ($nudge || $recall))
            <div @class(['grid gap-2', 'grid-cols-2' => $nudge && $recall])>
                @if ($nudge)<x-deal.nudge-sheet :deal="$deal" :invoice="$sale->isShownToManager() ? $sale : $selection" :action="'/work/deals/'.$deal->id.'/bills/nudge'" what="счёт и ссылку на подбор" icon="bell" class="w-full"/>@endif
                @if ($recall)
                    <form method="post" action="/work/deals/{{ $deal->id }}/bills/recall" class="contents" {!! \App\Offers\Warnings::html('Менеджер перестанет видеть счёт и ссылку на подбор, их можно будет поправить и отправить снова', 'Да, отозвать') !!}>@csrf
                        <button type="submit" class="btn btn-quiet btn-s w-full">Отозвать</button>
                    </form>
                @endif
            </div>
        @endif
    @endif
    {{-- Заявки менеджера «Оплатил…» — со своими «Поступила / Не поступила». --}}
    @foreach ($pair as $i)
        @if ($i->claims->isNotEmpty())<div class="list">{!! $billRows($i, false, true) !!}</div>@endif
    @endforeach
    @if ($payments->isNotEmpty())
        <div class="list-cap !px-0 mt-2">Получено</div>
        <div class="list">
            @foreach ($payments as [$p, $k])
                @php $manual = $canEdit && \App\Http\Admin\DealContractController::manualPayment($p); @endphp
                @if ($manual)
                    <div class="contents" data-controller="sheet">
                        <button type="button" class="row sale-row" data-action="sheet#open" aria-controls="pay-{{ $p->id }}">
                            <span class="min-w-0 flex-1"><span class="block">{{ $source($p) }}, {{ $p->paid_at->format('d.m') }}</span><span class="row-sub">{{ $k === 'sale' ? 'за счёт за ТС' : 'за подбор' }}</span></span>
                            <span class="nums shrink-0">{{ Money::rub((float) $p->amount) }}</span><x-ui.chevron/>
                        </button>
                        <x-deal.payment-sheet :deal="$deal" :bills="$pair" :payment="$p" :id="'pay-'.$p->id"/>
                    </div>
                @else
                    <a href="{{ \App\Http\Admin\MoneyController::url($p->invoice ?? $pair[$k]) }}" data-turbo-frame="_top" class="row sale-row">
                        <span class="min-w-0 flex-1"><span class="block">{{ $source($p) }}, {{ $p->paid_at->format('d.m') }}</span><span class="row-sub">{{ $k === 'sale' ? 'за счёт за ТС' : 'за подбор' }}</span></span>
                        <span class="nums shrink-0">{{ Money::rub((float) $p->amount) }}</span><x-ui.chevron/>
                    </a>
                @endif
            @endforeach
        </div>
    @endif
</div>
