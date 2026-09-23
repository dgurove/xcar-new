{{-- Ссылка, которую отдают человеку: крупно, по нажатию выделяется целиком,
     ниже «Скопировать» и «Отправить» (системный лист, где он есть). С :message — поле с готовым
     текстом сообщения, его можно поправить; в буфер и в лист уходят текст и ссылка вместе. --}}
@props(['url', 'title' => null, 'message' => null])
<div {{ $attributes->merge(['class' => 'flex flex-col gap-3']) }} data-controller="copy" data-copy-text-value="{{ $url }}" data-copy-title-value="{{ $title }}">
    <div class="box-nested nums break-all text-base leading-snug select-all" data-action="click->copy#select">{{ $url }}</div>
    @if ($message !== null)
        <textarea class="field-input" rows="3" aria-label="Сообщение" data-copy-target="message">{{ $message }}</textarea>
    @endif
    <div class="flex gap-2">
        <button type="button" class="btn btn-s btn-accent flex-1" data-action="copy#copy"><x-ui.icon name="copy" class="size-5"/> Скопировать</button>
        <button type="button" class="btn btn-s btn-quiet flex-1" data-action="copy#share" data-copy-target="share"><x-ui.icon name="share" class="size-5"/> Отправить</button>
    </div>
    {{ $slot }}
</div>
