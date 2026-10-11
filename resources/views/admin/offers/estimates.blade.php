{{-- Оценки менеджеров под полем цены продажи (11.10.2026) — группой строк, как в «Настройках» iOS. Строка и есть кнопка:
     оценил — справа сумма, нажатие — лист «Взять ценой продажи N ₽?» с «Взять» / «Отклонить» (`estimate_pick_controller`);
     думает — срок под именем, нажатие — «Снять с оценки?». Последняя строка — «Отдать на оценку».
     correction — «Оцененные»: цена уже стоит, менеджеры её корректируют. «Согласен» — зелёным словом, решать нечего;
     своя цена — с разницей к цене админа; «Оставить N ₽» закрывает просьбы с ценой админа. --}}
@php
    $n = $offer->number;
    $correction ??= false;
    $price = (int) $offer->asking_price;
    $proposals = $correction && $offer->openEstimates->contains->proposes();
@endphp
<div @class(['mt-4' => ! $correction])>
    @if ($offer->openEstimates->isNotEmpty())<p class="list-cap !px-1">{{ $correction ? 'Корректировки менеджеров' : 'Оценки менеджеров' }}</p>@endif
    <div class="list">
        @foreach ($offer->openEstimates as $e)
            @if ($e->agreed())
                <div class="row">
                    <x-ui.avatar :user="$e->user" :size="32"/>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate">{{ $e->user->shortName() }}</span>
                        <span class="row-sub">{{ $e->givenAgo() }}</span>
                    </span>
                    <x-ui.state tone="open" class="shrink-0">согласен</x-ui.state>
                </div>
            @elseif ($e->isGiven())
                <div class="contents" data-controller="estimate-pick" data-estimate-pick-title-value="Взять ценой продажи {{ \App\Support\Money::rub($e->amount) }}?">
                    <button type="button" class="row w-full text-left" data-action="estimate-pick#open">
                        <x-ui.avatar :user="$e->user" :size="32"/>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ $e->user->shortName() }}</span>
                            <span class="row-sub">{{ $e->givenAgo() }}</span>
                        </span>
                        <span class="flex shrink-0 flex-col items-end">
                            <span class="nums font-semibold">{{ \App\Support\Money::rub($e->amount) }}</span>
                            @if ($correction && $price)<span class="nums text-sm text-ink-muted">{{ $e->amount > $price ? '+' : '−' }}{{ \App\Support\Money::nums(abs($e->amount - $price)) }}</span>@endif
                        </span>
                        <x-ui.chevron/>
                    </button>
                    <form method="post" action="/offers/{{ $n }}/estimates/{{ $e->id }}/take" data-estimate-pick-target="take" data-turbo-confirm-text="{{ $e->user->shortName() }}, {{ $e->givenAgo() }}{{ $correction && $price ? ', ваша цена '.\App\Support\Money::rub($price) : '' }}" hidden>@csrf</form>
                    <form method="post" action="/offers/{{ $n }}/estimates/{{ $e->id }}/reject" data-estimate-pick-target="reject" hidden>@csrf</form>
                </div>
            @else
                <form method="post" action="/offers/{{ $n }}/estimates/{{ $e->id }}" class="contents" data-turbo-confirm="Снять {{ $e->user->shortName() }} с оценки?" data-turbo-confirm-label="Снять">
                    @csrf @method('delete')
                    <button type="submit" class="row w-full text-left">
                        <x-ui.avatar :user="$e->user" :size="32"/>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ $e->user->shortName() }}</span>
                            <span class="row-sub">
                                @if ($e->rejected_at)<span class="nums">отклонена {{ \App\Support\Money::nums($e->rejected_amount) }},</span>@endif
                                @if ($e->isOverdue())<x-offer.estimate-left :estimate="$e"/>@else<span>{{ $correction ? 'проверяет' : 'оценивает' }}, <x-offer.estimate-left :estimate="$e" class="!text-current"/></span>@endif
                            </span>
                        </span>
                    </button>
                </form>
            @endif
        @endforeach
        @if ($proposals)
            <form method="post" action="/offers/{{ $n }}/estimates/keep" class="contents" data-turbo-confirm="Оставить цену {{ \App\Support\Money::rub($price) }}?" data-turbo-confirm-text="Корректировки менеджеров закроются" data-turbo-confirm-label="Оставить">
                @csrf
                <button type="submit" class="row w-full text-left"><x-ui.row-icon name="check" tone="soft" size="s"/><span class="min-w-0 flex-1">Оставить <span class="nums">{{ \App\Support\Money::rub($price) }}</span></span></button>
            </form>
        @endif
        <x-offer.estimate-ask :offer="$offer" :managers="$managers" :label="$correction ? 'Спросить менеджеров' : 'Отдать на оценку'"/>
    </div>
</div>
