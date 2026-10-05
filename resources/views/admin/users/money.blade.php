{{-- Деньги менеджера для сотрудника: реквизиты с правкой, сделки-расчёты одной плашкой, акт сверки и Excel. Положение
     (просрочил, должен нам, должны ему, выплатили — кто кому словом) — словами в строке заголовка «Сделки», а не рядом чипов над экраном. --}}
@php use App\Support\Money; $p = $position; @endphp

<div class="box" data-controller="sheet">
    <div class="flex items-start gap-3">
        <div class="min-w-0 flex-1">
            <h2 class="text-lg">Реквизиты</h2>
            @if ($party->filled())
                {{-- Подпись не отрывается от номера: «ОГРНИП», «р/с» и цифры — одним куском при переносе. --}}
                @php $glue = fn (?string $t) => str_replace('/', "/\u{2060}", preg_replace('/ (?=\d)/u', "\u{a0}", (string) $t)); @endphp
                <div class="mt-1 text-sm text-ink-muted">{{ $party->kind->label() }}@if ($party->details()), <span class="nums">{{ $glue($party->details()) }}</span>@endif</div>
                <div class="text-sm text-ink-muted">{{ $party->bankDetails() ? $glue($party->bankDetails()) : 'Банк не указан' }}</div>
            @else
                <div class="mt-1 text-sm text-ink-muted">Не указаны</div>
            @endif
            @if (! $party->payoutReady())<x-ui.state tone="urgent" class="mt-2">Реквизитов для выплаты нет</x-ui.state>@endif
        </div>
        <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="sheet#open" aria-label="Изменить реквизиты"><x-ui.icon name="edit" class="size-5"/></button>
    </div>
    <x-ui.sheet id="party" title="Реквизиты" wide :open="$errors->has('name') || $errors->has('inn')">
        <form method="post" action="{{ $base }}/{{ $user->id }}/party" class="flex flex-col gap-4">
            @csrf @method('put')
            <x-billing.party-fields :party="$party->exists ? $party : null" vat/>
            <x-ui.button block>Сохранить</x-ui.button>
        </form>
    </x-ui.sheet>
</div>

<section class="mt-8">
    {{-- Строка заголовка: «Сделки N», суммы словами, справа документы. На телефоне суммам места нет — они второй
         строкой под заголовком; от 640 — между заголовком и документами, переносятся внутри своей колонки. --}}
    <div class="grid grid-cols-[auto_1fr] items-baseline gap-x-3 gap-y-1 sm:grid-cols-[auto_1fr_auto]">
        <h2 class="text-xl">Сделки @if ($moneyDeals->isNotEmpty())<span class="nums text-ink-dim">{{ $moneyDeals->count() }}</span>@endif</h2>
        @if ($p['overdue'] > 0 || $p['claimed'] > 0 || $p['pay'] > 0 || $p['payout'] > 0 || $p['paid_out'] > 0)
            <p class="col-span-2 row-start-2 flex flex-wrap gap-x-3 text-sm text-ink-muted sm:col-span-1 sm:col-start-2 sm:row-start-1 sm:justify-end">
                @if ($p['overdue'] > 0)<span class="nums whitespace-nowrap text-danger">просрочил {{ Money::rub($p['overdue']) }}</span>@endif
                @if ($p['claimed'] > 0)<span class="nums whitespace-nowrap text-urgent">сообщил об оплате {{ Money::rub($p['claimed']) }}</span>@endif
                @if ($p['pay'] > 0)<span class="nums whitespace-nowrap">должен нам {{ Money::rub($p['pay']) }}</span>@endif
                @if ($p['payout'] > 0)<span class="nums whitespace-nowrap text-urgent">должны ему {{ Money::rub($p['payout']) }}</span>@endif
                @if ($p['paid_out'] > 0)<span class="nums whitespace-nowrap">выплатили {{ Money::rub($p['paid_out']) }}</span>@endif
            </p>
        @endif
        {{-- Документы с начала года — шторкой документов, как у менеджера. --}}
        @php $period = http_build_query(['from' => now()->startOfYear()->toDateString(), 'to' => now()->toDateString()]); @endphp
        <div class="col-start-2 row-start-1 flex items-center gap-1.5 justify-self-end sm:col-start-3">
            <x-ui.doc :doc="['url' => $base.'/'.$user->id.'/statement?'.$period, 'type' => 'pdf', 'name' => 'akt-sverki.pdf', 'label' => 'Акт сверки']" class="chip"/>
            <x-ui.doc :doc="['url' => $base.'/'.$user->id.'/export?'.$period, 'type' => 'sheet', 'name' => 'sdelki.xlsx', 'label' => 'Сделки, Excel']" class="chip">Excel</x-ui.doc>
        </div>
    </div>
    @if ($moneyDeals->isEmpty())
        <x-ui.empty line class="mt-2 px-0">Сделок с деньгами нет</x-ui.empty>
    @else
        <div class="list mt-3">
            @foreach ($moneyDeals as $row)
                {{-- Сделка живёт в CRM: с сайта (/account/users) — с хостом, иначе 404. Машина гаража — в гараж CRM. --}}
                @php $path = $row instanceof \App\Garage\Car ? '/work/garage?preset=all&peek='.$row->offer->number : '/work/deals/'.$row->id; @endphp
                <x-money.deal-row :deal="$row" :href="$crm ? $path : \App\Support\Surface::Crm->url($path)"/>
            @endforeach
        </div>
    @endif
</section>
