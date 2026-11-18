{{-- Выбор покупателя, пока его нет (18.11.2026, владелец выбрал вариант А): новый — плитками вида (`x-deal.buyer-kind`), свой —
     строкой: «Я сам» и последние покупатели менеджера, нажатие сразу выбирает (`PUT /deals/{id}/contract`); «Все покупатели» —
     шторка выбора с поиском (`x-people.pick`, слушает `buyer:open`). Заготовка без данных — её строка (данные, «Другой
     покупатель»). Один кусок на задачу «Укажите покупателя» под шагом (`buyer-task`) и шаги, что сами просят покупателя
     (ПРАЙМ на оплате, «Покупатель по ДКП» в получении — `step`). --}}
@php
    $me = auth()->user();
    $contract = \App\Offers\DealContract::for($deal)->loadMissing('buyer.party');
    $draft = $contract->buyer;
    $mine = \App\Support\People::buyersOf($me)->reject(fn ($b) => $b->isBuyerDraft());
    // Последние — по последнему договору с ними (кого выбирали недавно, тот и нужен).
    $recentIds = $mine->isEmpty() ? collect() : \App\Offers\DealContract::whereIn('buyer_user_id', $mine->pluck('id'))
        ->groupBy('buyer_user_id')->selectRaw('buyer_user_id, max(updated_at) as at')->orderByDesc('at')->limit(3)->pluck('buyer_user_id');
    $recent = $recentIds->map(fn ($id) => $mine->firstWhere('id', $id))->filter()
        ->concat($mine->whereNotIn('id', $recentIds->all())->take(3 - $recentIds->count()))->take(3);
    $state = fn ($u) => $u->party?->readyAsBuyer() ? ['данные есть', 'text-open'] : [$u->party?->gapWord() ?? 'нужны данные', 'text-urgent'];
    $rows = \App\Support\People::contractBuyers($me, 'Я сам', 'ваши данные для договора');
@endphp
<div><x-deal.buyer-kind :deal="$deal"/></div>
@if ($draft)
    {{-- Плитку нажали, данные не внесли — строка заготовки открывает их шторку (кусок договора: там и выбор другого). --}}
    <div class="mt-3">@include('cabinet.deals.contract', ['part' => 'buyer', 'embedded' => true, 'labels' => true, 'after' => ''])</div>
@endif
<div class="list-cap !px-0 mt-4">Или из своих</div>
<div class="list">
    <form method="post" action="/deals/{{ $deal->id }}/contract" class="contents">
        @csrf @method('put')
        <input type="hidden" name="buyer_id" value="me">
        <button type="submit" class="row w-full text-left">
            <x-ui.avatar :user="$me" :size="28"/>
            <span class="min-w-0 flex-1"><span class="block">Я сам</span><span @class(['row-sub', 'text-open' => $me->party?->readyAsBuyer(), 'text-urgent' => ! $me->party?->readyAsBuyer()])>{{ $me->party?->readyAsBuyer() ? 'данные есть' : 'нужны ваши данные' }}</span></span>
            <x-ui.chevron/>
        </button>
    </form>
    @foreach ($recent as $b)
        <form method="post" action="/deals/{{ $deal->id }}/contract" class="contents">
            @csrf @method('put')
            <input type="hidden" name="buyer_id" value="{{ $b->id }}">
            <button type="submit" class="row w-full text-left">
                <x-ui.avatar :user="$b" :size="28"/>
                <span class="min-w-0 flex-1"><span class="block truncate">{{ $b->buyerLabel() }}</span><span class="row-sub {{ $state($b)[1] }}">{{ $state($b)[0] }}</span></span>
                <x-ui.chevron/>
            </button>
        </form>
    @endforeach
    @if ($mine->count() > $recent->count())
        <button type="button" class="row w-full text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="buyer:open">
            <span class="min-w-0 flex-1 text-ink-muted">Все покупатели <span class="nums">{{ $mine->count() }}</span></span><x-ui.chevron/>
        </button>
    @endif
</div>
@unless ($draft)
    {{-- Шторка выбора с поиском и «+ Новый покупатель» — её открывает «Все покупатели» и дела шага (`buyer:open`). --}}
    <form method="post" action="/deals/{{ $deal->id }}/contract" class="contents">
        @csrf @method('put')
        <x-people.pick name="buyer_id" kind="buyer" passport variant="none" :people="$rows" title="Покупатель" :deal="$deal->id" link autosubmit finish="Выбрать" data-action="buyer:open@window->sheet#open"/>
    </form>
@endunless
@error('buyer_id')<p class="field-error px-4 py-2">{{ $message }}</p>@enderror
