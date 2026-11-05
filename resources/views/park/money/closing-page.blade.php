{{-- Документ «Счетов и актов» на всю страницу (05.11.2026, владелец: «все тачки — ставка, даты, акт»; шапку-плашку — «убрать,
     там 4 кнопки и сумма»): в строке заголовка — логотип, плательщик, состояние словом, справа сумма и действия
     (`closing-actions`); плашки — только когда нужен наш ход (`closing-alerts`); под ними все ТС строками `.list`
     (`x-billing.registry-car-row`; 06.11.2026, владелец: таблица вбок на телефоне — «пиздец», на ПК «ужасно»): ничего не
     листается вбок, открыт акт (шторка справа) — строки просто уже. Последняя строка — итог. --}}
@php use App\Support\Money; $d = $doc; @endphp
<x-ui.shell :title="$d->name.', '.$d->what.' за '.mb_strtolower($d->month->translatedFormat($d->month->isSameYear(now()) ? 'F' : 'F Y'))" :back="['Счета и акты', '/money/closing#m'.$d->month->format('Y-m')]" cache="no-cache">
    <x-slot:lead>@if ($d->vendor)<x-vendor.logo :vendor="$d->vendor"/>@endif</x-slot:lead>
    <x-slot:badge><span class="title-state {{ $d->tone() }}">{{ $d->state === 'send' ? 'не отправлен' : $d->word() }}</span></x-slot:badge>
    <x-slot:actions class="doc-acts">@include('park.money.closing-actions')</x-slot:actions>
    @include('park.money.closing-alerts')
    @if ($cars->isNotEmpty())
        <div class="list doc-cars">
            @foreach ($cars as $n => $car)<x-billing.registry-car-row :car="$car" :n="$n + 1" :acts="$acts[$car['vehicle']->id] ?? null"/>@endforeach
            <div class="row doc-car-foot">
                <span class="min-w-0 flex-1">Итого {{ $cars->count() }}&nbsp;ТС@if ($days = $cars->sum('days')), {{ Money::nums($days) }}&nbsp;сут@endif</span>
                <span class="nums font-semibold">{{ Money::rub($cars->sum('amount')) }}</span>
            </div>
        </div>
    @endif
    <x-mail.window/>
</x-ui.shell>
