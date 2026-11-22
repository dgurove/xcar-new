{{-- Строки одного нашего счёта в «Продаже» (`x-deal.money`, кусок `admin.deals.money-bill`): документ (без суммы — она в шапке блока, `withSum` — у прочих
     счетов, у которых шапки нет), «Отправить менеджеру», ссылка на оплату, «Деньги пришли?» и заявки менеджера с ответами.
     Комиссия ПРАЙМ без документа (18.11.2026, «счёт на комиссию мы больше не формируем») — только ссылка и отметки; «оплачено» —
     словом шапки (`Billing\PayWord`), строкой не повторяется. --}}
@php use App\Support\Money; use App\Billing\InvoiceState; @endphp
@if (! $i->isDocument())
    {{-- Ссылка на оплату подбора — строкой, как документ (18.11.2026, владелец выбрал вариант В): серый значок ссылки (не кружком —
         «кажется, что это кнопка»), под названием состояние, справа «Выставить» пилюлей; выставлена — «Скопировать» / «Отправить». --}}
    @if ($i->state !== InvoiceState::Paid)
        @if (! $i->isShownToManager())
            <div class="row">
                <x-ui.icon name="link" class="size-5 shrink-0 text-ink-dim"/>
                <span class="min-w-0 flex-1"><span class="block">Ссылка на оплату</span><span class="row-sub">не выставлена@if ($i->paid > 0), на <span class="nums">{{ Money::rub($i->remaining()) }}</span>@endif</span></span>
                <x-deal.issue-button :deal="$deal" :invoice="$i"/>
            </div>
        @elseif ($link && $link->isOpen())
            <x-ui.share-link variant="row" :url="$link->url()" title="Оплата по ссылке" :text="$link->message()" done="Ссылка на оплату в буфере">
                <x-slot:lead>
                    <x-ui.icon name="link" class="size-5 shrink-0 text-ink-dim"/>
                    <span class="min-w-[9rem] flex-1"><span class="block">Ссылка на оплату</span><span class="block truncate text-sm text-ink-muted">{{ $linkState }}</span></span>
                </x-slot:lead>
            </x-ui.share-link>
        @endif
        {{-- «Оплатил наличными…» под ссылкой — выставлена она или нет (22.11.2026, владелец: «где нажать, что уже наличными оплатил»;
             пункт «⋯» не находили): шторка — дата и сумма, остаток платят по ссылке (`KeepPayLink` ставит её на остаток). Выставлена —
             рядом «Напомнить менеджеру». --}}
        @if ($paidBy->contains('id', $i->id))
            <div class="send-under send-pair">
                <button type="button" class="btn btn-quiet btn-s" data-controller="emit" data-action="emit#send" data-emit-event-param="paid-{{ $i->id }}:open">Оплатил наличными…</button>
                @if ($i->isShownToManager() && \App\Billing\Actions\NudgeInvoice::allows($i))<x-deal.nudge-sheet :deal="$deal" :invoice="$i"/>@endif
            </div>
        @endif
    @endif
@else
    {{-- Счёт — файлом, как документ (15.11.2026, владелец: «счёт должен быть не страницей, а кнопкой „Скачать PDF“»). --}}
    <x-deal.doc-row :href="$pdf ?? \App\Http\Admin\MoneyController::url($i)" :title="\App\Billing\PayOffer::invoiceName($i)" :date="\App\Support\Ago::day($i->issued_at)" name="schet.pdf" :download="(bool) $pdf">
        @if ($withSum && (! $payerRow || $i->party_id !== $payerId))<span>{{ $i->party?->title() }}</span>@endif
        @if ($withSum)<span class="nums whitespace-nowrap">{{ $i->isPartial() && $i->remaining() > 0 ? 'осталось '.Money::rub($i->remaining()).' из '.Money::rub($i->total) : Money::rub($i->total) }}</span>@endif
        {{-- Отправлен ли — словом подписи «Счёт» над блоком (`x-deal.doc-cap`, 19.11.2026); у прочих счетов без подписи — здесь. --}}
        @if ($withSum)<span @class(['text-ink-muted' => $i->isShownToManager(), 'text-danger' => ! $i->isShownToManager()])>{{ \App\Billing\DocWord::staff($i)[0] }}</span>@endif
        @if ($i->isShownToManager() && \App\Offers\Actions\ShowToManager::canRecallInvoice($i))
            {{-- Отправлен и не оплачен — «Отозвать» (19.11.2026): поправить и отправить снова. --}}
            <x-slot:acts><x-deal.recall-row :deal="$deal" :action="'/work/deals/'.$deal->id.'/invoices/'.$i->id.'/recall'" :what="'счёт '.$i->label()"/></x-slot:acts>
        @endif
    </x-deal.doc-row>
    @if ($link && $link->isOpen())
        <x-ui.share-link variant="row" :url="$link->url()" title="Оплата по ссылке" :text="$link->message()" label="Ссылка на оплату" :sub="$linkState" done="Ссылка на оплату в буфере"/>
    @endif
    {{-- Под счётом кнопками, без черты (19.11.2026, владелец: «как у договора»): не отправлен — «Отправить менеджеру»; отправлен —
         «Оплата получена» (шторка оплаты `paid-{id}`, прежняя «Деньги пришли?») и «Напомнить менеджеру» (`x-deal.nudge-sheet`);
         заявка менеджера — её «Поступила / Не поступила» ниже вместо них; оплачен — без кнопок. --}}
    @if (! $i->isShownToManager())
        <x-deal.send-row :deal="$deal" :action="'/work/deals/'.$deal->id.'/invoices/'.$i->id.'/show'"/>
    @elseif ($paidBy->contains('id', $i->id) && $i->claims->isEmpty())
        <div class="send-under send-pair">
            <button type="button" class="btn btn-accent btn-s gap-2" data-controller="emit" data-action="emit#send" data-emit-event-param="paid-{{ $i->id }}:open"><x-ui.icon name="check" class="size-4"/>Оплата получена</button>
            @if (\App\Billing\Actions\NudgeInvoice::allows($i))<x-deal.nudge-sheet :deal="$deal" :invoice="$i"/>@endif
        </div>
    @endif
@endif
@if (! $i->openLink() && ($i->isDocument() || $i->isShownToManager()))<x-billing.pay-status :invoice="$i" staff/>@endif
@foreach ($i->claims as $p)
    <x-money.payment :payment="$p" :slip="'/work/money/invoices/'.$i->id.'/payments/'.$p->id.'/slip'" staff>
        @unless ($inStep)
            <x-slot:acts>
                {{-- Три ответа одним рядом во всю ширину, чип платёжки — над ними (16.11.2026). --}}
                <div class="claim-acts">
                    <form method="post" action="/work/payments/{{ $p->id }}/confirm" class="contents" data-turbo-confirm="Поступила {{ Money::rub($p->amount) }}?">@csrf<x-ui.button size="sm">Поступила</x-ui.button></form>
                    <a href="{{ \App\Http\Admin\MoneyController::url($i) }}" class="btn btn-s btn-quiet" data-turbo-frame="_top">Не поступила</a>
                    <x-deal.claim-other :payment="$p"/>
                </div>
            </x-slot:acts>
        @endunless
    </x-money.payment>
@endforeach
