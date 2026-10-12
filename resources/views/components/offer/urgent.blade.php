{{-- Огонёк срочного (12.10.2026, владелец: «срочное — огоньком»): сразу после названия, как «Рекомендуем»; с label —
     «Срочно» словом. Рисуется только у `$offer->isUrgent()` — проверяет вызывающий. --}}
@props(['label' => false])
@if ($label)
<span {{ $attributes->merge(['class' => 'tag tag-urgent-fire']) }}><x-ui.icon name="fire" fill class="mr-1 size-3.5"/>Срочно</span>
@else
<span {{ $attributes->merge(['class' => 'urgent-fire']) }} role="img" aria-label="Срочно" title="Срочно"><x-ui.icon name="fire" fill class="size-[1em]"/></span>
@endif
