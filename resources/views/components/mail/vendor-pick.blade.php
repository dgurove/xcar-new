{{-- Кнопка «Выбрать вендора» на месте логотипа, когда вендор письма не узнан (16.10.2026, владелец): чип с лаймовым глаголом,
     открывает шторку `x-mail.vendor-sheet` событием. pick — `App\Mail\VendorPick::of` (null — кнопки нет: нет права
     или адреса). label — «Сменить вендора» в «···» окна письма. --}}
@props(['pick', 'label' => 'Выбрать вендора'])
@if ($pick)
    <button type="button" {{ $attributes->class('chip vendor-pick') }} data-controller="emit" data-action="emit#send:stop" data-emit-event-param="vendor-pick:open"
            data-emit-url-param="{{ $pick['url'] }}" data-emit-sender-param="{{ $pick['sender'] }}" data-emit-name-param="{{ $pick['name'] }}" data-emit-domain-param="{{ $pick['domain'] }}"><x-ui.icon name="plus" class="size-3.5"/>{{ $label }}</button>
@endif
