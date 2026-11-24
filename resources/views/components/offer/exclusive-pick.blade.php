{{-- «Эксклюзив за оценку» — выбор (24.11.2026, владелец выбрал из макетов А1): оценщики цикла строками (аватар, имя, «оценил N ₽»,
     галка), «Никому, всем сразу» красным, срок 3 / 5 / 7 дней. Отмечено, что уже решено (`Exclusive::preferred`: кому решили; никто
     не решал — Рома; «никому» — эта строка); новый выбор переписывает прежний целиком (`Exclusive::decide`). Один кусок на три
     места: шторка «Показ» (`x-offer.showing`, узел ленты, `mode=show`: уходит, только если блок тронули — `apply`), шторка
     «Выставить в продажу» одной машины и пачки (`x-offer.publish-sheet`, кнопка ждёт выбора). Поля — `exclusive[{id}][to][]`,
     `[none]`, `[days]`; сервер — `Exclusive::decideFrom`. Пачке — заголовок машины и `pack`: виден, только пока её галка отмечена. --}}
@props(['offer', 'estimates', 'form' => null, 'mode' => 'publish', 'pack' => false])
@php
    use App\Offers\Exclusive;
    $prefix = 'exclusive['.$offer->id.']';
    $preferred = Exclusive::preferred($estimates);
    $nobody = Exclusive::nobody($estimates);
    $days = Exclusive::chosenDays($estimates);
    $open = $estimates->contains(fn ($e) => $e->exclusive_days === null);
    $formAttr = $form ? 'form='.$form : '';
@endphp
<div {{ $attributes->class(['exclusive-pick']) }} data-controller="exclusive-pick" data-exclusive-pick-mode-value="{{ $mode }}"
    @if ($open) data-undecided @endif @if ($pack) data-exclusive-pick-number-value="{{ $offer->number }}" hidden @endif>
    @if ($pack)<p class="mb-1.5 truncate px-1 font-medium">{{ $offer->titleWithYear() }}</p>@endif
    @if ($mode === 'show')
        <div class="wave-head">За оценку @if ($open)<span class="text-sm text-urgent">решить</span>@endif</div>
        <input type="hidden" name="{{ $prefix }}[apply]" value="0" {!! $formAttr !!} data-exclusive-pick-target="apply">
    @endif
    <div class="list">
        @foreach ($estimates as $e)
            <label class="row row-check">
                <x-ui.avatar :user="$e->user" :size="32"/>
                <span class="min-w-0 flex-1">
                    <span class="block truncate">{{ $e->user->shortName() }}</span>
                    <span class="row-sub">оценил {{ \App\Support\Money::rub($e->amount) }}</span>
                </span>
                <span class="check"><input type="checkbox" name="{{ $prefix }}[to][]" value="{{ $e->id }}" {!! $formAttr !!} data-exclusive-pick-target="person" data-action="exclusive-pick#person" @checked(in_array($e->id, $preferred, true))></span>
            </label>
        @endforeach
        <label class="row row-check">
            <span class="min-w-0 flex-1 text-danger">Никому, всем сразу</span>
            <span class="check"><input type="checkbox" name="{{ $prefix }}[none]" value="1" {!! $formAttr !!} data-exclusive-pick-target="none" data-action="exclusive-pick#none" @checked($nobody)></span>
        </label>
    </div>
    <div class="segment mt-2" data-exclusive-pick-target="days" @if ($preferred === []) hidden @endif>
        @foreach (array_filter(\App\Offers\Estimate::EXCLUSIVE) as $d)
            <label><input type="radio" name="{{ $prefix }}[days]" value="{{ $d }}" {!! $formAttr !!} data-action="exclusive-pick#touch" @checked($d === $days)>{{ \App\Offers\Estimate::daysWord($d) }}</label>
        @endforeach
    </div>
</div>
