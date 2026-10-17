{{-- Кадры с вывоза (`at`: pickup — «Забрал», delivery — «Привёз»): сколько угодно, с телефона по одному с прогрессом и
     HEIC (`photos_controller`, `TransportController::upload`); лежат у машины, скрыты с витрины. edit — можно добавлять
     (исполнитель, логист, админ, пока шаг не пройден). --}}
@props(['t', 'at' => 'pickup', 'edit' => false])
@php $photos = $t->photos($at); @endphp
<section id="transport-photos-{{ $at }}" data-controller="photos" data-photos-url-value="/transports/{{ $t->id }}/media" data-photos-stage-value="{{ $at }}" @unless ($edit) data-photos-readonly-value="true" @endunless {{ $attributes->class(['mt-5']) }}>
    <h3 class="list-head">{{ $at === 'delivery' ? 'Фото при доставке' : 'Фото приёма' }}@if ($photos->isNotEmpty()) <span class="nums font-normal text-ink-dim">{{ $photos->count() }}</span>@endif</h3>
    @if ($edit)
        <input type="file" accept="image/*,.heic,.heif" multiple class="sr-only" tabindex="-1" data-photos-target="input" data-action="change->photos#upload">
        <div hidden data-photos-target="progress" class="mb-3">
            <div class="mb-1 text-sm text-ink-muted" data-label></div>
            <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
        </div>
    @endif
    @if ($edit || $photos->isNotEmpty())
        <x-ui.photos :photos="$photos" :readonly="! $edit" grid :hide="false" :main="false" :id="'transport-gallery-'.$at"/>
    @endif
</section>
