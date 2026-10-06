{{-- Карточка поступления: плательщик, сумма, дата, ИНН и назначение целиком. Не привязано — счета на выбор (сначала
     совпавшие по ИНН и сумме) и «Не наше»; «не наше» можно вернуть; привязанное ведёт в свой счёт. --}}
@php use App\Support\Money; $href = '/settings/bank/statement/'.$tx->id; @endphp
<x-ui.detail>
    <x-ui.row-card :title="$tx->counterparty ?: 'Плательщик не указан'" :photo="false">
        <x-slot:marks>
            <span class="tag nums font-semibold">{{ Money::exact($tx->amount) }}</span>
            <span class="tag nums">{{ $tx->booked_at->translatedFormat('j M Y') }}</span>
            <x-ui.state :tone="$tx->tone()">{{ mb_strtolower($tx->stateLabel()) }}</x-ui.state>
            @if ($tx->counterparty_inn)<span class="tag nums">ИНН {{ $tx->counterparty_inn }}</span>@endif
            @if ($tx->doc_number)<span class="tag nums">п/п {{ $tx->doc_number }}</span>@endif
        </x-slot:marks>
        <x-slot:actions>
            @if ($tx->invoice)
                <a href="/work/money/invoices/{{ $tx->invoice_id }}" class="btn btn-s btn-quiet">Счёт {{ $tx->invoice->label() }}, {{ $tx->invoice->party->name }}</a>
            @elseif ($tx->state === \App\Billing\Bank\Transaction::IGNORED)
                <form method="post" action="{{ $href }}/ignore" class="contents">@csrf<button class="btn btn-s btn-quiet">Вернуть в непривязанные</button></form>
            @else
                <form method="post" action="{{ $href }}/ignore" class="contents" data-turbo-confirm="Это не оплата по счёту?">@csrf<button class="btn btn-s btn-quiet">Не наше</button></form>
            @endif
        </x-slot:actions>
        @if ($tx->purpose)<div class="mt-4 text-sm">{{ $tx->purpose }}</div>@endif
        @if (\App\Billing\Bank\Actions\MatchTransaction::isPayout($tx) && $tx->note)
            <div class="list mt-4">
                <x-money.line :icon="$payouts->isEmpty() ? 'clock' : 'check'" :title="$tx->note" :tone="$payouts->isEmpty() ? 'urgent' : 'open'"/>
                @foreach ($payouts as $a)
                    <x-money.line :icon="\App\Billing\Acquiring\PayMethod::icon($a->method)" :title="'Счёт '.$a->link->invoice->label().', '.$a->link->invoice->party->name"
                        :sub="$a->created_at->translatedFormat('j M H:i').', комиссия '.Money::exact($a->fee() ?? 0)" :amount="$a->amount" :href="'/work/money/invoices/'.$a->link->invoice_id" data-turbo-frame="_top"/>
                @endforeach
            </div>
        @endif
        @if ($suggestions->isNotEmpty())
            <form method="post" action="{{ $href }}/match" class="mt-4 flex flex-col gap-3">
                @csrf
                <div class="list">
                    @foreach ($suggestions as $i)
                        <label class="row row-check !py-2">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate">Счёт {{ $i->label() }}, {{ $i->party->name }}</span>
                                <span class="row-sub nums">к оплате {{ Money::rub($i->remaining()) }}, от {{ $i->issued_at->translatedFormat('j M') }}{{ $i->deal?->offer ? ', '.$i->deal->offer->titleWithYear() : ($i->vehicle ? ', '.$i->vehicle->titleWithYear() : '') }}</span>
                            </span>
                            <span class="check"><input type="radio" name="invoice" value="{{ $i->id }}" required></span>
                        </label>
                    @endforeach
                </div>
                <x-ui.button size="sm">Привязать</x-ui.button>
            </form>
        @elseif ($tx->state === \App\Billing\Bank\Transaction::UNMATCHED)
            <div class="mt-4 text-sm text-ink-muted">Открытых счетов на такую сумму нет</div>
        @endif
        <x-slot:row><x-bank.table-row :tx="$tx"/></x-slot:row>
    </x-ui.row-card>
    {{-- Привязали или «не наше» — строка уходит из «Не привязаны»: страница перечитывается морфом. --}}
    @if (session('toast'))<turbo-stream action="reload"></turbo-stream>@endif
</x-ui.detail>
