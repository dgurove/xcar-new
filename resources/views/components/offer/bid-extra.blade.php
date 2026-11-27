{{-- «+ Доп. запрос…» внутри формы подтверждения (27.11.2026, вариант П2): ничего не отмечено — тихая строка, отмечено —
     «Запросить: ПТС, осмотр ×» (нажатие — шторка, × — снять всё). Шторка — `x-deal.want-sheet bid` без своей формы. Что видно —
     рисует сервер (после отправки страница морфится, контроллер заново не подключается), дальше ведёт `extra_pick_controller`. --}}
@props(['offer', 'myBid' => null])
@php
    $picked = array_map('intval', (array) old('extra', $myBid?->extra_picks ?? []));
    $words = $picked ? \App\Offers\ExtraWant::wordsOf($picked) : '';
@endphp
<div {{ $attributes->class('flex flex-col') }} data-controller="extra-pick sheet" data-extra-pick-last-value="{{ json_encode(\App\Offers\ExtraWant::last(auth()->user())) }}">
    <button type="button" class="self-start text-sm text-accent-text" data-extra-pick-target="add" data-action="extra-pick#open sheet#open" @if ($words !== '') hidden @endif>+ Доп. запрос…</button>
    <div class="flex items-center gap-2 text-sm" data-extra-pick-target="line" @if ($words === '') hidden @endif>
        <button type="button" class="flex min-w-0 flex-1 items-center gap-1.5 text-left" data-action="extra-pick#open sheet#open"><x-ui.icon name="check" class="size-4 shrink-0 text-accent-text"/><span>Запросить: <span data-extra-pick-target="summary">{{ $words }}</span></span></button>
        <button type="button" class="shrink-0 p-1 text-ink-dim" aria-label="Не запрашивать" data-action="extra-pick#clear"><x-ui.icon name="x" class="size-4"/></button>
    </div>
    <x-deal.want-sheet :id="'bid-want-'.$offer->number" bid :picked="$picked"/>
</div>
