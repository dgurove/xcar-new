{{-- «Приём» в окошке открытого предложения (19.10.2026, владелец: «посмотри другие вкладки, может там тоже переделать
     действие»): срок приёма и что с ним сделать — кнопками, а не пунктами «Действий». Идёт — «+15 мин», «+1 ч». Закрыт
     без подтверждений (вкладка «Приём закрыт») — главное «Возобновить до …» одним нажатием (обычный срок от сейчас, не
     позже срока обязывающего — `Binding::clampClose`) и «+1 ч»; закрыт с подтверждениями — выбирают подтверждение ниже,
     продление серым. Другая дата и кому показывать — строкой «Показ» (шторка). Горит без подтверждений (3 часа до конца, ни
     одного) — ещё «Снизить цену» (шторка) и «Уведомить менеджеров» (`NoBidsAlarm::notify`), 22.10.2026. --}}
@php
    $closeAt = $offer->bids_close_at;
    $live = $closeAt->isFuture();
    $renewAt = \App\Offers\Binding::clampClose($offer, \App\Offers\Slots::closeFor(now()));
    $renew = ! $live && $waiting->isEmpty() && $renewAt->isFuture();
    // Горит без подтверждений (22.10.2026, владелец): до конца приёма 3 часа, а подтверждений нет — снизить цену или позвать
    // менеджеров тут же, рядом с продлением.
    $burning = $live && $waiting->isEmpty() && $closeAt->lte(now()->addHours(\App\Offers\Actions\NoBidsAlarm::HOURS));
@endphp
<section class="flex w-full flex-col gap-3 rounded-(--radius-l) bg-surface-2 p-3">
    <div class="flex flex-wrap items-baseline gap-x-2">
        <h2 class="text-base font-medium">{{ $live ? 'Приём до '.\App\Support\Ago::day($closeAt, true) : 'Приём закрыт' }}</h2>
        @if ($live)
            <span class="nums text-sm {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-ink-muted' }}" data-controller="timer" data-timer-until-value="{{ $closeAt->toIso8601String() }}" data-timer-done-value="закрыт" data-timer-coarse-value="true">{{ \App\Support\Ago::left($closeAt, 'ещё') }}</span>
        @else
            <span class="nums text-sm text-ink-muted">{{ \App\Support\Ago::html($closeAt, true, words: false) }}</span>
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
        @if ($burning)
            <button type="button" class="btn btn-s btn-quiet" data-controller="emit" data-action="emit#send" data-emit-event-param="lower-{{ $n }}:open">Снизить цену</button>
            <form method="post" action="/offers/{{ $n }}/no-bids" class="flex" data-turbo-confirm="Уведомить менеджеров?" data-turbo-confirm-text="«Ни одного подтверждения» уйдёт всем, кому машина открыта" data-turbo-confirm-label="Уведомить">@csrf
                <button class="btn btn-s btn-quiet">Уведомить менеджеров</button>
            </form>
        @endif
        @foreach ($renew ? [60 => '+1 ч'] : [15 => '+15 мин', 60 => '+1 ч'] as $minutes => $label)
            <form method="post" action="/offers/{{ $n }}/extend" class="flex">@csrf<input type="hidden" name="minutes" value="{{ $minutes }}">
                <button class="btn btn-s btn-quiet nums">{{ $label }}</button>
            </form>
        @endforeach
    </div>
    @if ($burning)
        <div class="contents" data-controller="sheet" data-action="lower-{{ $n }}:open@window->sheet#open">
            <x-ui.sheet :id="'lower-'.$n" title="Снизить цену">
                <form method="post" action="/offers/{{ $n }}/lower-price" class="flex flex-col gap-4">@csrf
                    <div class="fields">
                        <x-ui.field name="asking_price" :id="'lower-price-'.$n" label="Цена продажи, ₽" :value="\App\Support\Money::nums($offer->asking_price)" required/>
                    </div>
                    <x-ui.sheet-foot>Снизить</x-ui.sheet-foot>
                </form>
            </x-ui.sheet>
        </div>
    @endif
</section>
