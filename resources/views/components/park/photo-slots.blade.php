{{-- Чек-лист кадров: плитка на слот, пустая — контур с камерой (нажатие открывает камеру именно на этот
     слот), заполненная — кадр: нажатие показывает его во весь экран, камера в углу — доснять. Счётчик обязательных — в заголовке карточки. Живёт внутри
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
            @if ($last)
                {{-- Снятый слот: нажатие — кадр во весь экран (все кадры слота подряд), камера в углу — доснять. --}}
                <div class="photo-slot is-filled">
                    @foreach ($shots->reverse()->values() as $k => $shot)
                        <img src="{{ \App\Media\MediaUrl::for($shot, 'w320') }}" alt="" data-full="{{ \App\Media\MediaUrl::for($shot) }}" data-mid="{{ \App\Media\MediaUrl::for($shot, 'w960') }}" data-id="{{ $shot->id }}" @if ($k) hidden loading="lazy" @else data-action="click->photos#open" @endif>
                    @endforeach
                    @if ($shots->count() > 1)<span class="mark nums">{{ $shots->count() }}</span>@endif
                    <button type="button" class="photo-slot-camera" data-action="photo-slot#pick" data-slot="{{ $slot->value }}" aria-label="Доснять"><x-ui.icon name="camera" class="size-4"/></button>
                    <span class="photo-slot-label">{{ $slot->label() }}</span>
                </div>
            @else
                <button type="button" class="photo-slot {{ $slot->required() ? 'is-required' : '' }}" data-action="photo-slot#pick" data-slot="{{ $slot->value }}">
                    <x-ui.icon name="camera" class="size-6"/>
                    <span class="photo-slot-label">{{ $slot->label() }}</span>
                </button>
            @endif
        @endforeach
    </div>
    <input type="file" accept="image/*,.heic,.heif" capture="environment" hidden data-photo-slot-target="input" data-action="change->photo-slot#upload">
</div>
