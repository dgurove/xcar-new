{{-- Оценки менеджеров под полем цены продажи (11.10.2026) — группой строк, как в «Настройках» iOS. Строка и есть кнопка:
     оценил — справа сумма, нажатие — лист «Взять ценой продажи» / «Отклонить» (`estimate_pick_controller`); думает — срок
     под именем, нажатие — «Снять с оценки?». Последняя строка — «Отдать на оценку». --}}
@php $n = $offer->number; @endphp
<div class="mt-4">
    <p class="list-cap !px-1">Оценки менеджеров</p>
    <div class="list">
        @foreach ($offer->openEstimates as $e)
            @if ($e->isGiven())
                <div class="contents" data-controller="estimate-pick" data-estimate-pick-title-value="Взять ценой продажи {{ \App\Support\Money::rub($e->amount) }}?">
                    <button type="button" class="row w-full text-left" data-action="estimate-pick#open">
                        <x-ui.avatar :user="$e->user" :size="32"/>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{ $e->user->shortName() }}</span>
                            <span class="row-sub">{{ $e->givenAgo() }}</span>
                        </span>
                        <span class="nums shrink-0 font-semibold">{{ \App\Support\Money::rub($e->amount) }}</span>
                        <x-ui.chevron/>
                    </button>
                    <form method="post" action="/offers/{{ $n }}/estimates/{{ $e->id }}/take" data-estimate-pick-target="take" data-turbo-confirm-text="Оценка: {{ $e->user->shortName() }}, {{ $e->givenAgo() }}" hidden>@csrf</form>
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
                                @if ($e->isOverdue())<x-offer.estimate-left :estimate="$e"/>@else<span>оценивает, <x-offer.estimate-left :estimate="$e" class="!text-current"/></span>@endif
                            </span>
                        </span>
                    </button>
                </form>
            @endif
        @endforeach
        <x-offer.estimate-ask :offer="$offer" :managers="$managers"/>
    </div>
</div>
