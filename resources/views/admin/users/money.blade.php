{{-- Деньги менеджера для сотрудника: положение чипами, реквизиты с правкой, сделки-расчёты, акт сверки и Excel. --}}
@php use App\Support\Money; $p = $position; @endphp
@if ($p['overdue'] > 0 || $p['claimed'] > 0 || $p['pay'] > 0 || $p['payout'] > 0 || $p['paid_out'] > 0)
    <div class="flex flex-wrap gap-1.5">
        @if ($p['overdue'] > 0)<x-ui.pill tone="danger" class="!min-h-0 !py-1 text-xs nums">просрочено {{ Money::rub($p['overdue']) }}</x-ui.pill>@endif
        @if ($p['claimed'] > 0)<x-ui.pill tone="urgent" class="!min-h-0 !py-1 text-xs nums">сообщил об оплате {{ Money::rub($p['claimed']) }}</x-ui.pill>@endif
        @if ($p['pay'] > 0)<span class="chip nums">нам {{ Money::rub($p['pay']) }}</span>@endif
        @if ($p['payout'] > 0)<span class="chip nums text-urgent">мы должны {{ Money::rub($p['payout']) }}</span>@endif
        @if ($p['paid_out'] > 0)<span class="tag nums">выплачено {{ Money::rub($p['paid_out']) }}</span>@endif
    </div>
@endif

<div class="mt-6 box" data-controller="sheet">
    <div class="flex items-start gap-3">
        <div class="min-w-0 flex-1">
            <h2 class="text-lg">Реквизиты</h2>
            @if ($party->filled())
                <div class="mt-1.5 flex flex-wrap gap-1.5"><span class="tag">{{ $party->kind->label() }}</span>@if ($party->details())<span class="tag">{{ $party->details() }}</span>@endif</div>
                <div class="mt-1 text-sm text-ink-muted">{{ $party->bankDetails() ?: 'Банк не указан' }}</div>
            @else
                <div class="mt-1 text-sm text-ink-muted">Не указаны</div>
            @endif
            @if (! $party->payoutReady())<x-ui.pill tone="urgent" class="mt-2 !min-h-0 !py-1 text-xs">Реквизитов для выплаты нет</x-ui.pill>@endif
        </div>
        <button type="button" class="btn btn-s btn-quiet btn-round shrink-0" data-action="sheet#open" aria-label="Изменить реквизиты"><x-ui.icon name="edit" class="size-5"/></button>
    </div>
    <x-ui.sheet id="party" title="Реквизиты" wide :open="$errors->has('name') || $errors->has('inn')">
        <form method="post" action="{{ $base }}/{{ $user->id }}/party" class="flex flex-col gap-4">
            @csrf @method('put')
            <x-billing.party-fields :party="$party->exists ? $party : null"/>
            <x-ui.button block>Сохранить</x-ui.button>
        </form>
    </x-ui.sheet>
</div>

<section class="mt-8">
    <div class="flex flex-wrap items-baseline gap-3">
        <h2 class="text-xl">Сделки @if ($moneyDeals->isNotEmpty())<span class="nums text-ink-dim">{{ $moneyDeals->count() }}</span>@endif</h2>
        {{-- Документы за период — та же форма, что у менеджера; на телефоне во встроенный браузер. --}}
        <form method="get" action="{{ $base }}/{{ $user->id }}/statement" class="ml-auto flex items-center gap-1.5" data-turbo="false" data-controller="file" data-action="submit->file#share">
            <input type="hidden" name="from" value="{{ now()->startOfYear()->toDateString() }}"><input type="hidden" name="to" value="{{ now()->toDateString() }}">
            <button class="chip" data-file-any>Акт сверки</button>
            <button class="chip" formaction="{{ $base }}/{{ $user->id }}/export" data-file-any>Excel</button>
        </form>
    </div>
    @if ($moneyDeals->isEmpty())
        <x-ui.empty class="mt-4">Сделок с деньгами нет</x-ui.empty>
    @else
        <div class="mt-4 flex flex-col gap-2">
            @foreach ($moneyDeals as $deal)
                <x-money.deal-row :deal="$deal" :href="'/work/deals/'.$deal->id"/>
            @endforeach
        </div>
    @endif
</section>
