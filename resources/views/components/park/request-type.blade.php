{{-- Тип заявки парковки (30.10.2026, владелец: «разные иконки красивые разного цвета»): цветная стрелка в бледном круге того
     же цвета — приём голубым к нам, выдача бордовым от нас (`RequestType::icon`, Phosphor) — и слово; word=false — только
     круг (телефон, перед названием). --}}
@props(['type', 'word' => true])
<span {{ $attributes->class(['req-type', 'req-type--'.$type->value]) }} title="{{ $type->label() }}"><span class="req-type-mark"><x-ui.icon :name="$type->icon()" fill class="size-3"/></span>@if ($word)<span>{{ $type->label() }}</span>@endif</span>
