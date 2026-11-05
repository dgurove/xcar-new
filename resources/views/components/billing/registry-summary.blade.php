{{-- Состав документа месяца по смыслу (05.11.2026, владелец: «огромный список, странный, бесцельный»;
     `ClosingDoc::composition`): движение в месяце (помесячно — стояли весь месяц, приняты, выданы; по типу ТС — нет,
     владелец: «не вижу смысла»), эвакуация и прочее, итог; последней строкой «Все N ТС» — шторка со строкой на машину: период, сутки, сумма, ссылка
     в дело. В эвакуации и счёте покупателю групп нет — машины строками сразу (их одна-три). Карточка «Счетов и актов» и
     страница счёта. --}}
@props(['composition', 'month', 'total', 'id'])
@php
    use App\Support\Money;
    $c = $composition; $cars = $c['cars'];
    $few = $cars->count() <= 3;
    $days = (int) $cars->sum('days');
@endphp
<div class="list-cap mt-5">Состав</div>
<div class="list">
    @if ($few)
        @foreach ($cars as $car)<x-billing.registry-car :car="$car"/>@endforeach
    @else
        @if ($c['moves'])
            @foreach ($c['moves'] as $m)
                <div class="row"><span class="min-w-0 flex-1">{{ $m['label'] }}<span class="row-sub"><span class="nums">{{ $m['cars'] }}&nbsp;ТС</span></span></span><span class="nums shrink-0">{{ Money::rub($m['amount']) }}</span></div>
            @endforeach
        @elseif ($c['other'] > 0)
            {{-- По выдаче делить нечего (все выданы в месяце): хранение отдельно, только когда рядом есть прочее. --}}
            <div class="row"><span class="min-w-0 flex-1">Хранение</span><span class="nums shrink-0">{{ Money::rub($total - $c['other']) }}</span></div>
        @endif
    @endif
    @if ($c['other'] > 0 && ! $few)
        <div class="row"><span class="min-w-0 flex-1">Эвакуация и прочее</span><span class="nums shrink-0">{{ Money::rub($c['other']) }}</span></div>
    @endif
    <div class="row">
        <span class="min-w-0 flex-1">Итого<span class="row-sub"><span class="nums">{{ $cars->count() }}&nbsp;ТС</span>@if ($days)<span class="nums">{{ Money::nums($days) }}&nbsp;сут</span>@endif</span></span>
        <span class="nums shrink-0 font-semibold">{{ Money::rub($total) }}</span>
    </div>
    @unless ($few)
        <div data-controller="sheet">
            <button type="button" class="row w-full text-left" data-action="sheet#open"><span class="min-w-0 flex-1 text-accent-text">Все {{ $cars->count() }}&nbsp;ТС</span><x-ui.chevron/></button>
            <x-ui.sheet :id="'cars-'.$id" :title="'ТС в документе, '.$cars->count()" tall>
                <div class="list">
                    @foreach ($cars as $car)<x-billing.registry-car :car="$car"/>@endforeach
                </div>
            </x-ui.sheet>
        </div>
    @endunless
</div>
