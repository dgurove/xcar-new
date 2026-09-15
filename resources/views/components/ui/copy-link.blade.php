{{-- Ссылка, которую отдают человеку: крупно, по нажатию выделяется целиком,
     ниже «Скопировать» и «Отправить» (системный лист, где он есть). --}}
@props(['url', 'title' => null])
<div {{ $attributes->merge(['class' => 'flex flex-col gap-3']) }} data-controller="copy" data-copy-text-value="{{ $url }}" data-copy-title-value="{{ $title }}">
    <div class="box-nested nums break-all text-[15px] leading-snug select-all" data-action="click->copy#select">{{ $url }}</div>
    <div class="flex gap-2">
        <button type="button" class="btn btn-s btn-accent flex-1" data-action="copy#copy"><x-ui.icon name="copy" class="size-5"/> Скопировать</button>
        <button type="button" class="btn btn-s btn-quiet flex-1" data-action="copy#share" data-copy-target="share"><x-ui.icon name="share" class="size-5"/> Отправить</button>
    </div>
    {{ $slot }}
</div>
