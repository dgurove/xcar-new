{{-- «Приём» в окошке открытого предложения (19.10.2026, владелец: «посмотри другие вкладки, может там тоже переделать
     действие»): срок приёма и что с ним сделать — кнопками, а не пунктами «Действий». Идёт — «+15 мин», «+1 ч». Закрыт
     без подтверждений (вкладка «Приём закрыт») — главное «Возобновить до …» одним нажатием (обычный срок от сейчас, не
     позже срока обязывающего — `Binding::clampClose`) и «+1 ч»; закрыт с подтверждениями — выбирают подтверждение ниже,
     продление серым. Другая дата и кому показывать — строкой «Показ» (шторка). --}}
@php
    $closeAt = $offer->bids_close_at;
    $live = $closeAt->isFuture();
    $renewAt = \App\Offers\Binding::clampClose($offer, \App\Offers\Slots::closeFor(now()));
    $renew = ! $live && $waiting->isEmpty() && $renewAt->isFuture();
@endphp
<section class="flex w-full flex-col gap-3 rounded-(--radius-l) bg-surface-2 p-3">
    <div class="flex flex-wrap items-baseline gap-x-2">
        <h2 class="text-lg font-medium">{{ $live ? 'Приём до '.$closeAt->translatedFormat('j M, H:i') : 'Приём закрыт' }}</h2>
        @if ($live)
            <span class="nums text-sm {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-ink-muted' }}" data-controller="timer" data-timer-until-value="{{ $closeAt->toIso8601String() }}" data-timer-done-value="закрыт" data-timer-coarse-value="true">{{ \App\Support\Ago::left($closeAt, 'ещё') }}</span>
        @else
            <span class="nums text-sm text-ink-muted">{{ $closeAt->translatedFormat('j M, H:i') }}</span>
        @endif
    </div>
    {{-- «Показ» строкой — кому и какие отметки, шторка со сроком и волнами (`x-offer.showing-row`). --}}
    <x-offer.showing-row :offer="$offer" :form="$showForm" :summary="$showSummary ?? null" :when="false"/>
    <div class="flex flex-wrap gap-2">
        @if ($renew)
            <form method="post" action="/offers/{{ $n }}/extend" class="flex min-w-0 flex-1">@csrf<input type="hidden" name="renew" value="1">
                <button class="btn btn-s btn-accent min-w-0 flex-1">Возобновить до {{ \App\Offers\Slots::phrase($renewAt) }}</button>
            </form>
        @endif
        @foreach ($renew ? [60 => '+1 ч'] : [15 => '+15 мин', 60 => '+1 ч'] as $minutes => $label)
            <form method="post" action="/offers/{{ $n }}/extend" class="flex">@csrf<input type="hidden" name="minutes" value="{{ $minutes }}">
                <button class="btn btn-s btn-quiet nums">{{ $label }}</button>
            </form>
        @endforeach
    </div>
</section>
