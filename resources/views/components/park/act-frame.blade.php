{{-- Шаг окна действия (фрейм act-frame): заголовок окна — шаг и машина (шаблон data-window-title, `window_controller`),
     адрес шага — data-window-url (ошибка проверки возвращается сюда, `ActWindowBack`), тост ответа. --}}
@props(['title', 'vehicle', 'self'])
<turbo-frame id="act-frame" class="act-fill">
    <x-ui.flash-stream/>
    <template data-window-title><span class="block truncate">{{ $title }}</span><span class="block truncate text-sm font-normal text-ink-muted">{{ $vehicle->titleWithYear() }}@if ($vehicle->plate) <span class="nums">{{ $vehicle->plate }}</span>@endif</span></template>
    <div data-window-url="{{ $self }}" hidden></div>
    @if ($errors->any() && ! $errors->hasAny(['yard_id', 'spot', 'accepted_at', 'released_at', 'to', 'brand_id', 'model_id', 'without_qr_reason', 'reason', 'pass']))<x-ui.flash tone="danger" class="mb-3">{{ $errors->first() }}</x-ui.flash>@endif
    {{ $slot }}
</turbo-frame>
