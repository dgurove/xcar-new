{{-- «Показ» строкой (19.10.2026, владелец: «компактно над кнопками с возможностью поменять, по нажатию окошко, где всё, что
     с показом связано»): в блоке «Цены» черновика и в плашке «Приём» открытого — в окошке строки и в редакторе. Сверху срок
     приёма («Приём до 22 окт, 17:00»; не вписан — как его посчитает публикация, `Slots::closeFor`; в «Приёме» его нет — `when=false`, срок в заголовке плашки), ниже кому показывать
     (`AudienceRules::summary`) и отметки показа, что не по умолчанию. Нажатие — шторка «Показ» с полями модуля
     (`x-offer.showing as=sheet`), «Сохранить» отправляет форму `form`, сводку перерисовывает её ответ. Только админу. --}}
@props(['offer', 'form', 'summary' => null, 'when' => true])
@php
    use App\Offers\{AudienceRules, Slots};
    $close = $offer->bids_close_at;
    $when = $when === false ? false : ($close && $close->isFuture()
        ? 'Приём до '.$close->translatedFormat($close->isCurrentYear() ? 'j M, H:i' : 'j M Y, H:i')
        : 'Приём '.config('xcar.bids_window_days').' '.\App\Support\Plural::of((int) config('xcar.bids_window_days'), ['день', 'дня', 'дней']).' с выхода, до '.sprintf('%02d:00', (int) config('xcar.bids_close_hour')));
    $marks = array_filter([
        $offer->recommended ? 'рекомендуем' : null,
        $offer->share_locked ? 'без шеринга' : null,
        ! $offer->chat_enabled ? 'без чата' : null,
        ! $offer->garage_allowed ? 'без гаража' : null,
    ]);
    $who = AudienceRules::summary(AudienceRules::of($offer)).($marks ? ', '.implode(', ', $marks) : '');
    // Срок обязывающего — в шторке; строкой сводки только когда горит (красным).
    $binding = \App\Offers\Binding::matters($offer) ? \App\Offers\Binding::word($offer) : null;
    $id = 'showing-'.$offer->number.'-'.$form;
@endphp
@if (auth()->user()->canManageCrm())
<div class="contents" data-controller="sheet">
    <button type="button" {{ $attributes->class(['showing-row']) }} data-action="sheet#open">
        <x-ui.icon name="users" class="size-5 shrink-0 text-ink-muted"/>
        <span class="min-w-0 flex-1">
            @if ($when !== false)<span class="block nums">{{ $when }}</span>@endif
            <span @class(['block', 'text-sm text-ink-muted' => $when !== false])>{{ $who }}</span>
            @if ($binding)<span class="block nums text-sm text-danger">обязывающее до {{ \App\Offers\Binding::date($offer->binding_until) }}, {{ $binding }}</span>@endif
        </span>
        <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
    </button>
    <x-ui.sheet :id="$id" title="Показ">
        <x-offer.showing :offer="$offer" :form="$form" :summary="$summary" as="sheet"/>
        <x-ui.sheet-foot type="submit" foot="mt-4" :form="$form" data-action="sheet#close">Сохранить</x-ui.sheet-foot>
    </x-ui.sheet>
</div>
@endif
