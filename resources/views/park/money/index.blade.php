{{-- Деньги: счета с пресетами, сортировкой, лупой и чипом «Контрагент» — только таблицей с карточкам
     (плиток и строк у счетов нет); «Долги», «Счета и акты» и «Реквизиты» — одной плашкой над списком; счёт выставляется
     из ТС. --}}
@php use App\Support\ListView; $view = ListView::fromRequest(request()) === ListView::WIDE ? ListView::WIDE : ListView::TABLE; @endphp
<x-ui.shell :title="$party ? $party->name : 'Оплаты'" :count="$any ? $invoices->total() : null" :back="$party ? ['Долги', '/money/debts'] : false" :phone-heading="(bool) $party" :detail="$detail">
    <x-slot:lead>@if ($partyVendor = $party ? \App\Vendors\Vendor::ofParty($party->id) : null)<x-vendor.logo :vendor="$partyVendor"/>@endif</x-slot:lead>
    @if ($any)<x-ui.toolbar :sort="$sort" :pills="$presets" :pill="$preset" pill-param="preset" :counts="$counts" name="money" :facets="$facets" search="Номер, контрагент, убыток, VIN">
        {{-- Счета — только таблицей; на телефоне её можно развернуть в подробную. --}}
        <x-slot:extra><x-ui.view-switch :views="[ListView::TABLE, ListView::WIDE]" :current="$view"/></x-slot:extra>
    </x-ui.toolbar>@endif
    @unless ($party)
        <div @class(['list', 'mt-4' => $any])>
            {{-- Счета и акты за всю историю: сколько отправить, что изменилось после отправки, кому нужны реквизиты, что
                 просрочено (`ClosingDoc::summary`, те же состояния, что в списке). Нечего — строка без чисел. --}}
            <a href="/money/closing" class="row">
                <span class="min-w-0 flex-1">Счета и акты</span>
                @foreach (['drift' => ['изменилось', 'text-danger'], 'blocked' => ['нет реквизитов', 'text-danger'], 'nobody' => ['нет плательщика', 'text-danger'], 'answered' => ['ответили', 'text-urgent'], 'send' => ['отправить', 'text-urgent'], 'review' => ['на согласовании', 'text-ink-muted'], 'overdue' => ['просрочено', 'text-danger']] as $state => [$word, $tone])
                    @if ($closing[$state] ?? 0)<span class="nums shrink-0 text-sm {{ $tone }}">{{ $word }} {{ $closing[$state] }}</span>@endif
                @endforeach
                <x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/>
            </a>
            <a href="/money/debts" class="row"><span class="min-w-0 flex-1">Долги по контрагентам</span><x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/></a>
            @if (auth()->user()->canManagePark())<a href="/money/parties" class="row"><span class="min-w-0 flex-1">Реквизиты</span><x-ui.icon name="chevron-right" class="size-5 text-ink-dim"/></a>@endif
        </div>
    @endunless
    <div id="list">
    {{-- Счетов ещё нет вовсе (их заводит отправка в «Счетах и актах») — пустой список не рисуется. --}}
    @if ($invoices->isEmpty() && $q === '' && ! $any)
    @elseif ($invoices->isEmpty())
        <x-ui.empty class="mt-6">{{ $q !== '' ? 'Ничего не нашлось' : 'Неоплаченных счетов нет' }}</x-ui.empty>
    @else
        @php $columns = \App\Http\Park\MoneyController::table(); @endphp
        <x-ui.table :table="$columns" id="invoices" class="mt-6" :view="$view">
            <x-slot:head><x-table.head :table="$columns" :sort="$sort"/></x-slot:head>
            @foreach ($invoices as $i)<x-billing.table-row :invoice="$i" :table="$columns"/>@endforeach
        </x-ui.table>
    @endif
    @if ($invoices->hasPages())<div class="mt-8"><x-ui.pager :of="$invoices" :sizes="ListView::PER_ROWS"/></div>@endif
    </div>
</x-ui.shell>
