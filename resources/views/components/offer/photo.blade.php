{{-- Картинка оффера под srcset; без фото — заглушка. --}}
@props(['media', 'sizes' => '(min-width: 768px) 33vw, 100vw', 'eager' => false])
{{-- eager — первые кадры экрана: без lazy и с высоким приоритетом, чтобы список с фото появлялся раньше. --}}
@if ($media)
    <img src="{{ \App\Media\MediaUrl::for($media, 'w640') }}" srcset="{{ \App\Media\MediaUrl::srcset($media) }}" sizes="{{ $sizes }}"
         alt="" @if (!$eager) loading="lazy" @else fetchpriority="high" @endif decoding="async" {{ $attributes }}>
@else
    <x-ui.car-blank {{ $attributes }}/>
@endif
