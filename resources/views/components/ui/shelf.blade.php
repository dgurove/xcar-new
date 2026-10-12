{{-- Пачка плиток лентой вбок (11.10.2026, владелец и начальник: «горизонтальный скролл в разы удобнее вертикального»).
     Ленты — только там, где у плиток есть пачки с подзаголовком; плоский список остаётся сеткой. Телефон — пальцем, ПК —
     ещё стрелками в заголовке (`shelf_controller`). Такт кадров (`ticker`) — у страницы, не у ленты. Атрибуты — контейнеру
     плиток (id, data-*), `head` — что ещё в заголовке (галка группы CRM). --}}
@props(['title', 'count' => null, 'head' => null, 'tone' => null])
<section class="shelf" data-controller="shelf">
    <div class="shelf-head">
        {{ $head }}
        <h2 class="shelf-title">@if ($tone === 'urgent')<x-ui.icon name="fire" fill class="size-5 self-center text-fire"/>@elseif ($tone)<span class="card-tone card-tone--{{ $tone }} self-center"></span>@endif{{ $title }}@if ($count !== null && $count !== '') <span class="nums">{{ $count }}</span>@endif</h2>
        <span class="shelf-arrows" data-shelf-target="arrows" hidden>
            <button type="button" class="btn btn-quiet btn-round" data-shelf-target="prev" data-action="shelf#prev" aria-label="Назад"><x-ui.icon name="chevron-left" class="size-5"/></button>
            <button type="button" class="btn btn-quiet btn-round" data-shelf-target="next" data-action="shelf#next" aria-label="Дальше"><x-ui.icon name="chevron-right" class="size-5"/></button>
        </span>
    </div>
    <div {{ $attributes->merge(['class' => 'cards cards--grid cards-shelf']) }} data-shelf-target="track">{{ $slot }}</div>
</section>
