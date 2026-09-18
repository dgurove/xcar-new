{{-- Чек-лист кадров: плитка на слот, пустая — контур с камерой, заполненная — кадр; нажатие открывает
     камеру именно на этот слот. Счётчик обязательных — в заголовке карточки. Живёт внутри
     data-controller="photos" карточки: загрузка тем же контроллером, слот и стадия — полями формы. --}}
@props(['vehicle', 'stage', 'slots', 'shots' => []])
@php
    $photos = $vehicle->photos()->filter(fn ($m) => $m->getCustomProperty('stage') === $stage);
    $bySlot = $photos->groupBy(fn ($m) => (string) $m->getCustomProperty('slot'));
    $required = collect($slots)->filter->required();
    $have = $required->filter(fn ($s) => $bySlot->has($s->value))->count();
@endphp
<div class="photo-slots" data-controller="photo-slot" data-photo-slot-stage-value="{{ $stage }}" data-photo-slot-required-value="{{ $required->count() }}" data-photo-slot-have-value="{{ $have }}">
    <div class="mb-2 flex items-center gap-2 text-sm text-ink-muted"><span class="nums" data-photo-slot-target="count">{{ $have }} из {{ $required->count() }}</span></div>
    <div class="grid grid-cols-3 gap-2">
        @foreach ($slots as $slot)
            @php $shots = $bySlot->get($slot->value, collect()); $last = $shots->last(); @endphp
            <button type="button" class="photo-slot {{ $last ? 'is-filled' : '' }} {{ $slot->required() && !$last ? 'is-required' : '' }}" data-action="photo-slot#pick" data-slot="{{ $slot->value }}">
                @if ($last)
                    <img src="{{ \App\Media\MediaUrl::for($last, 'w320') }}" alt="" data-full="{{ \App\Media\MediaUrl::for($last) }}">
                    @if ($shots->count() > 1)<span class="mark nums">{{ $shots->count() }}</span>@endif
                @else
                    <x-ui.icon name="camera" class="size-6"/>
                @endif
                <span class="photo-slot-label">{{ $slot->label() }}</span>
            </button>
        @endforeach
    </div>
    <input type="file" accept="image/*,.heic,.heif" capture="environment" hidden data-photo-slot-target="input" data-action="change->photo-slot#upload">
</div>
