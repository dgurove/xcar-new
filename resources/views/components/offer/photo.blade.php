{{-- Картинка оффера под srcset; без фото — заглушка. --}}
@props(['media', 'sizes' => '(min-width: 768px) 33vw, 100vw', 'eager' => false, 'single' => false])
{{-- eager — первые кадры экрана: без lazy и с высоким приоритетом, чтобы список с фото появлялся раньше.
     single — кадр плитки за первым (до него листают): один адрес w960 вместо srcset из пяти — его и берёт телефон
     (`MediaUrl::sizes`), а каталог в двести плиток по шесть кадров был 2 МБ разметки (16.10.2026). --}}
@if ($media && $single)
    <img src="{{ \App\Media\MediaUrl::for($media, 'w960') }}" alt="" loading="lazy" decoding="async" {{ $attributes }}>
@elseif ($media)
    <img src="{{ \App\Media\MediaUrl::for($media, 'w640') }}" srcset="{{ \App\Media\MediaUrl::srcset($media) }}" sizes="{{ \App\Media\MediaUrl::sizes($sizes) }}"
         alt="" @if (!$eager) loading="lazy" @else fetchpriority="high" @endif decoding="async" {{ $attributes }}>
@else
    <x-ui.car-blank {{ $attributes }}/>
@endif
