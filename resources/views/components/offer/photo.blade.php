{{-- Картинка оффера под srcset; без фото — заглушка. --}}
@props(['media', 'sizes' => '(min-width: 768px) 33vw, 100vw', 'eager' => false])
@if ($media)
    <img src="{{ \App\Media\MediaUrl::for($media, 'w640') }}" srcset="{{ \App\Media\MediaUrl::srcset($media) }}" sizes="{{ $sizes }}"
         alt="" @if (!$eager) loading="lazy" @endif decoding="async" {{ $attributes }}>
@else
    <div {{ $attributes->merge(['class' => 'flex size-full items-center justify-center text-ink-dim']) }}><x-ui.icon name="car" class="size-10"/></div>
@endif
